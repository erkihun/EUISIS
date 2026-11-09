<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Per-template content for the front header band.
 *
 * Each text field is an override: an empty value falls back to the global
 * system setting, so a template that stores nothing renders exactly as it did
 * before headers became template-managed.
 */
final readonly class IdCardHeaderContent
{
    /**
     * Editable text fields, with the system setting each one falls back to.
     *
     * The renderer reads both languages at once, the same way the card's field
     * labels do, so every line has an Amharic and an English form.
     *
     * @var array<string, array{setting: string, max: int}>
     */
    public const FIELDS = [
        'city_name_en' => ['setting' => 'city_name_en', 'max' => 120],
        'city_name_am' => ['setting' => 'city_name_am', 'max' => 120],
        'bureau_name_en' => ['setting' => 'bureau_name_en', 'max' => 160],
        'bureau_name_am' => ['setting' => 'bureau_name_am', 'max' => 160],
    ];

    /** Initials shown when a template has no logo and none can be resolved. */
    public const FALLBACK_INITIALS = 'AA';

    /**
     * The front header carries two logo slots, each managed on its own: the
     * primary (city / issuing authority) and the secondary (organization).
     */
    public const LOGO_SLOTS = ['primary', 'secondary'];

    public function __construct(
        public string $cityNameEn,
        public string $cityNameAm,
        public string $bureauNameEn,
        public string $bureauNameAm,
        public bool $showLogo,
        public ?string $logoDataUri,
        public bool $showSecondaryLogo = true,
        public ?string $secondaryLogoDataUri = null,
    ) {}

    /**
     * Build from stored JSON, falling back to the supplied global values for
     * any field the template leaves blank.
     *
     * @param  array<string, string>  $fallbacks  Keyed by the FIELDS key.
     */
    public static function fromArray(
        ?array $data,
        array $fallbacks,
        bool $showLogo,
        ?string $logoDataUri,
        ?string $secondaryLogoDataUri = null,
    ): self {
        $value = static function (string $key) use ($data, $fallbacks): string {
            $candidate = trim((string) ($data[$key] ?? ''));
            $max = self::FIELDS[$key]['max'];

            return $candidate === ''
                ? (string) ($fallbacks[$key] ?? '')
                : mb_substr($candidate, 0, $max);
        };

        return new self(
            $value('city_name_en'),
            $value('city_name_am'),
            $value('bureau_name_en'),
            $value('bureau_name_am'),
            // A template may hide either logo even when one is available.
            ! isset($data['show_logo']) || (bool) $data['show_logo'],
            $showLogo ? $logoDataUri : null,
            ! isset($data['show_secondary_logo']) || (bool) $data['show_secondary_logo'],
            $showLogo ? $secondaryLogoDataUri : null,
        );
    }

    /** Stored shape, for the template editor. */
    public function toArray(): array
    {
        return [
            'city_name_en' => $this->cityNameEn,
            'city_name_am' => $this->cityNameAm,
            'bureau_name_en' => $this->bureauNameEn,
            'bureau_name_am' => $this->bureauNameAm,
            'show_logo' => $this->showLogo,
            'show_secondary_logo' => $this->showSecondaryLogo,
        ];
    }
}
