<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * SHA-256 of a stored file, streamed through the disk so it works on any
 * driver (local or S3-compatible object storage) without a local path.
 */
final class StoredFileChecksum
{
    public static function sha256(string $disk, string $path): ?string
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (\Throwable) {
            return null; // disks configured with throw=true (object storage) raise on a missing file
        }
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public static function matches(string $disk, string $path, ?string $expected): bool
    {
        return $expected !== null && hash_equals($expected, (string) self::sha256($disk, $path));
    }
}
