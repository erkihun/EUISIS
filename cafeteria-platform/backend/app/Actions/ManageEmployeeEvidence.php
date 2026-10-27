<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An employee's identity evidence (ADR 0009): documents, the photo and the
 * signature. Authorization is EmployeeDocumentPolicy's; this action keeps the
 * invariants:
 *  - the file is inspected on the server (content type from the bytes, the
 *    extension must agree, images must decode, PDFs must look like PDFs and
 *    carry no script); the browser's type is never trusted;
 *  - it is stored on the private `evidence` disk under a generated name, with
 *    its SHA-256 checksum; the original name is kept only as metadata;
 *  - a replacement adds the next version and keeps the earlier ones; the
 *    lineage row is locked, so two replacements cannot both become current;
 *  - only someone other than the uploader verifies or rejects a version;
 *  - a version that took part in an approval or a card is archived, never
 *    deleted; only an unused pending upload of an employee not yet approved
 *    may be discarded;
 *  - every change and every read of a document or signature is audited,
 *    without the file or its contents.
 */
final readonly class ManageEmployeeEvidence
{
    public const DISK = 'evidence';

    /** Content types the server accepts, with the extensions each may carry. */
    private const TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    public function __construct(private AuditWriter $audit, private CoreSettings $settings) {}

    /**
     * A new document (a new lineage), or the employee's photo or signature
     * (which replaces the current one, as its next version).
     *
     * @param  array{document_type?: string, title?: ?string, issued_at?: ?string, expires_at?: ?string}  $meta
     */
    public function upload(Employee $employee, string $kind, UploadedFile $file, array $meta, User $actor): EmployeeDocument
    {
        $type = $kind === EmployeeDocument::DOCUMENT ? (string) ($meta['document_type'] ?? '') : $kind;
        if (! in_array($kind, EmployeeDocument::KINDS, true) || ($kind === EmployeeDocument::DOCUMENT && ! in_array($type, EmployeeDocument::DOCUMENT_TYPES, true))) {
            throw ValidationException::withMessages(['document_type' => 'Choose a document type.']);
        }
        $inspected = $this->inspect($file, $kind);

        return DB::transaction(function () use ($employee, $kind, $type, $file, $meta, $actor, $inspected): EmployeeDocument {
            Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $current = $kind === EmployeeDocument::DOCUMENT ? null : EmployeeDocument::query()->lockForUpdate()
                ->where('employee_id', $employee->getKey())->where('kind', $kind)->where('is_current', true)->first();

            return $current === null
                ? $this->store($employee, $kind, $type, null, 1, $file, $inspected, $meta, $actor, 'employee_document.uploaded')
                : $this->replaceLocked($current, $file, $inspected, $meta, $actor);
        });
    }

    /** @param array{title?: ?string, issued_at?: ?string, expires_at?: ?string} $meta */
    public function replace(EmployeeDocument $document, UploadedFile $file, array $meta, User $actor): EmployeeDocument
    {
        $inspected = $this->inspect($file, $document->kind);

        return DB::transaction(function () use ($document, $file, $meta, $actor, $inspected): EmployeeDocument {
            $current = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if (! $current->is_current || $current->status === EmployeeDocument::ARCHIVED) {
                throw ValidationException::withMessages(['document' => 'Only the current version of a document can be replaced.']);
            }

            return $this->replaceLocked($current, $file, $inspected, $meta, $actor);
        });
    }

    /** A pending current version is verified or rejected, by someone other than its uploader. */
    public function decide(EmployeeDocument $document, string $decision, ?string $notes, User $actor): EmployeeDocument
    {
        if (! in_array($decision, [EmployeeDocument::VERIFIED, EmployeeDocument::REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => 'Verify or reject the document.']);
        }
        if ($decision === EmployeeDocument::REJECTED && trim((string) $notes) === '') {
            throw ValidationException::withMessages(['notes' => 'Give the reason for rejecting the document.']);
        }

        return DB::transaction(function () use ($document, $decision, $notes, $actor): EmployeeDocument {
            $document = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if (! $document->is_current || $document->status !== EmployeeDocument::PENDING) {
                throw ValidationException::withMessages(['document' => 'Only the current version waiting for verification can be decided.']);
            }
            if ((int) $document->uploaded_by === (int) $actor->getKey()) {
                throw ValidationException::withMessages(['document' => 'Someone other than the person who uploaded it must verify or reject it.']);
            }
            if ($decision === EmployeeDocument::VERIFIED && $document->expires_at !== null && $document->expires_at->lt(today())) {
                throw ValidationException::withMessages(['document' => 'This document has expired; upload the current one.']);
            }
            $document->forceFill(['status' => $decision, 'verified_by' => $actor->getKey(), 'verified_at' => now(), 'verification_notes' => $notes])->save();
            $this->audit->write('employee_document.'.$decision, $actor, $document, $this->organizationId($document), ['status' => EmployeeDocument::PENDING],
                ['status' => $decision, 'kind' => $document->kind, 'document_type' => $document->document_type, 'version' => $document->version], $notes);

            return $document;
        });
    }

    /** The current version is withdrawn (with its history kept); nothing is deleted. */
    public function archive(EmployeeDocument $document, string $reason, User $actor): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $reason, $actor): EmployeeDocument {
            $document = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if (! $document->is_current) {
                throw ValidationException::withMessages(['document' => 'Only the current version can be archived; earlier versions are history.']);
            }
            $before = $document->status;
            $document->forceFill(['status' => EmployeeDocument::ARCHIVED, 'is_current' => false, 'archived_by' => $actor->getKey(), 'archived_at' => now(), 'archive_reason' => $reason])->save();
            $this->audit->write('employee_document.archived', $actor, $document, $this->organizationId($document), ['status' => $before],
                ['status' => EmployeeDocument::ARCHIVED, 'kind' => $document->kind, 'document_type' => $document->document_type, 'version' => $document->version], $reason);

            return $document;
        });
    }

    /**
     * An upload that never served (pending, the only version, of an employee
     * not yet approved) is removed with its file; anything else is archived.
     */
    public function discard(EmployeeDocument $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $document = EmployeeDocument::query()->lockForUpdate()->with('employee')->findOrFail($document->getKey());
            $versions = EmployeeDocument::query()->where('lineage_id', $document->lineage_id)->count();
            if ($document->status !== EmployeeDocument::PENDING || $versions > 1 || $document->employee?->isApproved()) {
                throw ValidationException::withMessages(['document' => 'Only an unused pending upload of an employee not yet approved can be discarded; archive it instead.']);
            }
            $organizationId = $this->organizationId($document);
            $document->delete();
            DB::afterCommit(fn () => Storage::disk($document->storage_disk)->delete($document->storage_path));
            $this->audit->write('employee_document.discarded', $actor, $document, $organizationId, null,
                ['kind' => $document->kind, 'document_type' => $document->document_type, 'checksum' => $document->checksum]);
        });
    }

    /**
     * The file, streamed to someone the policy let through; documents and
     * signatures are audited on every read, a photo when it is downloaded.
     */
    public function stream(EmployeeDocument $document, User $actor, bool $inline): StreamedResponse
    {
        $disk = Storage::disk($document->storage_disk);
        if (! $disk->exists($document->storage_path)) {
            abort(404, 'The file of this document is missing; report it to the administrator.');
        }
        if ($document->kind !== EmployeeDocument::PHOTO || ! $inline) {
            $this->audit->write('employee_document.downloaded', $actor, $document, $this->organizationId($document), null,
                ['kind' => $document->kind, 'document_type' => $document->document_type, 'version' => $document->version, 'inline' => $inline]);
        }
        $name = $this->safeName($document);
        $disposition = HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, Str::ascii($name) ?: 'document');

        return new StreamedResponse(function () use ($disk, $document): void {
            $stream = $disk->readStream($document->storage_path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) $document->file_size,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * What the bytes are, checked on the server.
     *
     * @return array{mime: string, extension: string, width: ?int, height: ?int}
     */
    public function inspect(UploadedFile $file, string $kind): array
    {
        if (! $file->isValid() || $file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'The file is empty or did not upload completely.']);
        }
        $maxKb = match ($kind) {
            EmployeeDocument::PHOTO => $this->settings->int('files.photo_max_kb'),
            EmployeeDocument::SIGNATURE => $this->settings->int('files.signature_max_kb'),
            default => $this->settings->int('files.document_max_kb'),
        };
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => "The file is larger than {$maxKb} KB."]);
        }
        // A file the server cannot read (an antivirus scanner may lock or remove an upload) is refused, not a 500 (ADR 0012).
        $path = $file->getRealPath();
        $mime = $path === false ? false : @(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime)) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Upload it again.']);
        }
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $allowed = $kind === EmployeeDocument::DOCUMENT ? self::TYPES : array_diff_key(self::TYPES, ['application/pdf' => true]);
        if (! isset($allowed[$mime]) || ! in_array($extension, $allowed[$mime], true)) {
            throw ValidationException::withMessages(['file' => $kind === EmployeeDocument::DOCUMENT
                ? 'Upload a PDF, JPEG or PNG file whose name ends with its real type.'
                : 'Upload a JPEG or PNG image whose name ends with its real type.']);
        }
        if ($mime === 'application/pdf') {
            $this->inspectPdf($file);

            return ['mime' => $mime, 'extension' => 'pdf', 'width' => null, 'height' => null];
        }

        return ['mime' => $mime, 'extension' => $mime === 'image/png' ? 'png' : 'jpg'] + $this->inspectImage($file, $kind, $mime);
    }

    /**
     * @param  array{mime: string, extension: string, width: ?int, height: ?int}  $inspected
     * @param  array{title?: ?string, issued_at?: ?string, expires_at?: ?string}  $meta
     */
    private function store(Employee $employee, string $kind, string $type, ?string $lineageId, int $version, UploadedFile $file, array $inspected, array $meta, User $actor, string $action): EmployeeDocument
    {
        $id = (string) Str::uuid7();
        $path = "{$employee->getKey()}/{$kind}/{$id}.{$inspected['extension']}";
        Storage::disk(self::DISK)->putFileAs("{$employee->getKey()}/{$kind}", $file, "{$id}.{$inspected['extension']}");
        // A failed transaction must not leave an orphan file behind.
        DB::afterRollBack(fn () => Storage::disk(self::DISK)->delete($path));

        $document = new EmployeeDocument;
        $document->forceFill([
            'id' => $id,
            'employee_id' => $employee->getKey(),
            'kind' => $kind,
            'document_type' => $type,
            'title' => isset($meta['title']) ? mb_substr(trim((string) $meta['title']), 0, 200) ?: null : null,
            'lineage_id' => $lineageId ?? $id,
            'version' => $version,
            'is_current' => true,
            'status' => EmployeeDocument::PENDING,
            'storage_disk' => self::DISK,
            'storage_path' => $path,
            'original_filename' => $this->cleanName($file->getClientOriginalName()),
            'mime_type' => $inspected['mime'],
            'file_size' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'width' => $inspected['width'],
            'height' => $inspected['height'],
            'issued_at' => $meta['issued_at'] ?? null,
            'expires_at' => $meta['expires_at'] ?? null,
            'uploaded_by' => $actor->getKey(),
        ])->save();
        $this->audit->write($action, $actor, $document, $this->organizationId($document), null, [
            'kind' => $kind, 'document_type' => $type, 'version' => $version, 'lineage_id' => $document->lineage_id,
            'mime_type' => $document->mime_type, 'file_size' => $document->file_size, 'checksum' => $document->checksum,
        ]);

        return $document;
    }

    /**
     * @param  array{mime: string, extension: string, width: ?int, height: ?int}  $inspected
     * @param  array{title?: ?string, issued_at?: ?string, expires_at?: ?string}  $meta
     */
    private function replaceLocked(EmployeeDocument $current, UploadedFile $file, array $inspected, array $meta, User $actor): EmployeeDocument
    {
        if (hash_equals((string) $current->checksum, (string) hash_file('sha256', $file->getRealPath()))) {
            throw ValidationException::withMessages(['file' => 'This file is already the current version.']);
        }
        $next = (int) EmployeeDocument::query()->where('lineage_id', $current->lineage_id)->max('version') + 1;
        $current->forceFill(['is_current' => false, 'status' => $current->status === EmployeeDocument::REJECTED ? EmployeeDocument::REJECTED : EmployeeDocument::REPLACED])->save();
        $replacement = $this->store($current->employee()->firstOrFail(), $current->kind, $current->document_type, $current->lineage_id, $next, $file, $inspected,
            $meta + ['title' => $current->title], $actor, 'employee_document.replaced');
        $current->forceFill(['replaced_by_id' => $replacement->getKey()])->save();

        return $replacement;
    }

    private function inspectPdf(UploadedFile $file): void
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $head = (string) fread($handle, 1024);
        fclose($handle);
        if (! str_starts_with(ltrim($head), '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'The file is not a readable PDF.']);
        }
        // Active content has no place in an HR record.
        $content = (string) file_get_contents($file->getRealPath());
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction\s*<<[^>]*/JS)\b#', $content) === 1) {
            throw ValidationException::withMessages(['file' => 'PDFs with scripts, launch actions or embedded files are not accepted.']);
        }
    }

    /** @return array{width: int, height: int} */
    private function inspectImage(UploadedFile $file, string $kind, string $mime): array
    {
        $size = @getimagesize($file->getRealPath());
        if ($size === false || ($size['mime'] ?? null) !== $mime || $size[0] < 1 || $size[1] < 1) {
            throw ValidationException::withMessages(['file' => 'The image is damaged or cannot be read.']);
        }
        [$width, $height] = [(int) $size[0], (int) $size[1]];
        // A camera may store the picture sideways with a rotation flag (EXIF orientation 5–8).
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath());
            if (is_array($exif) && in_array((int) ($exif['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }
        if ($kind === EmployeeDocument::PHOTO) {
            $min = $this->settings->int('files.photo_min_pixels');
            if ($width < $min || $height < $min) {
                throw ValidationException::withMessages(['file' => "The photo must be at least {$min} × {$min} pixels."]);
            }
            if ($this->settings->get('files.photo_portrait_only') && $width > $height) {
                throw ValidationException::withMessages(['file' => 'The photo must be upright (portrait), as on an ID card.']);
            }
        }

        return ['width' => $width, 'height' => $height];
    }

    /** The original name as metadata: no path, no control characters, bounded. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name);

        return mb_substr(trim($name), 0, 200) ?: 'file';
    }

    private function safeName(EmployeeDocument $document): string
    {
        $base = pathinfo($this->cleanName((string) $document->original_filename), PATHINFO_FILENAME) ?: $document->document_type;
        $extension = pathinfo((string) $document->storage_path, PATHINFO_EXTENSION);

        return mb_substr($base, 0, 120).'-v'.$document->version.'.'.$extension;
    }

    private function organizationId(EmployeeDocument $document): ?string
    {
        $organizationId = DB::table('employees')->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
            ->where('employees.id', $document->employee_id)->value('employee_assignments.organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }
}
