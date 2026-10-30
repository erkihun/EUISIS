<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Domain\Enums\AttachmentScanStatus;

/**
 * Private storage for communication files (docs/communication-module.md §Attachments).
 *
 * The disk is `communication.attachments_disk` (the private `local` disk by
 * default; an S3-compatible private bucket in production). Files get random
 * names, the content's MIME type is detected server-side and must match the
 * extension, and the browser never sees a path.
 */
final class AttachmentStorage
{
    /** Extension => acceptable detected MIME types. */
    public const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
    ];

    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly AttachmentScanner $scanner,
    ) {}

    public function disk(): string
    {
        return (string) config('communication.attachments_disk', 'local');
    }

    /**
     * Validate and store. Throws a validation error naming the rule broken.
     *
     * @return array{original_name: string, storage_name: string, disk: string, path: string, mime_type: string, extension: string, size: int, checksum: string, scan_status: AttachmentScanStatus, metadata: array<string, mixed>}
     */
    public function store(UploadedFile $file, string $folder): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, $this->settings->allowedAttachmentTypes(), true) || ! isset(self::MIME_MAP[$extension])) {
            throw ValidationException::withMessages(['file' => __('communication.errors.type_not_allowed')]);
        }
        if ($file->getSize() > $this->settings->maxAttachmentKb() * 1024) {
            throw ValidationException::withMessages(['file' => __('communication.errors.too_large', ['max' => $this->settings->maxAttachmentKb()])]);
        }
        // The detected type, never the client's claim.
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($detected, self::MIME_MAP[$extension], true)) {
            throw ValidationException::withMessages(['file' => __('communication.errors.type_mismatch')]);
        }

        $storageName = Str::uuid7()->toString().'.'.$extension;
        $path = trim($folder, '/').'/'.$storageName;
        Storage::disk($this->disk())->putFileAs(trim($folder, '/'), $file, $storageName);

        $metadata = [];
        if (str_starts_with($detected, 'image/') && ($size = @getimagesize($file->getRealPath())) !== false) {
            $metadata = ['width' => $size[0], 'height' => $size[1]];
        }

        return [
            'original_name' => mb_substr(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 0, 200),
            'storage_name' => $storageName,
            'disk' => $this->disk(),
            'path' => $path,
            'mime_type' => $detected,
            'extension' => $extension,
            'size' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => $this->settings->scanAttachments() ? $this->scanner->scan($this->disk(), $path) : AttachmentScanStatus::Skipped,
            'metadata' => $metadata,
        ];
    }
}
