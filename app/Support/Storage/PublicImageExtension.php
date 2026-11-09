<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Extension for a file stored on the PUBLIC disk, taken from the file's
 * detected content type — never from the uploader's file name. A valid image
 * named "x.html" is therefore stored as ".png" and can never be served as
 * HTML from the application origin (stored XSS, risk register SEC-10).
 */
final class PublicImageExtension
{
    private const BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    public static function for(UploadedFile $file): string
    {
        // Sniff the actual bytes; the client name and declared type are ignored.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath());

        return self::BY_MIME[$mime]
            ?? throw new InvalidArgumentException("Unsupported public image type [{$mime}].");
    }
}
