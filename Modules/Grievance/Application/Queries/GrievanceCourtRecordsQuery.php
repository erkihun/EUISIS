<?php

declare(strict_types=1);

namespace Modules\Grievance\Application\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Modules\Grievance\Contracts\Data\GrievanceStatus;
use Modules\Grievance\Contracts\GrievanceCourtRecords;
use Modules\Grievance\Domain\Enums\GrievanceDecisionStatus;
use Modules\Grievance\Domain\Enums\GrievanceLetterStatus;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceDecision;
use Modules\Grievance\Domain\Models\GrievanceLetter;

/** An employee's own grievances for a court case (moved here from the court-ms filing service). */
final class GrievanceCourtRecordsQuery implements GrievanceCourtRecords
{
    public function filedBy(User $user, int $maxBytes, int $limit = 50): array
    {
        return $this->own($user)->latest('submitted_at')->limit($limit)->get()
            ->map(fn (Grievance $g): array => [
                'id' => (string) $g->getKey(),
                'reference_number' => $g->reference_number,
                'subject' => $g->subject,
                'status' => $g->status->value,
                'submitted_at' => $g->submitted_at?->toIso8601String(),
                'documents' => $this->documentCount($g, $user, $maxBytes),
            ])->values()->all();
    }

    public function ownIds(User $user, array $grievanceIds): array
    {
        return $grievanceIds === [] ? [] : $this->own($user)->whereKey($grievanceIds)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    public function courtFile(User $user, array $grievanceIds, int $maxBytes): array
    {
        $grievances = $grievanceIds === [] ? collect() : $this->own($user)->whereKey($grievanceIds)->with('category')->orderBy('submitted_at')->get();
        $documents = [];
        foreach ($grievances as $grievance) {
            array_push($documents, ...$this->grievanceDocuments($grievance, $user, $maxBytes));
        }

        return ['snapshots' => $grievances->map(fn (Grievance $g): array => $this->snapshot($g))->values()->all(), 'documents' => $documents];
    }

    /** The employee's own grievances that went past the draft stage. */
    private function own(User $user): Builder
    {
        return Grievance::query()
            ->where(fn (Builder $q) => $q->where('submitted_by_user_id', $user->getKey())
                ->when($user->employee_id, fn (Builder $q2) => $q2->orWhere('employee_id', $user->employee_id)))
            ->where('status', '!=', GrievanceStatus::Draft->value)
            ->whereNotNull('submitted_at');
    }

    /**
     * What court-ms keeps of a grievance: its facts, the letters issued to the
     * employee (as responses) and the issued decisions, as plain text.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Grievance $grievance): array
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
    private function documentCount(Grievance $grievance, User $user, int $maxBytes): int
    {
        return $this->issuedLetters($grievance)->whereNotNull('pdf_path')->count()
            + $this->ownEvidence($grievance, $user, $maxBytes)->count();
    }

    /** @return list<array{name: string, contents: string, title: string, source: string}> */
    private function grievanceDocuments(Grievance $grievance, User $user, int $maxBytes): array
    {
        $documents = [];
        $reference = (string) ($grievance->reference_number ?? $grievance->getKey());

        foreach ($this->issuedLetters($grievance)->whereNotNull('pdf_path')->get() as $letter) {
            $contents = $this->read($letter->pdf_disk ?? 'local', (string) $letter->pdf_path, $maxBytes);
            if ($contents !== null) {
                $documents[] = [
                    'name' => preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($letter->reference_number ?? 'letter')).'.pdf',
                    'contents' => $contents,
                    'title' => (string) ($letter->subject ?: $letter->reference_number ?: 'Letter'),
                    'source' => "EUISIS grievance {$reference}",
                ];
            }
        }

        foreach ($this->ownEvidence($grievance, $user, $maxBytes)->get() as $evidence) {
            $contents = $this->read((string) $evidence->disk, (string) $evidence->path, $maxBytes);
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
    private function ownEvidence(Grievance $grievance, User $user, int $maxBytes)
    {
        return $grievance->evidence()
            ->where(fn ($q) => $q->where('submitted_by_complainant', true)->orWhere('submitted_by', $user->getKey()))
            ->where('mime_type', 'application/pdf')
            ->where(fn ($q) => $q->whereNull('scan_status')->orWhere('scan_status', '!=', 'infected'))
            ->where(fn ($q) => $q->whereNull('size_bytes')->orWhere('size_bytes', '<=', $maxBytes))
            ->orderBy('submitted_at');
    }

    private function read(string $disk, string $path, int $maxBytes): ?string
    {
        try {
            $storage = Storage::disk($disk);
            if ($path === '' || ! $storage->exists($path) || $storage->size($path) > $maxBytes) {
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
