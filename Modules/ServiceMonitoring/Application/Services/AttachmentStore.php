<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;

/**
 * Stores stamped copies and evidence on the private disk: the type is decided
 * by sniffing the content (never the client's name or MIME), the size is
 * capped, the path is random, and the sha256 is recorded. Files are served
 * only by the authorized download controller, always as attachments.
 */
class AttachmentStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> sniffed MIME => extension */
    public const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function store(UploadedFile $file, string $type, string $ownerId, string $kind, User $actor): MonitoringAttachment
    {
        $path = $file->getRealPath();
        $mime = $path !== false ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        $size = (int) $file->getSize();
        if (! isset(self::ALLOWED[$mime]) || $size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('service-monitoring.validation.upload_type', ['max' => self::MAX_BYTES / 1024 / 1024])]);
        }

        $sha256 = (string) hash_file('sha256', (string) $path);
        $stored = $file->storeAs("service-monitoring/{$type}", Str::random(40).'.'.self::ALLOWED[$mime], self::DISK);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => __('service-monitoring.validation.upload_type', ['max' => self::MAX_BYTES / 1024 / 1024])]);
        }

        return MonitoringAttachment::query()->create([
            'attachable_type' => $type,
            'attachable_id' => $ownerId,
            'kind' => $kind,
            'disk' => self::DISK,
            'path' => $stored,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'uploaded_by' => $actor->id,
        ]);
    }
}
