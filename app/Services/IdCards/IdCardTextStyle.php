<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Per-template typography for one text role on one card side.
 *
 * Every value is validated on the way in, so renderers can inline these into
 * SVG/CSS without further escaping concerns.
 */
final readonly class IdCardTextStyle
{
    /** Font sizes offered in the template editor. */
    public const SIZES = [
        '7px', '8px', '9px', '10px', '11px', '12px',
        '13px', '14px', '15px', '16px', '18px', '20px',
    ];

    /** Font weights offered in the template editor. */
    public const WEIGHTS = ['400', '500', '600', '700', '800'];

    /**
     * Text roles per side, with the built-in defaults used when a template
     * stores nothing. Colour keys resolve against the layout settings so
     * templates saved before per-template typography look unchanged.
     *
     * @var array<string, array<string, array{size: string, weight: string, color: string}>>
     */
    public const ROLES = [
        // A quiet label against a heavier value reads as a hierarchy rather
        // than a wall of text, and the small label leaves the value room to
        // breathe inside a narrow two-column card.
        'front' => [
            'header' => ['size' => '9px', 'weight' => '700', 'color' => 'primary'],
            'label' => ['size' => '7px', 'weight' => '400', 'color' => 'secondary'],
            'value' => ['size' => '10px', 'weight' => '600', 'color' => 'primary'],
            'employee_name' => ['size' => '12px', 'weight' => '700', 'color' => 'primary'],
            'employee_position' => ['size' => '9px', 'weight' => '600', 'color' => 'secondary'],
        ],
        // The back has no heading of its own; its notes lead the text column.
        'back' => [
            'label' => ['size' => '7px', 'weight' => '400', 'color' => 'back'],
            'value' => ['size' => '8px', 'weight' => '600', 'color' => 'back'],
            'footer' => ['size' => '7px', 'weight' => '400', 'color' => 'back'],
        ],
    ];

    public function __construct(
        public string $color,
        public string $fontSize,
        public string $fontWeight,
    ) {}

    /**
     * Build from stored JSON, falling back to the role default so a template
     * saved without this role keeps rendering unchanged.
     */
    public static function fromArray(?array $data, string $color, string $fontSize, string $fontWeight): self
    {
        $candidate = new self(
            (string) ($data['color'] ?? $color),
            (string) ($data['font_size'] ?? $fontSize),
            (string) ($data['font_weight'] ?? $fontWeight),
        );

        return new self(
            self::isHexColor($candidate->color) ? $candidate->color : $color,
            in_array($candidate->fontSize, self::SIZES, true) ? $candidate->fontSize : $fontSize,
            in_array($candidate->fontWeight, self::WEIGHTS, true) ? $candidate->fontWeight : $fontWeight,
        );
    }

    public static function isHexColor(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $value) === 1;
    }

    /** Font size as a unitless number, for SVG's font-size attribute. */
    public function fontSizePx(): float
    {
        return (float) rtrim($this->fontSize, 'px');
    }

    /** @return array{color: string, font_size: string, font_weight: string} */
    public function toArray(): array
    {
        return ['color' => $this->color, 'font_size' => $this->fontSize, 'font_weight' => $this->fontWeight];
    }
}
