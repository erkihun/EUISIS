<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Letters\Domain\Enums\AttachmentKind;
use Modules\Letters\Domain\Models\Letter;
use Modules\Letters\Domain\Models\LetterAttachment;

/**
 * Stores scans, annexes and the dispatched PDF on the private disk: the type
 * is decided by sniffing the content (never the client's name or MIME), the
 * size is capped, the path is random, the sha256 is recorded. Files are
 * served only by the authorized download controller, always as attachments.
 */
class LetterAttachmentStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 15 * 1024 * 1024;

    /** @var array<string, string> sniffed MIME => extension */
    public const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function store(UploadedFile $file, Letter $letter, AttachmentKind $kind, User $actor): LetterAttachment
    {
        $path = $file->getRealPath();
        $mime = $path !== false ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        $size = (int) $file->getSize();
        if (! isset(self::ALLOWED[$mime]) || $size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => __('letters.validation.upload_type', ['max' => self::MAX_BYTES / 1024 / 1024])]);
        }

        $stored = $file->storeAs("letters/{$letter->id}", Str::random(40).'.'.self::ALLOWED[$mime], self::DISK);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => __('letters.validation.upload_type', ['max' => self::MAX_BYTES / 1024 / 1024])]);
        }

        return LetterAttachment::query()->create([
            'letter_id' => $letter->id,
            'kind' => $kind->value,
            'disk' => self::DISK,
            'path' => $stored,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime' => $mime,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', (string) $path),
            'uploaded_by' => $actor->id,
        ]);
    }

    /** Stores bytes rendered by the module itself (the dispatched PDF). */
    public function storeRendered(string $bytes, Letter $letter, string $fileName, ?User $actor): LetterAttachment
    {
        $path = "letters/{$letter->id}/".Str::random(40).'.pdf';
        Storage::disk(self::DISK)->put($path, $bytes);

        return LetterAttachment::query()->create([
            'letter_id' => $letter->id,
            'kind' => AttachmentKind::Rendered->value,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => mb_substr($fileName, 0, 255),
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'uploaded_by' => $actor?->id,
        ]);
    }
}
