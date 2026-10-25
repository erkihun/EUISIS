<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The `evidence` prop of the employee page (ADR 0009): each section only for a
 * reader allowed to see it (null otherwise, so no empty privileged section is
 * drawn), every version with its own actions, and what approval and a card
 * still need. File paths never leave the server; files are read through the
 * authorized download URL.
 */
final readonly class EvidenceView
{
    public function __construct(private EmployeeEvidence $evidence, private CoreSettings $settings) {}

    /** @return array<string, mixed> */
    public function for(Employee $employee, User $user): array
    {
        $visible = array_values(array_filter(EmployeeDocument::KINDS, fn (string $kind): bool => Gate::forUser($user)->allows('viewAny', [EmployeeDocument::class, $employee, $kind])));
        $rows = $visible === [] ? collect() : EmployeeDocument::query()->where('employee_id', $employee->getKey())->whereIn('kind', $visible)
            ->orderBy('kind')->orderBy('document_type')->orderByDesc('version')->get();
        $names = User::query()->whereIn('id', $rows->pluck('uploaded_by')->merge($rows->pluck('verified_by'))->merge($rows->pluck('archived_by'))->filter()->unique())->pluck('name', 'id');
        $section = function (string $kind) use ($employee, $user, $rows, $names, $visible): ?array {
            if (! in_array($kind, $visible, true)) {
                return null;
            }
            $lineages = $rows->where('kind', $kind)->groupBy('lineage_id')->map(fn (Collection $versions): array => [
                'current' => ($current = $versions->firstWhere('is_current', true)) === null ? null : $this->version($employee, $current, $user, $names),
                'history' => $versions->reject(fn (EmployeeDocument $row): bool => $row->is_current)->map(fn (EmployeeDocument $row): array => $this->version($employee, $row, $user, $names))->values()->all(),
            ])->values();

            return [
                'items' => $lineages->sortBy(fn (array $lineage): string => ($lineage['current'] === null ? '1' : '0').($lineage['current']['document_type'] ?? ''))->values()->all(),
                'can_upload' => Gate::forUser($user)->allows('upload', [EmployeeDocument::class, $employee, $kind]),
                'upload_url' => route('employees.documents.store', $employee),
            ];
        };

        return [
            'documents' => $section(EmployeeDocument::DOCUMENT),
            'photo' => $section(EmployeeDocument::PHOTO),
            'signature' => $section(EmployeeDocument::SIGNATURE),
            'document_types' => EmployeeDocument::DOCUMENT_TYPES,
            'missing' => $visible === [] ? null : [
                'approval' => array_keys($this->evidence->failures($employee, $this->evidence->forApproval())),
                'cards' => array_keys($this->evidence->failures($employee, $this->evidence->forCards())),
            ],
            'limits' => [
                'document_max_kb' => $this->settings->int('files.document_max_kb'),
                'photo_max_kb' => $this->settings->int('files.photo_max_kb'),
                'signature_max_kb' => $this->settings->int('files.signature_max_kb'),
                'photo_min_pixels' => $this->settings->int('files.photo_min_pixels'),
            ],
        ];
    }

    /** The current photo for the page header, when the reader may see photos. */
    public function photoUrl(Employee $employee, User $user): ?string
    {
        $photo = EmployeeDocument::query()->where('employee_id', $employee->getKey())->where('kind', EmployeeDocument::PHOTO)->where('is_current', true)->first();

        return $photo !== null && Gate::forUser($user)->allows('download', $photo)
            ? route('employees.documents.file', [$employee, $photo, 'inline' => 1])
            : null;
    }

    /**
     * @param  Collection<int|string, string>  $names
     * @return array<string, mixed>
     */
    private function version(Employee $employee, EmployeeDocument $row, User $user, Collection $names): array
    {
        $gate = Gate::forUser($user);
        $canRead = $gate->allows('download', $row);
        $expired = $row->expires_at !== null && $row->expires_at->lt(today());

        return [
            'id' => $row->id,
            'lineage_id' => $row->lineage_id,
            'kind' => $row->kind,
            'document_type' => $row->document_type,
            'title' => $row->title,
            'version' => $row->version,
            'status' => $expired && $row->status === EmployeeDocument::VERIFIED ? EmployeeDocument::EXPIRED : $row->status,
            'is_current' => $row->is_current,
            'original_filename' => $row->original_filename,
            'mime_type' => $row->mime_type,
            'file_size' => $row->file_size,
            'width' => $row->width,
            'height' => $row->height,
            'issued_at' => $row->issued_at?->toDateString(),
            'expires_at' => $row->expires_at?->toDateString(),
            'uploaded_by' => $names->get($row->uploaded_by),
            'uploaded_at' => $row->created_at?->toIso8601String(),
            'verified_by' => $names->get($row->verified_by),
            'verified_at' => $row->verified_at?->toIso8601String(),
            'verification_notes' => $row->verification_notes,
            'archived_by' => $names->get($row->archived_by),
            'archive_reason' => $row->archive_reason,
            'can' => [
                'download' => $canRead,
                'replace' => $gate->allows('replace', $row),
                'verify' => $gate->allows('verify', $row),
                'archive' => $gate->allows('archive', $row),
                'discard' => $gate->allows('archive', $row) && $row->status === EmployeeDocument::PENDING && ! $employee->isApproved(),
            ],
            'urls' => [
                'file' => $canRead ? route('employees.documents.file', [$employee, $row]) : null,
                'replace' => route('employees.documents.replace', [$employee, $row]),
                'verify' => route('employees.documents.decide', [$employee, $row, 'verify']),
                'reject' => route('employees.documents.decide', [$employee, $row, 'reject']),
                'archive' => route('employees.documents.archive', [$employee, $row]),
                'discard' => route('employees.documents.discard', [$employee, $row]),
            ],
        ];
    }
}
