<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Per-template appearance for the employee photo on the back face.
 *
 * The back photo is a security watermark rather than a portrait, so it is off
 * by default and normally printed faint. A template that stores nothing renders
 * exactly as it did before the back photo existed.
 */
final readonly class IdCardBackPhoto
{
    /** How the image is fitted into its layout box. */
    public const FITS = ['cover', 'contain', 'stretch'];

    /** Percent, so the editor and the validator share one range. */
    public const MIN_OPACITY = 0;

    public const MAX_OPACITY = 100;

    public const MIN_CONTRAST = 0;

    public const MAX_CONTRAST = 300;

    /** Faint enough to read through, which is the point of a watermark. */
    public const DEFAULT_OPACITY = 15;

    public const DEFAULT_CONTRAST = 100;

    public const DEFAULT_FIT = 'cover';

    public function __construct(
        public bool $show,
        public int $opacity,
        public int $contrast,
        public string $fit,
        public ?string $backgroundColor,
    ) {}

    /**
     * Build from stored JSON, clamping every value so a bad row can never reach
     * the renderer, which would otherwise inline it into the SVG.
     */
    public static function fromArray(?array $data): self
    {
        $int = static fn (string $key, int $default, int $min, int $max): int => is_numeric($data[$key] ?? null)
            ? max($min, min($max, (int) $data[$key]))
            : $default;

        $fit = (string) ($data['fit'] ?? self::DEFAULT_FIT);
        $color = trim((string) ($data['background_color'] ?? ''));

        return new self(
            (bool) ($data['show'] ?? false),
            $int('opacity', self::DEFAULT_OPACITY, self::MIN_OPACITY, self::MAX_OPACITY),
            $int('contrast', self::DEFAULT_CONTRAST, self::MIN_CONTRAST, self::MAX_CONTRAST),
            in_array($fit, self::FITS, true) ? $fit : self::DEFAULT_FIT,
            IdCardTextStyle::isHexColor($color) ? $color : null,
        );
    }

    /** SVG preserveAspectRatio value for the chosen fit. */
    public function preserveAspectRatio(): string
    {
        return match ($this->fit) {
            'contain' => 'xMidYMid meet',
            'stretch' => 'none',
            default => 'xMidYMid slice',
        };
    }

    /** CSS object-fit value for the chosen fit, used by the preview. */
    public function objectFit(): string
    {
        return match ($this->fit) {
            'contain' => 'contain',
            'stretch' => 'fill',
            default => 'cover',
        };
    }

    /** Stored shape, for the template editor. */
    public function toArray(): array
    {
        return [
            'show' => $this->show,
            'opacity' => $this->opacity,
            'contrast' => $this->contrast,
            'fit' => $this->fit,
            'background_color' => $this->backgroundColor,
        ];
    }
}
