<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Where one element sits on the card, as a percentage of the card's own width
 * and height.
 *
 * Percentages rather than pixels because the same numbers have to drive two
 * renderers: the 856x540 SVG canvas used for export, and the CSS card used for
 * preview and print. A percentage means the identical value is correct in both.
 */
final readonly class IdCardLayoutElement
{
    /**
     * Elements an administrator can move, with the built-in arrangement as the
     * default. A template that stores nothing keeps exactly this layout.
     *
     * Both landscape faces are positionable; the portrait face still renders
     * from its own fixed arrangement.
     *
     * @var array<string, array<string, array{x: float, y: float, w: float, h: float}>>
     */
    public const ELEMENTS = [
        'front' => [
            'header' => ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 9.0],
            'photo' => ['x' => 1.9, 'y' => 9.6, 'w' => 8.4, 'h' => 17.8],
            'fields' => ['x' => 11.7, 'y' => 9.6, 'w' => 86.0, 'h' => 60.0],
            'emphasis' => ['x' => 1.9, 'y' => 76.0, 'w' => 96.0, 'h' => 12.0],
            'footer' => ['x' => 0.0, 'y' => 94.0, 'w' => 100.0, 'h' => 6.0],
        ],
        'back' => [
            'qr' => ['x' => 1.9, 'y' => 30.0, 'w' => 22.0, 'h' => 45.0],
            'notes' => ['x' => 27.0, 'y' => 5.0, 'w' => 71.0, 'h' => 25.0],
            'seal' => ['x' => 55.0, 'y' => 32.0, 'w' => 22.4, 'h' => 35.6],
            'details' => ['x' => 27.0, 'y' => 73.0, 'w' => 71.0, 'h' => 20.0],
            'dates' => ['x' => 27.0, 'y' => 88.0, 'w' => 71.0, 'h' => 10.0],
            'emergency' => ['x' => 1.9, 'y' => 78.0, 'w' => 23.0, 'h' => 18.0],
        ],
    ];

    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {}

    /**
     * Build from stored JSON, falling back to the element default for anything
     * missing or out of range. The renderer clips silently at the card edge, so
     * a bad stored value must never reach it.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $default
     */
    public static function fromArray(?array $data, array $default): self
    {
        $value = static function (string $key) use ($data, $default): float {
            $candidate = $data[$key] ?? null;

            return is_numeric($candidate) ? (float) $candidate : $default[$key];
        };

        // Leave room for the box itself, so an extreme x or y cannot push the
        // right or bottom edge past the card.
        $x = self::clamp($value('x'), 0.0, 99.0);
        $y = self::clamp($value('y'), 0.0, 99.0);

        return new self(
            $x,
            $y,
            self::clamp($value('w'), 1.0, 100.0 - $x),
            self::clamp($value('h'), 1.0, 100.0 - $y),
        );
    }

    private static function clamp(float $value, float $min = 0.0, float $max = 100.0): float
    {
        return round(max($min, min($max, $value)), 2);
    }

    /** Left edge in canvas units for a card of the given width. */
    public function xIn(float $canvasWidth): int
    {
        return (int) round($canvasWidth * $this->x / 100);
    }

    /** Top edge in canvas units for a card of the given height. */
    public function yIn(float $canvasHeight): int
    {
        return (int) round($canvasHeight * $this->y / 100);
    }

    /** Width in canvas units for a card of the given width. */
    public function wIn(float $canvasWidth): int
    {
        return (int) round($canvasWidth * $this->w / 100);
    }

    /** Height in canvas units for a card of the given height. */
    public function hIn(float $canvasHeight): int
    {
        return (int) round($canvasHeight * $this->h / 100);
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }
}
