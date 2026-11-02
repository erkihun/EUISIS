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
            // Containers from before each field had its own box. A template
            // that stores one of these but no field boxes has its fields laid
            // out inside it (IdCardTemplateService::derivedFieldBox).
            'fields' => ['x' => 28.0, 'y' => 22.0, 'w' => 72.0, 'h' => 68.0],
            'emphasis' => ['x' => 2.5, 'y' => 74.5, 'w' => 96.0, 'h' => 12.0],
            // One box per printed field, so each moves on its own. The defaults
            // are the two-column flow inside the default `fields` box, and the
            // ID number in the `emphasis` band.
            'field_name' => ['x' => 28.0, 'y' => 22.0, 'w' => 35.25, 'h' => 10.0],
            'field_sex' => ['x' => 64.75, 'y' => 22.0, 'w' => 35.25, 'h' => 10.0],
            'field_dob' => ['x' => 28.0, 'y' => 32.0, 'w' => 35.25, 'h' => 10.0],
            'field_nationality' => ['x' => 64.75, 'y' => 32.0, 'w' => 35.25, 'h' => 10.0],
            'field_employment' => ['x' => 28.0, 'y' => 42.0, 'w' => 35.25, 'h' => 10.0],
            'field_phone' => ['x' => 64.75, 'y' => 42.0, 'w' => 35.25, 'h' => 10.0],
            'field_email' => ['x' => 28.0, 'y' => 52.0, 'w' => 35.25, 'h' => 10.0],
            'field_address' => ['x' => 64.75, 'y' => 52.0, 'w' => 35.25, 'h' => 10.0],
            'field_idNumber' => ['x' => 2.5, 'y' => 74.5, 'w' => 96.0, 'h' => 12.0],
            // The back's fields, for a template that moves them to the front.
            'field_emergency_contact_name' => ['x' => 28.0, 'y' => 62.0, 'w' => 35.25, 'h' => 10.0],
            'field_emergency_contact_phone' => ['x' => 64.75, 'y' => 62.0, 'w' => 35.25, 'h' => 10.0],
        ],
        'back' => [
            'qr' => ['x' => 0.0, 'y' => 4.5, 'w' => 38.5, 'h' => 57.0],
            'notes' => ['x' => 2.5, 'y' => 83.0, 'w' => 70.5, 'h' => 15.5],
            'seal' => ['x' => 76.5, 'y' => 66.5, 'w' => 15.0, 'h' => 26.0],
            // Two contacts, each a four-line bilingual field. Kept as the
            // container older templates stored; each contact now has its own box.
            'emergency' => ['x' => 41.5, 'y' => 6.0, 'w' => 58.5, 'h' => 43.5],
            'field_emergency_contact_name' => ['x' => 41.5, 'y' => 6.0, 'w' => 58.5, 'h' => 21.75],
            'field_emergency_contact_phone' => ['x' => 41.5, 'y' => 27.75, 'w' => 58.5, 'h' => 21.75],
            'card_number' => ['x' => 7.0, 'y' => 62.0, 'w' => 35.0, 'h' => 7.0],
            'signature' => ['x' => 55.0, 'y' => 78.5, 'w' => 34.0, 'h' => 7.0],
            // The caption naming the signing line, movable on its own so it can
            // sit above it, below it, or somewhere else entirely.
            'signature_label' => ['x' => 55.0, 'y' => 73.5, 'w' => 34.0, 'h' => 5.0],
            // The employee photo as a watermark; off unless a template enables it.
            'photo' => ['x' => 60.0, 'y' => 20.0, 'w' => 25.0, 'h' => 45.0],
            // The front's fields, for a template that moves them to the back:
            // two columns under the emergency contacts.
            'field_name' => ['x' => 41.5, 'y' => 50.0, 'w' => 28.5, 'h' => 8.0],
            'field_sex' => ['x' => 71.0, 'y' => 50.0, 'w' => 28.5, 'h' => 8.0],
            'field_dob' => ['x' => 41.5, 'y' => 58.0, 'w' => 28.5, 'h' => 8.0],
            'field_nationality' => ['x' => 71.0, 'y' => 58.0, 'w' => 28.5, 'h' => 8.0],
            'field_employment' => ['x' => 41.5, 'y' => 66.0, 'w' => 28.5, 'h' => 8.0],
            'field_phone' => ['x' => 71.0, 'y' => 66.0, 'w' => 28.5, 'h' => 8.0],
            'field_idNumber' => ['x' => 41.5, 'y' => 74.0, 'w' => 28.5, 'h' => 8.0],
            'field_email' => ['x' => 71.0, 'y' => 74.0, 'w' => 28.5, 'h' => 8.0],
            'field_address' => ['x' => 41.5, 'y' => 82.0, 'w' => 28.5, 'h' => 8.0],
        ],
    ];

    /** Landscape front fields in the built-in print order; `field_` + key is the element. */
    public const FRONT_FIELDS = ['name', 'sex', 'dob', 'nationality', 'employment', 'phone', 'idNumber', 'email', 'address'];

    /** Back fields; `field_` + key is the element. */
    public const BACK_FIELDS = ['emergency_contact_name', 'emergency_contact_phone'];

    /**
     * The face a field prints on: the template's choice (layout_config.faces)
     * or the field's home face. A portrait card's name and ID number are its
     * large badge elements, so they never leave the front.
     *
     * @param  array<string, mixed>|null  $faces
     */
    public static function faceOf(string $key, ?array $faces, string $orientation = 'landscape'): string
    {
        if ($orientation === 'portrait' && in_array($key, ['name', 'idNumber'], true)) {
            return 'front';
        }
        $chosen = $faces[$key] ?? null;

        return in_array($chosen, ['front', 'back'], true)
            ? $chosen
            : (in_array($key, self::BACK_FIELDS, true) ? 'back' : 'front');
    }

    /**
     * Where a portrait card's fields sit until the template places them. A
     * portrait card is narrow, so each detail field gets a full-width row; its
     * name and ID number are the large `employee_name` and `emphasis` elements
     * instead. Mirrored in IdCardTemplateContext.tsx (PORTRAIT_FIELD_DEFAULTS).
     *
     * @var array<string, array<string, array{x: float, y: float, w: float, h: float}>>
     */
    public const PORTRAIT_FIELDS = [
        'front' => [
            'field_sex' => ['x' => 4.0, 'y' => 24.0, 'w' => 92.0, 'h' => 7.0],
            'field_dob' => ['x' => 4.0, 'y' => 31.0, 'w' => 92.0, 'h' => 7.0],
            'field_nationality' => ['x' => 4.0, 'y' => 38.0, 'w' => 92.0, 'h' => 7.0],
            'field_employment' => ['x' => 4.0, 'y' => 45.0, 'w' => 92.0, 'h' => 7.0],
            'field_phone' => ['x' => 4.0, 'y' => 52.0, 'w' => 92.0, 'h' => 7.0],
            'field_email' => ['x' => 4.0, 'y' => 59.0, 'w' => 92.0, 'h' => 7.0],
            'field_address' => ['x' => 4.0, 'y' => 66.0, 'w' => 92.0, 'h' => 7.0],
            'field_emergency_contact_name' => ['x' => 4.0, 'y' => 73.0, 'w' => 92.0, 'h' => 7.0],
            'field_emergency_contact_phone' => ['x' => 4.0, 'y' => 80.0, 'w' => 92.0, 'h' => 7.0],
        ],
        'back' => [
            'field_emergency_contact_name' => ['x' => 4.0, 'y' => 70.0, 'w' => 92.0, 'h' => 12.0],
            'field_emergency_contact_phone' => ['x' => 4.0, 'y' => 83.0, 'w' => 92.0, 'h' => 12.0],
            'field_sex' => ['x' => 4.0, 'y' => 4.0, 'w' => 92.0, 'h' => 7.0],
            'field_dob' => ['x' => 4.0, 'y' => 11.0, 'w' => 92.0, 'h' => 7.0],
            'field_nationality' => ['x' => 4.0, 'y' => 18.0, 'w' => 92.0, 'h' => 7.0],
            'field_employment' => ['x' => 4.0, 'y' => 25.0, 'w' => 92.0, 'h' => 7.0],
            'field_phone' => ['x' => 4.0, 'y' => 32.0, 'w' => 92.0, 'h' => 7.0],
            'field_email' => ['x' => 4.0, 'y' => 39.0, 'w' => 92.0, 'h' => 7.0],
            'field_address' => ['x' => 4.0, 'y' => 46.0, 'w' => 92.0, 'h' => 7.0],
        ],
    ];

    /** Horizontal gap between the two field columns, and one field row's height, in percent. */
    public const FIELD_GAP = 1.5;

    public const FIELD_ROW = 10.0;

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
