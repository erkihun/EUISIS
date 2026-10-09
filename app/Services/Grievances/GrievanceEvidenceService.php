<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceCustodyAction;
use App\Enums\Grievance\GrievanceEvidenceStatus;
use App\Enums\Grievance\GrievanceEvidenceType;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceEvidence;
use App\Models\GrievanceEvidenceCustody;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence on private storage (docs/grievance-management.md §8.6).
 *
 *  - Allowed extensions come from settings; the MIME type detected from the
 *    file CONTENT must also belong to that extension's family.
 *  - Files are stored under a random name on the private `local` disk; there
 *    is never a public URL. Every download is authorized at request time.
 *  - SHA-256 is recorded at upload and re-checked at download (detects
 *    accidental replacement; it is not a digital signature).
 *  - Accepted evidence is never replaced in place: a new version supersedes it.
 *  - A chain-of-custody row is kept for upload, acceptance, classification,
 *    view and download. No antivirus is installed on this platform, so
 *    scan_status stays "not_scanned" until one is integrated.
 */
final class GrievanceEvidenceService
{
    public const DISK = 'local';

    /** Extension → acceptable detected MIME types. */
    private const MIME_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'mp3' => ['audio/mpeg', 'audio/mp3'], 'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'], 'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        'mp4' => ['video/mp4'], 'eml' => ['message/rfc822', 'text/plain'],
    ];

    /** Evidence type → extension families it may carry. */
    private const TYPE_EXTENSIONS = [
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'],
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'audio' => ['mp3', 'm4a', 'wav'],
        'video' => ['mp4'],
        'email' => ['eml', 'txt', 'pdf'],
        'letter' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
        'report' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'minutes' => ['pdf', 'doc', 'docx'],
        'other' => null,
    ];

    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceSettings $settings,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
    ) {}

    /**
     * @param  array{evidence_type: string, title: string, description?: string|null, classification?: string|null, information_request_id?: string|null, information_response_id?: string|null, appeal_id?: string|null}  $meta
     */
    public function upload(Grievance $grievance, UploadedFile $file, array $meta, User $actor, bool $asComplainant = false): GrievanceEvidence
    {
        if ($asComplainant) {
            $this->access->authorize($this->access->isComplainant($actor, $grievance) && ($actor->can('grievances.update_draft') || $actor->can('grievances.submit')));
            if ($grievance->isFinal()) {
                throw ValidationException::withMessages(['file' => __('grievances.errors.case_closed')]);
            }
        } else {
            $this->access->authorize($this->access->canReview($actor, $grievance));
        }

        $type = GrievanceEvidenceType::from($meta['evidence_type']);
        $extension = $this->validateFile($file, $type);
        $classification = $asComplainant
            ? $grievance->confidentiality_level
            : (GrievanceConfidentiality::tryFrom((string) ($meta['classification'] ?? '')) ?? $grievance->confidentiality_level);

        $path = $file->storeAs('grievances/'.$grievance->getKey().'/evidence', Str::uuid7().'.'.$extension, self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('grievances.errors.upload_failed')]);
        }

        try {
            return DB::transaction(function () use ($grievance, $file, $meta, $actor, $asComplainant, $type, $classification, $path): GrievanceEvidence {
                $evidence = GrievanceEvidence::query()->create([
                    'grievance_id' => $grievance->getKey(),
                    'case_stage_id' => $grievance->current_stage_id,
                    'information_request_id' => $meta['information_request_id'] ?? null,
                    'information_response_id' => $meta['information_response_id'] ?? null,
                    'appeal_id' => $meta['appeal_id'] ?? null,
                    'evidence_type' => $type,
                    'title' => $meta['title'],
                    'description' => $meta['description'] ?? null,
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $this->safeName($file->getClientOriginalName()),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'classification' => $classification,
                    'status' => GrievanceEvidenceStatus::Submitted,
                    'scan_status' => 'not_scanned',
                    'submitted_by_complainant' => $asComplainant,
                    'submitted_by' => $actor->getKey(),
                    'submitted_by_employee_id' => $actor->employee_id,
                    'submitted_at' => now(),
                ]);
                $this->custody($evidence, GrievanceCustodyAction::Uploaded, $actor);
                // Metadata only: never the file content or its title.
                $this->audit->record(AuditEventType::GrievanceEvidenceUploaded, $actor, $evidence, ['evidence_id' => $evidence->getKey(), 'type' => $type->value, 'mime' => $evidence->mime_type, 'size' => $evidence->size_bytes, 'sha256' => $evidence->sha256]);
                if ($grievance->status !== GrievanceStatus::Draft) {
                    $this->timeline->record($grievance, 'evidence_uploaded', $actor, ['evidence_id' => $evidence->getKey(), 'by_complainant' => $asComplainant]);
                }

                return $evidence;
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }
    }

    public function accept(GrievanceEvidence $evidence, User $actor): GrievanceEvidence
    {
        return $this->decide($evidence, $actor, true, null);
    }

    public function reject(GrievanceEvidence $evidence, User $actor, string $reason): GrievanceEvidence
    {
        return $this->decide($evidence, $actor, false, $reason);
    }

    /** New version of accepted evidence; the old artifact stays, marked superseded. */
    public function supersede(GrievanceEvidence $old, UploadedFile $file, User $actor, ?string $description): GrievanceEvidence
    {
        $grievance = $old->grievance;
        $this->access->authorize($grievance !== null && $this->access->canReview($actor, $grievance));

        $new = $this->upload($grievance, $file, [
            'evidence_type' => $old->evidence_type->value,
            'title' => $old->title,
            'description' => $description ?? $old->description,
            'classification' => $old->classification->value,
        ], $actor);

        DB::transaction(function () use ($old, $new, $actor): void {
            $new->forceFill(['version_no' => $old->version_no + 1, 'supersedes_evidence_id' => $old->getKey()])->save();
            $old->forceFill(['status' => GrievanceEvidenceStatus::Superseded])->save();
            $this->custody($old, GrievanceCustodyAction::Superseded, $actor, 'by '.$new->getKey());
        });

        return $new;
    }

    public function classify(GrievanceEvidence $evidence, User $actor, GrievanceConfidentiality $level): GrievanceEvidence
    {
        $grievance = $evidence->grievance;
        $this->access->authorize($grievance !== null && $this->access->canReview($actor, $grievance));
        $old = $evidence->classification->value;
        $evidence->forceFill(['classification' => $level])->save();
        $this->custody($evidence, GrievanceCustodyAction::Classified, $actor, "{$old} → {$level->value}");
        $this->audit->record(AuditEventType::GrievanceEvidenceClassified, $actor, $evidence, ['classification' => $level->value], ['classification' => $old]);

        return $evidence;
    }

    /** Authorized, audited, integrity-checked download with no-cache headers. */
    public function download(GrievanceEvidence $evidence, User $actor): StreamedResponse
    {
        $this->access->authorize($this->access->canViewEvidence($actor, $evidence));

        $disk = Storage::disk($evidence->disk);
        abort_unless($disk->exists($evidence->path), 404);
        if (hash_file('sha256', $disk->path($evidence->path)) !== $evidence->sha256) {
            report(new \RuntimeException('Grievance evidence integrity check failed: '.$evidence->getKey()));
            abort(409, __('grievances.errors.integrity_failed'));
        }

        $this->custody($evidence, GrievanceCustodyAction::Downloaded, $actor);
        $this->audit->record(AuditEventType::GrievanceEvidenceDownloaded, $actor, $evidence, ['evidence_id' => $evidence->getKey()]);

        return $disk->download($evidence->path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function custody(GrievanceEvidence $evidence, GrievanceCustodyAction $action, ?User $actor, ?string $notes = null): void
    {
        GrievanceEvidenceCustody::query()->create([
            'evidence_id' => $evidence->getKey(),
            'action' => $action,
            'actor_user_id' => $actor?->getKey(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    /** @return list<string> extensions acceptable for a type under current settings */
    public function allowedExtensions(GrievanceEvidenceType $type): array
    {
        $configured = $this->settings->evidenceAllowedExtensions();
        $forType = self::TYPE_EXTENSIONS[$type->value] ?? null;

        return array_values(array_filter($configured, fn (string $ext) => isset(self::MIME_BY_EXTENSION[$ext]) && ($forType === null || in_array($ext, $forType, true))));
    }

    private function decide(GrievanceEvidence $evidence, User $actor, bool $accept, ?string $reason): GrievanceEvidence
    {
        $grievance = $evidence->grievance;
        $this->access->authorize($grievance !== null && $this->access->canReview($actor, $grievance));

        return DB::transaction(function () use ($evidence, $actor, $accept, $reason): GrievanceEvidence {
            $evidence = GrievanceEvidence::query()->whereKey($evidence->getKey())->lockForUpdate()->firstOrFail();
            if ($evidence->status !== GrievanceEvidenceStatus::Submitted) {
                throw ValidationException::withMessages(['evidence' => __('grievances.errors.stale')]);
            }
            $evidence->forceFill($accept
                ? ['status' => GrievanceEvidenceStatus::Accepted, 'accepted_by' => $actor->getKey(), 'accepted_at' => now()]
                : ['status' => GrievanceEvidenceStatus::Rejected, 'rejection_reason' => $reason])->save();
            $this->custody($evidence, $accept ? GrievanceCustodyAction::Accepted : GrievanceCustodyAction::Rejected, $actor);
            $this->audit->record($accept ? AuditEventType::GrievanceEvidenceAccepted : AuditEventType::GrievanceEvidenceRejected, $actor, $evidence, ['evidence_id' => $evidence->getKey()]);

            return $evidence;
        });
    }

    private function validateFile(UploadedFile $file, GrievanceEvidenceType $type): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('grievances.errors.upload_failed')]);
        }
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, $this->allowedExtensions($type), true)) {
            throw ValidationException::withMessages(['file' => __('grievances.errors.file_type_not_allowed')]);
        }
        $detected = strtolower((string) $file->getMimeType());
        if (! in_array($detected, self::MIME_BY_EXTENSION[$extension], true)) {
            throw ValidationException::withMessages(['file' => __('grievances.errors.file_content_mismatch')]);
        }
        if ($file->getSize() > $this->settings->evidenceMaxSizeKb() * 1024) {
            throw ValidationException::withMessages(['file' => __('grievances.errors.file_too_large', ['kb' => $this->settings->evidenceMaxSizeKb()])]);
        }

        return $extension;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? 'file';

        return Str::limit(trim($name, ' ._') ?: 'file', 180, '');
    }
}
