<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Domain\Models\LetterLetterhead;
use Throwable;

/**
 * Which header-and-footer layout a letter prints on, and that layout ready
 * for the PDF: the layout the letter chose (when it belongs to the letter's
 * institution or the platform and is active), else the institution's default
 * layout, else the platform's default, else the built-in layout. Images are
 * returned as data URIs at print size, so dompdf can embed them.
 */
class LetterheadResolver
{
    public function __construct(private readonly LetterImageFitter $fitter) {}

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
        $wide = LetterLayout::wideImages($header, $footer);
        foreach (LetterLayout::usedImages($header, $footer) as $key) {
            $uri = $key === LetterLayout::ORG_LOGO
                ? ($organization !== null ? $this->dataUri($organization->logo_path, 'public', true) : null)
                : $this->dataUri($paths[$key] ?? null, LetterLetterhead::DISK, true, in_array($key, $wide, true));
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
            'stamp' => $this->dataUri($layout?->stamp_path, LetterLetterhead::DISK, true),
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

    /**
     * The image file as a data URI at print size, or null when it is missing or
     * not an image. With $forPrint, an image this server cannot bring down to
     * print size and that is too large for the PDF engine is left out (null)
     * rather than allowed to exhaust the request's memory.
     */
    public function dataUri(?string $path, string $disk, bool $forPrint = false, bool $wide = false): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }
            $bytes = Storage::disk($disk)->get($path);
        } catch (Throwable) {
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
            $fitted = $this->printSize($bytes, $mime, $disk, $path, $wide);
            if ($fitted === null && $forPrint) {
                return null;
            }
            $bytes = $fitted ?? $bytes;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * A raster emblem or stamp at print size: the stored bytes when they are
     * small already, else a fitted copy cached on the same disk by content.
     * Null when the image is too large to print and cannot be fitted here.
     */
    private function printSize(string $bytes, string $mime, string $disk, string $path, bool $wide = false): ?string
    {
        try {
            $file = Storage::disk($disk)->path($path);
        } catch (Throwable) {
            return $bytes;
        }
        if (! $this->fitter->needsFitting($file, $wide)) {
            return $bytes;
        }
        $cache = 'letters/letterheads/print/'.sha1($bytes).($wide ? '-wide' : '').($mime === 'image/png' ? '.png' : '.jpg');
        try {
            if (Storage::disk($disk)->exists($cache)) {
                return (string) Storage::disk($disk)->get($cache);
            }
        } catch (Throwable) {
            // The cache is a convenience; fall through and fit again.
        }

        $fit = $this->fitter->fitted($file, $wide);
        if ($fit === null) {
            return $this->fitter->unsafe($file) ? null : $bytes;
        }
        try {
            Storage::disk($disk)->put($cache, $fit['bytes']);
        } catch (Throwable) {
            // Not cached this time; the next request fits it again.
        }

        return $fit['bytes'];
    }
}
