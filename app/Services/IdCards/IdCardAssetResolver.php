<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves storage paths and remote-safe URLs to base64 data URIs
 * suitable for embedding inside an SVG <image> element.
 *
 * Rules:
 * - Only reads files from the public disk (storage/app/public).
 * - Refuses to read private paths or paths outside the storage root.
 * - Returns null on any error so the renderer can show a placeholder.
 */
final class IdCardAssetResolver
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB guard

    /**
     * Resolve a public-disk storage path (e.g. "photos/abc.jpg") to a data URI.
     * The path must not be absolute; it is relative to storage/app/public.
     */
    public function resolveStoragePath(?string $relativePath, int $maxBytes = self::MAX_BYTES): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        // Strip leading /storage/ prefix that the model appends for web URLs
        $path = ltrim($relativePath, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        try {
            if (! Storage::disk('public')->exists($path)) {
                return null;
            }

            $size = Storage::disk('public')->size($path);
            if ($size > $maxBytes) {
                return null;
            }

            $bytes = Storage::disk('public')->get($path);
            $mimeType = Storage::disk('public')->mimeType($path);

            if (! in_array($mimeType, self::ALLOWED_MIME, true)) {
                return null;
            }

            return 'data:'.$mimeType.';base64,'.base64_encode((string) $bytes);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a model's photo_path or logo_path to a data URI.
     * Accepts either a bare storage-relative path or the "/storage/..." web URL form.
     */
    public function resolvePhotoPath(?string $photoPath): ?string
    {
        if ($photoPath && preg_match('#^employee-photos/[a-zA-Z0-9-]+\.(jpg|jpeg|png|webp)$#D', $photoPath)) {
            $disk = Storage::disk('local');
            if ($disk->exists($photoPath) && $disk->size($photoPath) <= 4 * 1024 * 1024) {
                $mime = $disk->mimeType($photoPath);
                if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return 'data:'.$mime.';base64,'.base64_encode($disk->get($photoPath));
                }
            }
            return null;
        }
        // Employee create/update accepts photos up to 4096 KB.
        return $this->resolveStoragePath($photoPath, 4 * 1024 * 1024);
    }

    public function resolveLogoPath(?string $logoPath): ?string
    {
        return $this->resolveStoragePath($logoPath);
    }
}
