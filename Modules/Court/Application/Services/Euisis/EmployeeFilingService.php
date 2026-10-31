<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Euisis;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Services\SecureUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flow 5 (docs/euisis-integration.md): a case an employee files from the EUISIS
 * employee portal. The employee is the applicant (their court account is linked,
 * or opened, as in Flow 4); the respondent is the EUISIS institution they chose;
 * the grievances they attach travel with the case (their responses and decisions
 * in case_euisis_grievances, their PDFs as case documents). Other employees named
 * as co-applicants are invited (they confirm in the EUISIS employee portal); a
 * lawyer from the court's register represents the applicant once the registry
 * accepts the power of attorney. The case starts in intake review like any
 * filing. Idempotent on the EUISIS filing id.
 */
final class EmployeeFilingService
{
    public function __construct(
        private readonly EmployeeCourtAccess $access,
        private readonly CaseNumbers $numbers,
        private readonly CaseDocuments $documents,
        private readonly CaseParties $parties,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @param  list<array{file: UploadedFile, title: string, source: string}>  $documents
     * @return array{0: object, 1: bool} the case row, and whether this call created it
     *
     * @throws EmployeeCourtAccessException
     */
    public function file(array $data, array $documents, ?UploadedFile $powerOfAttorney = null): array
    {
        $existing = DB::table('court_cases')->where('euisis_filing_id', $data['filing_id'])->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        $applicant = $this->access->findOrOpen($data['employee']);
        if (! $applicant->is_active) {
            throw new EmployeeCourtAccessException('account_inactive', 'The court account is deactivated.');
        }

        $caseId = DB::transaction(function () use ($data, $documents, $applicant, $powerOfAttorney): int {
            $caseId = DB::table('court_cases')->insertGetId([
                'applicant_id' => $applicant->id,
                'case_number' => $this->numbers->next((int) $data['case_type_id']),
                'code' => $this->numbers->code(),
                // court_cases.title is the applicant's name (the applicant form fills it the same way).
                'title' => preg_replace('/ {2,}/', ' ', $applicant->full_name),
                'respondent_name' => trim(strip_tags((string) $data['respondent']['name'])),
                'respondent_address' => isset($data['respondent']['address']) ? trim(strip_tags((string) $data['respondent']['address'])) : null,
                'respondent_organization_uuid' => $data['respondent']['organization_uuid'],
                'description' => $this->paragraphs((string) $data['description']),
                'relief_requested' => filled($data['relief_requested'] ?? null) ? $this->paragraphs((string) $data['relief_requested']) : null,
                'case_type_id' => (int) $data['case_type_id'],
                'filing_date' => now()->toDateString(),
                'status' => 'pending',
                'review_status' => 'awaiting_review',
                'source' => 'euisis_employee',
                'euisis_filing_id' => $data['filing_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('court_case_status_logs')->insert([
                'case_id' => $caseId, 'from_status' => null, 'to_status' => 'pending', 'changed_by_user_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // The EUISIS proceedings the case started from: grievances and discipline cases, in one form.
            $proceedings = [
                ...array_map(fn (array $row): array => [...$row, 'kind' => 'grievance'], (array) ($data['grievances'] ?? [])),
                ...array_map(fn (array $row): array => [...$row, 'kind' => 'discipline'], (array) ($data['disciplines'] ?? [])),
            ];
            foreach ($proceedings as $grievance) {
                DB::table('court_case_euisis_grievances')->insert([
                    'court_case_id' => $caseId,
                    'kind' => $grievance['kind'],
                    'grievance_uuid' => $grievance['uuid'],
                    'reference' => $grievance['reference'] ?? null,
                    'subject' => mb_substr(strip_tags((string) $grievance['subject']), 0, 500),
                    'category' => isset($grievance['category']) ? mb_substr(strip_tags((string) $grievance['category']), 0, 255) : null,
                    'status' => $grievance['status'] ?? null,
                    'description' => isset($grievance['description']) ? strip_tags((string) $grievance['description']) : null,
                    'submitted_at' => $this->time($grievance['submitted_at'] ?? null),
                    'closed_at' => $this->time($grievance['closed_at'] ?? null),
                    'responses' => json_encode($this->plain((array) ($grievance['responses'] ?? []))),
                    'decisions' => json_encode($this->plain((array) ($grievance['decisions'] ?? []))),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($documents as $document) {
                $this->documents->store($caseId, $document['file'], $document['title'], $document['source']);
            }

            $lead = $this->parties->addLead($caseId, (int) $applicant->id, $applicant->euisis_employee_uuid,
                (string) preg_replace('/ {2,}/', ' ', $applicant->full_name), 'filing');
            foreach ((array) ($data['co_applicants'] ?? []) as $coApplicant) {
                $this->parties->invite($caseId, (string) $coApplicant['uuid'], trim(strip_tags((string) $coApplicant['name'])), (int) $applicant->id);
            }
            if (filled($data['lawyer_license_number'] ?? null) && $powerOfAttorney !== null) {
                $lawyer = Applicant::query()->where('is_lawyer', true)->where('lawyer_license_number', trim((string) $data['lawyer_license_number']))->firstOrFail();
                $path = app(SecureUploadService::class)->store($powerOfAttorney, 'powers-of-attorney', 'court_private', ['related_type' => 'court_case', 'related_id' => $caseId]);
                $this->parties->addRepresentative($lead, $lawyer, $path, confirmedByParty: true);
            }

            return $caseId;
        });

        return [DB::table('court_cases')->where('id', $caseId)->first(), true];
    }

    /** Plain text as the escaped paragraphs the case views render (they show sanitized HTML). */
    private function paragraphs(string $text): string
    {
        $parts = preg_split('/\R{2,}/', trim($text)) ?: [];

        return implode('', array_map(fn (string $p): string => '<p>'.nl2br(e(trim($p)), false).'</p>', array_filter($parts, fn ($p) => trim($p) !== '')));
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Responses and decisions as sent, with every value as plain text.
     *
     * @param  array<int, mixed>  $items
     * @return list<array<string, string|null>>
     */
    private function plain(array $items): array
    {
        return array_values(array_map(
            fn ($item): array => collect(is_array($item) ? $item : [])
                ->map(fn ($value): ?string => is_scalar($value) ? mb_substr(strip_tags((string) $value), 0, 20000) : null)
                ->all(),
            array_slice($items, 0, 50),
        ));
    }
}
