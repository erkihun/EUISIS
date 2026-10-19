<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\LegalSourceStatus;
use App\Models\LegalSource;
use App\Models\LegalSourceAttachment;
use App\Models\LegalSourceCitation;
use App\Models\User;
use App\Support\Storage\StoredFileChecksum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Legal sources, their documents and their citations (ADR-LB-001).
 *
 * A source is evidence: it is recorded once, documents are added to it, and
 * design records cite it. It is never deleted while anything cites it; an
 * instrument that stops applying is marked superseded or repealed so the
 * history of what rested on it stays readable.
 */
class LegalSourceService
{
    public const DISK = 'local';

    /** Documents only; spreadsheets carry structures, images carry scans. No executables, no macros. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    public const MAX_ATTACHMENT_KB = 20480;

    public const FIELDS = [
        'code', 'title_en', 'title_am', 'source_type_id', 'issuing_authority', 'document_number',
        'issue_date', 'effective_from', 'effective_to', 'description_en', 'description_am', 'source_url',
    ];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, ?string $organizationId, array $data): LegalSource
    {
        return DB::transaction(function () use ($actor, $organizationId, $data): LegalSource {
            $source = new LegalSource([
                ...$this->only($data),
                'organization_id' => $organizationId,
                'status' => LegalSourceStatus::Active,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $source->save();
            $this->audit->record(AuditEventType::LegalSourceCreated, $actor, $source, $source->only(self::FIELDS));

            return $source;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, LegalSource $source, array $data): LegalSource
    {
        return DB::transaction(function () use ($actor, $source, $data): LegalSource {
            $old = $source->only(self::FIELDS);
            $source->fill([...$this->only($data), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::LegalSourceUpdated, $actor, $source, $source->only(self::FIELDS), $old);

            return $source;
        });
    }

    /** Active → superseded | repealed, or back to active. The row and its citations stay. */
    public function changeStatus(User $actor, LegalSource $source, LegalSourceStatus $status, ?string $reason): LegalSource
    {
        if ($source->status === $status) {
            return $source;
        }

        return DB::transaction(function () use ($actor, $source, $status, $reason): LegalSource {
            $from = $source->status->value;
            $source->forceFill(['status' => $status, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::LegalSourceStatusChanged, $actor, $source, ['status' => $status->value], ['status' => $from], $reason);
            $this->audit->transition($actor, $source, $from, $status->value, $reason);

            return $source;
        });
    }

    /** Soft-deletes a source nothing cites and that holds no document. */
    public function delete(User $actor, LegalSource $source): void
    {
        if ($source->citations()->exists()) {
            throw ValidationException::withMessages(['source' => __('organization-design.legal_source_cited_cannot_delete')]);
        }

        DB::transaction(function () use ($actor, $source): void {
            foreach ($source->attachments()->get() as $attachment) {
                $this->removeAttachment($actor, $attachment);
            }
            $source->forceFill(['updated_by' => $actor->getKey()])->save();
            $source->delete();
            $this->audit->record(AuditEventType::LegalSourceDeleted, $actor, $source, ['code' => $source->code]);
        });
    }

    public function addAttachment(User $actor, LegalSource $source, UploadedFile $file, ?string $label): LegalSourceAttachment
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => __('organization-design.legal_source_file_type')]);
        }

        $path = $file->storeAs('organization-design/legal-sources/'.$source->getKey(), Str::uuid7().'.'.$extension, self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('organization-design.legal_source_upload_failed')]);
        }

        try {
            return DB::transaction(function () use ($actor, $source, $file, $label, $path): LegalSourceAttachment {
                $attachment = LegalSourceAttachment::query()->create([
                    'legal_source_id' => $source->getKey(),
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $this->safeName($file->getClientOriginalName()),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'label' => $label,
                    'uploaded_by' => $actor->getKey(),
                    'created_at' => now(),
                ]);
                // Metadata only: never the content.
                $this->audit->record(AuditEventType::LegalSourceAttachmentAdded, $actor, $source, ['attachment_id' => $attachment->getKey(), 'mime' => $attachment->mime_type, 'size' => $attachment->size_bytes, 'sha256' => $attachment->sha256]);

                return $attachment;
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }
    }

    public function removeAttachment(User $actor, LegalSourceAttachment $attachment): void
    {
        DB::transaction(function () use ($actor, $attachment): void {
            $source = $attachment->source;
            $attachment->delete();
            Storage::disk($attachment->disk)->delete($attachment->path);
            $this->audit->record(AuditEventType::LegalSourceAttachmentRemoved, $actor, $source, ['attachment_id' => $attachment->getKey()]);
        });
    }

    /** Streams the document from its disk after an integrity check; the path never leaves the server. */
    public function download(User $actor, LegalSourceAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);
        if (! StoredFileChecksum::matches($attachment->disk, $attachment->path, $attachment->sha256)) {
            report(new \RuntimeException('Legal source attachment integrity check failed: '.$attachment->getKey()));
            abort(409, __('organization-design.legal_source_integrity_failed'));
        }

        $this->audit->record(AuditEventType::LegalSourceAttachmentDownloaded, $actor, $attachment->source, ['attachment_id' => $attachment->getKey()]);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Records that `$subject` rests on `$source`. A source may be cited by a
     * record of any organization only when it is city-level or belongs to
     * that record's organization.
     */
    public function cite(User $actor, LegalSource $source, Model $subject, ?string $note = null): LegalSourceCitation
    {
        $subjectOrganization = $subject->getAttribute('organization_id');
        if (! $source->isCityLevel() && is_string($subjectOrganization) && $subjectOrganization !== $source->organization_id) {
            throw ValidationException::withMessages(['legal_source_id' => __('organization-design.legal_source_other_organization')]);
        }

        return DB::transaction(function () use ($actor, $source, $subject, $note): LegalSourceCitation {
            $citation = LegalSourceCitation::query()->firstOrCreate(
                ['legal_source_id' => $source->getKey(), 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
                ['note' => $note, 'created_by' => $actor->getKey(), 'created_at' => now()],
            );
            if ($citation->wasRecentlyCreated) {
                $this->audit->record(AuditEventType::LegalSourceCited, $actor, $subject, ['legal_source_id' => $source->getKey(), 'code' => $source->code]);
            }

            return $citation;
        });
    }

    public function uncite(User $actor, LegalSourceCitation $citation): void
    {
        DB::transaction(function () use ($actor, $citation): void {
            $subject = $citation->subject;
            $citation->delete();
            if ($subject instanceof Model) {
                $this->audit->record(AuditEventType::LegalSourceCitationRemoved, $actor, $subject, ['legal_source_id' => $citation->legal_source_id]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private function safeName(string $name): string
    {
        $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'document';

        return Str::limit(trim($base) === '' ? 'document' : $base, 180, '');
    }
}
