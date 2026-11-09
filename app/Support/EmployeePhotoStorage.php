<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Images\ImageKind;
use App\Support\Images\ImageOptimizer;
use App\Support\Images\ImageUploads;
use Illuminate\Http\File;
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

    /** Small copies for public confirmation screens; disposable, always re-derivable from the photo. */
    public const THUMBNAIL_DIRECTORY = 'employee-photo-thumbnails';

    /** Longest side of a thumbnail: sharp at 3x on the ~128 px frame of the public feedback page. */
    public const THUMBNAIL_SIZE = 384;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** What ImageOptimizer can produce, most likely first. */
    private const THUMBNAIL_EXTENSIONS = ['webp', 'jpg', 'png'];

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

        $disk = self::isPrivate($path) ? self::PRIVATE_DISK : 'public';

        Storage::disk($disk)->delete($path);
        Storage::disk(self::PRIVATE_DISK)->delete(array_map(
            static fn (string $extension): string => self::thumbnailBase($disk, $path).'.'.$extension,
            self::THUMBNAIL_EXTENSIONS,
        ));
    }

    /**
     * Where a recorded photo is stored, or null when the value is not a stored
     * photo: empty, a traversal or absolute path, an unexpected extension, or a
     * file that is not there.
     *
     * @return array{disk: string, path: string}|null
     */
    public static function locate(?string $path): ?array
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')
            || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }

        $disk = self::isPrivate($path) ? self::PRIVATE_DISK : 'public';

        return Storage::disk($disk)->exists($path) ? ['disk' => $disk, 'path' => $path] : null;
    }

    /**
     * A small copy of the photo for public confirmation screens.
     *
     * Made once, on first request, from the stored photo, and kept on the
     * private disk under a name derived from the photo's path. A replaced photo
     * has a new path and so gets a new copy; the stored photo is never altered.
     * When no smaller copy can be made the stored photo itself is returned.
     *
     * @return array{disk: string, path: string}|null
     */
    public static function thumbnail(?string $path): ?array
    {
        $source = self::locate($path);

        if ($source === null) {
            return null;
        }

        $private = Storage::disk(self::PRIVATE_DISK);
        $base = self::thumbnailBase($source['disk'], $source['path']);

        foreach (self::THUMBNAIL_EXTENSIONS as $extension) {
            if ($private->exists($base.'.'.$extension)) {
                return ['disk' => self::PRIVATE_DISK, 'path' => $base.'.'.$extension];
            }
        }

        $optimized = app(ImageOptimizer::class)->optimize(
            Storage::disk($source['disk'])->path($source['path']),
            ImageKind::Photo,
            self::THUMBNAIL_SIZE,
        );

        if ($optimized === null) {
            return $source;
        }

        try {
            $stored = $private->putFileAs(self::THUMBNAIL_DIRECTORY, new File($optimized->path), basename($base).'.'.$optimized->extension);
        } finally {
            $optimized->discard();
        }

        return is_string($stored) ? ['disk' => self::PRIVATE_DISK, 'path' => $stored] : $source;
    }

    private static function thumbnailBase(string $disk, string $path): string
    {
        return self::THUMBNAIL_DIRECTORY.'/'.hash('sha256', $disk.':'.$path).'-'.self::THUMBNAIL_SIZE;
    }
}
