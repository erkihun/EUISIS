<?php

declare(strict_types=1);

namespace App\Services\CourtMs;

use App\Enums\EmployeeStatus;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\GrievanceStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceDecision;
use App\Models\GrievanceLetter;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Discipline\Contracts\DisciplineCases;

/**
 * My Portal > My court cases > File a case (court-ms Flow 5). Employees file
 * their tribunal case here, not in court-ms: against an institution registered
 * in EUISIS, with the grievances it concerns. Each grievance travels with what
 * the employee was given on it (the letters issued to them and the issued
 * decisions) and its PDFs (those letters and the evidence they submitted).
 * Only what the employee can already see in My Grievances is sent.
 */
final class EmployeeCourtFiling
{
    /** court-ms accepts at most 20 documents of 10 MB each. */
    public const MAX_DOCUMENTS = 20;

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly EmployeeCourtPortal $portal) {}

    /** The employee's own grievances that went past the draft stage. */
    public function grievances(User $user): Builder
    {
        return Grievance::query()
            ->where(fn (Builder $q) => $q->where('submitted_by_user_id', $user->getKey())
                ->when($user->employee_id, fn (Builder $q2) => $q2->orWhere('employee_id', $user->employee_id)))
            ->where('status', '!=', GrievanceStatus::Draft->value)
            ->whereNotNull('submitted_at');
    }

    /** Institutions registered in EUISIS that may be sued: the active ones. */
    public function organizations(): Builder
    {
        return Organization::query()->where('status', OrganizationStatus::Active->value);
    }

    /** The institution the employee works for, the default respondent. */
    public function ownOrganizationId(Employee $employee): ?string
    {
        $employee->loadMissing('currentAssignment');

        return $employee->currentAssignment?->organization_id;
    }

    /** An active employee by employee number: a co-applicant the employee files with. */
    public function colleague(string $employeeNumber): ?Employee
    {
        $number = trim($employeeNumber);

        return $number === '' ? null : Employee::query()->where('employee_number', $number)->where('status', EmployeeStatus::Active->value)->first();
    }

    /** The name court-ms shows for an employee: the English name, as their court account is opened. */
    public function nameOf(Employee $employee): string
    {
        $name = trim((string) ($employee->fullNameEn() ?: implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name]))));

        return $name !== '' ? $name : (string) $employee->employee_number;
    }

    /**
     * @param  array{case_type_id: int, description: string, relief_requested?: ?string, organization_id: string, respondent_address?: ?string, filing_id: string}  $data
     * @param  list<string>  $grievanceIds  the employee's own grievances (checked by the caller)
     * @param  list<UploadedFile>  $uploads  PDFs the employee adds
     * @param  list<array{uuid: string, name: string}>  $coApplicants  other employees filing with them (checked by the caller)
     * @param  ?string  $lawyerLicense  a lawyer from the court's register, with the power of attorney
     * @param  list<string>  $disciplineIds  the employee's own decided discipline cases (checked by the caller)
     * @return array{case_id: int, case_number: string, review_status: string, status: string}
     *
     * @throws CourtMsException
     */
    public function file(Employee $employee, User $user, array $data, array $grievanceIds, array $uploads, array $coApplicants = [], ?string $lawyerLicense = null, ?UploadedFile $powerOfAttorney = null, array $disciplineIds = []): array
    {
        $organization = $this->organizations()->findOrFail($data['organization_id']);
        $grievances = $this->grievances($user)->whereKey($grievanceIds)->with('category')->orderBy('submitted_at')->get();
        $disciplines = app(DisciplineCases::class)->courtSnapshots(app(DisciplineCases::class)->ownDeliveredCaseIds((string) $employee->getKey(), $disciplineIds));

        $documents = [];
        foreach ($grievances as $grievance) {
            array_push($documents, ...$this->grievanceDocuments($grievance, $user));
        }
        foreach ($uploads as $upload) {
            $documents[] = [
                'name' => $upload->getClientOriginalName() ?: 'document.pdf',
                'contents' => (string) $upload->get(),
                'title' => pathinfo($upload->getClientOriginalName() ?: 'document.pdf', PATHINFO_FILENAME),
                'source' => 'EUISIS employee portal',
            ];
        }

        return $this->portal->fileCase($employee, [
            'filing_id' => $data['filing_id'],
            'case_type_id' => $data['case_type_id'],
            'description' => $data['description'],
            'relief_requested' => $data['relief_requested'] ?? null,
            'respondent' => array_filter([
                'organization_uuid' => (string) $organization->getKey(),
                'name' => $organization->name_am ?: $organization->name_en,
                'address' => $data['respondent_address'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'grievances' => json_encode($grievances->map(fn (Grievance $g): array => $this->snapshot($g))->values()->all(), JSON_UNESCAPED_UNICODE),
            // The discipline cases it concerns, in the grievances' form (ADR 0013 #14): the appeal record starts there.
            'disciplines' => $disciplines === [] ? null : json_encode($disciplines, JSON_UNESCAPED_UNICODE),
            'co_applicants' => $coApplicants !== [] ? $coApplicants : null,
            'lawyer_license_number' => $powerOfAttorney !== null ? $lawyerLicense : null,
        ], array_slice($documents, 0, self::MAX_DOCUMENTS), $powerOfAttorney !== null && $lawyerLicense !== null ? [
            'name' => $powerOfAttorney->getClientOriginalName() ?: 'power-of-attorney.pdf',
            'contents' => (string) $powerOfAttorney->get(),
        ] : null);
    }

    /**
     * What court-ms keeps of a grievance: its facts, the letters issued to the
     * employee (as responses) and the issued decisions, as plain text.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Grievance $grievance): array
    {
        return [
            'uuid' => (string) $grievance->getKey(),
            'reference' => $grievance->reference_number,
            'subject' => (string) $grievance->subject,
            'category' => $grievance->category ? ($grievance->category->name_am ?: $grievance->category->name_en) : null,
            'status' => $grievance->status->value,
            'description' => $this->plain((string) $grievance->description),
            'submitted_at' => $grievance->submitted_at?->toIso8601String(),
            'closed_at' => $grievance->closed_at?->toIso8601String(),
            'responses' => $this->issuedLetters($grievance)->get()->map(fn (GrievanceLetter $letter): array => [
                'body' => trim(implode("\n\n", array_filter([(string) $letter->subject, $this->plain((string) $letter->body)]))),
                'at' => $letter->issued_at?->toDateString(),
            ])->values()->all(),
            'decisions' => $grievance->decisions()->where('status', GrievanceDecisionStatus::Issued->value)->orderBy('issued_at')->get()
                ->map(fn (GrievanceDecision $decision): array => [
                    'number' => $decision->decision_no,
                    'type' => $decision->decision_type ? __('grievances.enums.decision_type.'.$decision->decision_type->value) : null,
                    'text' => $this->plain((string) $decision->decision_text),
                    'at' => $decision->issued_at?->toDateString(),
                ])->values()->all(),
        ];
    }

    /** How many PDFs a grievance would bring (the page shows it). */
    public function documentCount(Grievance $grievance, User $user): int
    {
        return $this->issuedLetters($grievance)->whereNotNull('pdf_path')->count()
            + $this->ownEvidence($grievance, $user)->count();
    }

    /** @return list<array{name: string, contents: string, title: string, source: string}> */
    private function grievanceDocuments(Grievance $grievance, User $user): array
    {
        $documents = [];
        $reference = (string) ($grievance->reference_number ?? $grievance->getKey());

        foreach ($this->issuedLetters($grievance)->whereNotNull('pdf_path')->get() as $letter) {
            $contents = $this->read($letter->pdf_disk ?? 'local', (string) $letter->pdf_path);
            if ($contents !== null) {
                $documents[] = [
                    'name' => preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($letter->reference_number ?? 'letter')).'.pdf',
                    'contents' => $contents,
                    'title' => (string) ($letter->subject ?: $letter->reference_number ?: 'Letter'),
                    'source' => "EUISIS grievance {$reference}",
                ];
            }
        }

        foreach ($this->ownEvidence($grievance, $user)->get() as $evidence) {
            $contents = $this->read((string) $evidence->disk, (string) $evidence->path);
            if ($contents !== null) {
                $documents[] = [
                    'name' => $evidence->original_name ?: 'evidence.pdf',
                    'contents' => $contents,
                    'title' => (string) ($evidence->title ?: $evidence->original_name ?: 'Evidence'),
                    'source' => "EUISIS grievance {$reference}",
                ];
            }
        }

        return $documents;
    }

    /** Letters issued to the employee on the grievance, as My Grievances lists them. */
    private function issuedLetters(Grievance $grievance)
    {
        return $grievance->letters()->where('status', GrievanceLetterStatus::Issued->value)->where('visible_to_complainant', true)->orderBy('issued_at');
    }

    /** PDF evidence the employee submitted, never a file the scanner flagged. */
    private function ownEvidence(Grievance $grievance, User $user)
    {
        return $grievance->evidence()
            ->where(fn ($q) => $q->where('submitted_by_complainant', true)->orWhere('submitted_by', $user->getKey()))
            ->where('mime_type', 'application/pdf')
            ->where(fn ($q) => $q->whereNull('scan_status')->orWhere('scan_status', '!=', 'infected'))
            ->where(fn ($q) => $q->whereNull('size_bytes')->orWhere('size_bytes', '<=', self::MAX_DOCUMENT_BYTES))
            ->orderBy('submitted_at');
    }

    private function read(string $disk, string $path): ?string
    {
        try {
            $storage = Storage::disk($disk);
            if ($path === '' || ! $storage->exists($path) || $storage->size($path) > self::MAX_DOCUMENT_BYTES) {
                return null;
            }
            $contents = $storage->get($path);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return is_string($contents) && str_starts_with($contents, '%PDF') ? $contents : null;
    }

    /** Rich text as plain text with its paragraphs kept. */
    private function plain(string $html): string
    {
        $text = preg_replace(['~<\s*br\s*/?>~i', '~</\s*(p|div|li|h[1-6]|tr)\s*>~i'], ["\n", "\n\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
