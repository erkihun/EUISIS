<?php

declare(strict_types=1);

namespace App\Support\Images;

/** A re-encoded image waiting in a temporary file; the caller stores it, then discards the file. */
final class OptimizedImage
{
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
    ) {}

    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
