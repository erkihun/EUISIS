<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Storage;

/**
 * The one place the product's identity is resolved (docs/branding.md).
 *
 * Priority: System Settings (group `general`, shared cache, no restart
 * needed) → config/branding.php (env fallbacks so the app boots before the
 * settings table exists). A blank setting counts as unset. Institution
 * branding (name, logo, colours of the employee's organization) is a
 * different thing and is deliberately not resolved here.
 */
final class BrandingService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    /** Compact brand: browser title, sign-in pages, navigation, sender labels. */
    public function brandName(): string
    {
        return $this->setting('application_name', (string) config('branding.name', 'Addis One'));
    }

    /** Official short identifier: sidebar secondary line, formal references, file names. */
    public function shortName(): string
    {
        return $this->setting('application_short_name', (string) config('branding.short_name', 'AA-IEMSS'));
    }

    public function officialNameEn(): string
    {
        return $this->setting('official_name_en', (string) config('branding.official_name_en'));
    }

    public function officialNameAm(): string
    {
        return $this->setting('official_name_am', (string) config('branding.official_name_am'));
    }

    /** The official name in the given locale (current locale when null), falling back to English. */
    public function officialName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'am' ? ($this->officialNameAm() ?: $this->officialNameEn()) : $this->officialNameEn();
    }

    /** "Official name (AA-IEMSS)" for document headers and footers. */
    public function formalLine(?string $locale = null): string
    {
        return $this->officialName($locale).' ('.$this->shortName().')';
    }

    public function logoUrl(): ?string
    {
        return $this->assetUrl($this->settings->get(SystemSettingsRegistry::GROUP_GENERAL, 'identity_system_logo'));
    }

    public function faviconUrl(): ?string
    {
        return $this->assetUrl($this->settings->get(SystemSettingsRegistry::GROUP_GENERAL, 'favicon'));
    }

    /** @return list<string> former names, for migration and readiness reporting only */
    public function legacyNames(): array
    {
        return array_values(array_filter((array) config('branding.legacy_names', []), 'is_string'));
    }

    /** True when the value is one of the former product names (case-insensitive, trimmed). */
    public function isLegacyName(?string $value): bool
    {
        $needle = mb_strtolower(trim((string) $value));

        return $needle !== '' && in_array($needle, array_map(fn (string $n): string => mb_strtolower($n), $this->legacyNames()), true);
    }

    /**
     * The safe, public shape shared with every page.
     *
     * @return array{brandName: string, shortName: string, officialNameEn: string, officialNameAm: string, logoUrl: ?string, faviconUrl: ?string}
     */
    public function toArray(): array
    {
        return [
            'brandName' => $this->brandName(),
            'shortName' => $this->shortName(),
            'officialNameEn' => $this->officialNameEn(),
            'officialNameAm' => $this->officialNameAm(),
            'logoUrl' => $this->logoUrl(),
            'faviconUrl' => $this->faviconUrl(),
        ];
    }

    private function setting(string $key, string $fallback): string
    {
        $value = $this->settings->get(SystemSettingsRegistry::GROUP_GENERAL, $key);
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : $fallback;
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
