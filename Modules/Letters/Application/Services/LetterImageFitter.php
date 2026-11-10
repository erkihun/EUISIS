<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Support\Images\ImageKind;
use App\Support\Images\ImageOptimizer;
use Illuminate\Support\Facades\Storage;
use Modules\Letters\Domain\Models\LetterLetterhead;

/**
 * Brings emblems and stamps down to the size they print at. A logo exported
 * from a design tool is often 7,000 pixels wide: decoding it takes over
 * 150 MB, more than a web request gets on most servers, so opening the
 * letterhead or printing a letter answered 500 there. Images are therefore
 * fitted once, when uploaded, with the platform optimizer (which reserves the
 * memory first and gives up safely when it cannot); what was stored before
 * that is fitted by `letters:fit-images`.
 */
class LetterImageFitter
{
    /** Longest side of a fitted emblem or stamp (ImageKind::Print). */
    public const MAX_PX = 600;

    /** Longest side of a picture that prints across the whole page width (ImageKind::PrintWide). */
    public const WIDE_PX = 1600;

    /**
     * An image above this many pixels that could not be fitted is refused at
     * upload and left out of a PDF: the PDF engine would run out of memory on
     * it just as this class did.
     */
    public const SAFE_PIXELS = 4_000_000;

    public function __construct(private readonly ImageOptimizer $optimizer) {}

    /** @return array{0: int, 1: int}|null width and height read from the file header (nothing is decoded) */
    public function dimensions(string $file): ?array
    {
        $info = is_file($file) ? @getimagesize($file) : false;

        return $info === false || $info[0] < 1 || $info[1] < 1 ? null : [(int) $info[0], (int) $info[1]];
    }

    public function needsFitting(string $file, bool $wide = false): bool
    {
        $size = $this->dimensions($file);

        return $size !== null && max($size) > ($wide ? self::WIDE_PX : self::MAX_PX);
    }

    /** Whether an unfitted image is too large to hand to the PDF engine. */
    public function unsafe(string $file): bool
    {
        $size = $this->dimensions($file);

        return $size !== null && $size[0] * $size[1] > self::SAFE_PIXELS;
    }

    /**
     * The image at print size, or null when this server cannot decode it.
     *
     * @return array{bytes: string, extension: string}|null
     */
    public function fitted(string $file, bool $wide = false): ?array
    {
        $result = $this->optimizer->optimize($file, $wide ? ImageKind::PrintWide : ImageKind::Print);
        if ($result === null) {
            return null;
        }
        try {
            $bytes = file_get_contents($result->path);

            return $bytes === false || $bytes === '' ? null : ['bytes' => $bytes, 'extension' => $result->extension];
        } finally {
            $result->discard();
        }
    }

    /**
     * Fits every stored emblem and stamp that is still larger than it prints,
     * in place. Run from the command line, where memory is not rationed.
     *
     * @return array{checked: int, fitted: int, failed: list<string>}
     */
    public function fitStored(bool $dryRun = false): array
    {
        $disk = Storage::disk(LetterLetterhead::DISK);
        $report = ['checked' => 0, 'fitted' => 0, 'failed' => []];

        foreach (LetterLetterhead::query()->cursor() as $layout) {
            $wideKeys = LetterLayout::wideImages((array) $layout->header, (array) $layout->footer);
            $widePaths = array_column(array_filter($layout->imageList(), fn (array $image): bool => in_array($image['key'], $wideKeys, true)), 'path');
            $paths = [...array_column($layout->imageList(), 'path'), ...array_filter([$layout->stamp_path])];
            foreach (array_unique($paths) as $path) {
                $wide = in_array($path, $widePaths, true);
                if (! $disk->exists($path)) {
                    continue;
                }
                $report['checked']++;
                $file = $disk->path($path);
                if (! $this->needsFitting($file, $wide)) {
                    continue;
                }
                $fit = $dryRun ? ['bytes' => '', 'extension' => ''] : $this->fitted($file, $wide);
                if ($fit === null) {
                    $report['failed'][] = $layout->name.': '.$path;

                    continue;
                }
                if (! $dryRun) {
                    $disk->put($path, $fit['bytes']);
                }
                $report['fitted']++;
            }
        }

        return $report;
    }
}
