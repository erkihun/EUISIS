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
     * Both faces in both orientations are positionable from the same
     * percentage-based boxes.
     *
     * @var array<string, array<string, array{x: float, y: float, w: float, h: float}>>
     */
    public const ELEMENTS = [
        'front' => [
            // The band and its text. Each logo is positioned separately so an
            // admin can move one mark without disturbing the other.
            'header' => ['x' => 0.0, 'y' => 0.0, 'w' => 85.0, 'h' => 16.0],
            'logo_primary' => ['x' => 1.5, 'y' => 2.5, 'w' => 10.5, 'h' => 14.0],
            'logo_secondary' => ['x' => 72.0, 'y' => 0.0, 'w' => 27.0, 'h' => 15.5],
            // Both clear the header band rather than starting underneath it.
            'photo' => ['x' => 2.0, 'y' => 22.0, 'w' => 25.0, 'h' => 49.0],
            'employee_name' => ['x' => 28.0, 'y' => 22.0, 'w' => 72.0, 'h' => 10.0],
            'employee_position' => ['x' => 28.0, 'y' => 32.0, 'w' => 72.0, 'h' => 8.0],
            'fields' => ['x' => 28.0, 'y' => 22.0, 'w' => 72.0, 'h' => 68.0],
            'emphasis' => ['x' => 2.5, 'y' => 74.5, 'w' => 96.0, 'h' => 12.0],
            // Issue and expiry sit on the front, between the emphasised ID
            // number and the footer, so validity reads without turning the card.
            'dates' => ['x' => 2.5, 'y' => 86.5, 'w' => 96.0, 'h' => 6.0],
        ],
        'back' => [
            'qr' => ['x' => 0.0, 'y' => 4.5, 'w' => 38.5, 'h' => 57.0],
            'notes' => ['x' => 2.5, 'y' => 83.0, 'w' => 70.5, 'h' => 15.5],
            'seal' => ['x' => 76.5, 'y' => 66.5, 'w' => 15.0, 'h' => 26.0],
            // Two contacts, each a four-line bilingual field.
            'emergency' => ['x' => 41.5, 'y' => 6.0, 'w' => 58.5, 'h' => 43.5],
            'card_number' => ['x' => 7.0, 'y' => 62.0, 'w' => 35.0, 'h' => 7.0],
            'signature' => ['x' => 55.0, 'y' => 78.5, 'w' => 34.0, 'h' => 7.0],
            // The caption naming the signing line, movable on its own so it can
            // sit above it, below it, or somewhere else entirely.
            'signature_label' => ['x' => 55.0, 'y' => 73.5, 'w' => 34.0, 'h' => 5.0],
            // The employee photo as a watermark; off unless a template enables it.
            'photo' => ['x' => 60.0, 'y' => 20.0, 'w' => 25.0, 'h' => 45.0],
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
