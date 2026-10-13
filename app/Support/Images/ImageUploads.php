<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\Storage\PublicImageExtension;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an uploaded image after optimisation, under a random name whose
 * extension comes from the stored bytes (never from the uploader's file name,
 * risk register SEC-10). When the image cannot be optimised it is stored as
 * uploaded, exactly as before.
 */
final class ImageUploads
{
    public static function store(UploadedFile $file, ImageKind $kind, string $directory, string $disk, string $baseName): string
    {
        // Sniffs the bytes and rejects anything that is not an image.
        $uploadedExtension = PublicImageExtension::for($file);
        $optimized = app(ImageOptimizer::class)->optimize((string) $file->getRealPath(), $kind);

        if ($optimized === null) {
            return (string) $file->storeAs($directory, $baseName.'.'.strtolower($uploadedExtension), $disk);
        }

        try {
            $path = Storage::disk($disk)->putFileAs($directory, new File($optimized->path), $baseName.'.'.$optimized->extension);
        } finally {
            $optimized->discard();
        }

        if ($path === false) {
            return (string) $file->storeAs($directory, $baseName.'.'.strtolower($uploadedExtension), $disk);
        }

        return $path;
    }
}
