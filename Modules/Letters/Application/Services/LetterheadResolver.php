<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Domain\Models\LetterLetterhead;

/**
 * Which header-and-footer layout a letter prints on, and that layout ready
 * for the PDF: the layout the letter chose (when it belongs to the letter's
 * institution or the platform and is active), else the institution's default
 * layout, else the platform's default, else the built-in layout. Images are
 * returned as data URIs, scaled to print size, so dompdf can embed them.
 */
class LetterheadResolver
{
    /** Longest side of an emblem or stamp in the PDF: 600 px is about 300 dpi at 50 mm. */
    public const PRINT_MAX_PX = 600;

    /** @return array<string, mixed> */
    public function for(Organization $organization, ?string $layoutId = null): array
    {
        return $this->resolve($this->pick($organization->id, $layoutId), $organization);
    }

    public function pick(?string $organizationId, ?string $layoutId = null): ?LetterLetterhead
    {
        $active = fn () => LetterLetterhead::query()->where('active', true);
        if ($layoutId !== null && $layoutId !== '') {
            $chosen = $active()->whereKey($layoutId)
                ->where(fn ($q) => $q->whereNull('organization_id')->when($organizationId !== null, fn ($q) => $q->orWhere('organization_id', $organizationId)))
                ->first();
            if ($chosen !== null) {
                return $chosen;
            }
        }
        if ($organizationId !== null) {
            $own = $active()->where('organization_id', $organizationId)->orderByDesc('is_default')->orderBy('created_at')->first();
            if ($own !== null) {
                return $own;
            }
        }

        return $active()->whereNull('organization_id')->orderByDesc('is_default')->orderBy('created_at')->first();
    }

    /**
     * A layout (or the built-in one when null) as the PDF needs it.
     *
     * @return array{id: string|null, name: string|null, page: array<string, mixed>, header: array<string, mixed>, footer: array<string, mixed>, images: array<string, string>, stamp: string|null, source: string}
     */
    public function resolve(?LetterLetterhead $layout, ?Organization $organization): array
    {
        $defaults = LetterLayout::defaults();
        $keys = $layout?->imageKeys() ?? [];
        $header = $layout !== null ? LetterLayout::section($layout->header, $keys) : $defaults['header'];
        $footer = $layout !== null ? LetterLayout::section($layout->footer, $keys) : $defaults['footer'];
        $paths = $layout !== null ? array_column($layout->imageList(), 'path', 'key') : [];

        $images = [];
        foreach (LetterLayout::usedImages($header, $footer) as $key) {
            $uri = $key === LetterLayout::ORG_LOGO
                ? ($organization !== null ? $this->dataUri($organization->logo_path, 'public') : null)
                : $this->dataUri($paths[$key] ?? null, LetterLetterhead::DISK);
            if ($uri !== null) {
                $images[$key] = $uri;
            }
        }

        return [
            'id' => $layout?->id,
            'name' => $layout?->name,
            'page' => LetterLayout::page((array) ($layout?->page ?? [])),
            'header' => $header,
            'footer' => $footer,
            'images' => $images,
            'stamp' => $this->dataUri($layout?->stamp_path, LetterLetterhead::DISK),
            'source' => $layout === null ? 'default' : ($layout->organization_id === null ? 'platform' : 'institution'),
        ];
    }

    /**
     * The layouts a letter of this institution may print on: its own active
     * layouts, else the platform's. The default comes first.
     *
     * @return list<array{id: string, name: string, is_default: bool, platform: bool}>
     */
    public function options(?string $organizationId): array
    {
        $query = fn () => LetterLetterhead::query()->where('active', true)->orderByDesc('is_default')->orderBy('name');
        $rows = $organizationId !== null ? $query()->where('organization_id', $organizationId)->get() : collect();
        if ($rows->isEmpty()) {
            $rows = $query()->whereNull('organization_id')->get();
        }

        return $rows->map(fn (LetterLetterhead $l): array => ['id' => $l->id, 'name' => (string) $l->name, 'is_default' => $l->is_default, 'platform' => $l->organization_id === null])->values()->all();
    }

    /** The image file as a data URI, or null when it is missing or not an image dompdf can embed. */
    public function dataUri(?string $path, string $disk): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $bytes = Storage::disk($disk)->get($path);
        } catch (\Throwable) {
            return null;
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml'], true)) {
            return null;
        }

        if ($mime !== 'image/svg+xml') {
            $bytes = $this->printSize($bytes, $mime, $disk);
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * A raster emblem or stamp scaled down to print size. dompdf walks every
     * pixel of a transparent PNG, so a phone photo of a 7000-pixel emblem
     * makes each letter take minutes. The scaled copy is cached on the same
     * disk, keyed by its content; without GD the original is used.
     */
    private function printSize(string $bytes, string $mime, string $disk): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || max($size[0], $size[1]) <= self::PRINT_MAX_PX) {
            return $bytes;
        }
        $cache = 'letters/letterheads/print/'.sha1($bytes).($mime === 'image/png' ? '.png' : '.jpg');
        try {
            if (Storage::disk($disk)->exists($cache)) {
                return (string) Storage::disk($disk)->get($cache);
            }
        } catch (\Throwable) {
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return $bytes;
        }
        $scale = self::PRINT_MAX_PX / max($size[0], $size[1]);
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $target = imagecreatetruecolor($width, $height);
        if ($mime === 'image/png') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        ob_start();
        $mime === 'image/png' ? imagepng($target, null, 9) : imagejpeg($target, null, 90);
        $scaled = (string) ob_get_clean();
        if ($scaled === '') {
            return $bytes;
        }
        try {
            Storage::disk($disk)->put($cache, $scaled);
        } catch (\Throwable) {
        }

        return $scaled;
    }
}
