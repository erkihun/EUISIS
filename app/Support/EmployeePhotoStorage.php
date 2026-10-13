<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Images\ImageKind;
use App\Support\Images\ImageUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one place employee photos are written and removed.
 *
 * Photos are personal data, so they live on the PRIVATE disk under
 * employee-photos/{uuid}.{ext} and are only served through authorized
 * routes (employees.private-photo, employee.photo). The file name is random
 * and the extension comes from the sniffed MIME type, never the client name.
 * Photos are shrunk to at most 1024 px and re-encoded on the way in.
 *
 * Older photos may still sit on the public disk until
 * `php artisan employees:privatize-photos` has been run; delete() handles
 * both locations.
 */
final class EmployeePhotoStorage
{
    public const PRIVATE_DISK = 'local';

    public const PREFIX = 'employee-photos/';

    public static function store(UploadedFile $photo): string|false
    {
        return ImageUploads::store($photo, ImageKind::Photo, rtrim(self::PREFIX, '/'), self::PRIVATE_DISK, (string) Str::uuid());
    }

    public static function isPrivate(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::PREFIX);
    }

    public static function delete(?string $path): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return;
        }

        Storage::disk(self::isPrivate($path) ? self::PRIVATE_DISK : 'public')->delete($path);
    }
}
