<?php

declare(strict_types=1);

namespace App\Support;

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
        return $photo->storeAs(rtrim(self::PREFIX, '/'), Str::uuid().'.'.$photo->extension(), self::PRIVATE_DISK);
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
