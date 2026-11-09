import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { BRANDING_FALLBACK, brandNameFor, type Branding } from '@/lib/branding';
import type { PageProps } from '@/types';

export { BRANDING_FALLBACK, type Branding };

/**
 * The frontend reader of the product's identity: System Settings (live,
 * including the settings preview) → the shared `branding` prop → the boot
 * fallbacks in lib/branding.ts, which mirror config/branding.php.
 */
export function useBranding() {
    const { props } = usePage<PageProps<{ branding?: Partial<Branding> }>>();
    const { getString, settings } = useSystemSettings();
    const { locale } = useLocale();
    const shared = props.branding ?? {};

    const pick = (settingKey: string, propKey: keyof Branding): string =>
        getString(settingKey, (shared[propKey] as string | undefined) || (BRANDING_FALLBACK[propKey] as string));

    const brandNameEn = brandNameFor('en', settings, shared);
    const brandNameAm = brandNameFor('am', settings, shared);
    const brandName = locale === 'am' ? brandNameAm : brandNameEn;
    const shortName = pick('app.short_name', 'shortName');
    const officialNameEn = pick('app.official_name_en', 'officialNameEn');
    const officialNameAm = pick('app.official_name_am', 'officialNameAm') || officialNameEn;
    const logoUrl = getString('general.identity_system_logo_url', shared.logoUrl ?? '') || null;
    const faviconUrl = getString('general.favicon_url', shared.faviconUrl ?? '') || null;

    /** Resolves {brand}, {shortName}, {officialName}, {officialNameEn}, {officialNameAm} in a translated string. */
    const withBrand = (text: string): string => text
        .replace(/\{brand\}/g, brandName)
        .replace(/\{shortName\}/g, shortName)
        .replace(/\{officialNameEn\}/g, officialNameEn)
        .replace(/\{officialNameAm\}/g, officialNameAm)
        .replace(/\{officialName\}/g, locale === 'am' ? officialNameAm : officialNameEn);

    return {
        withBrand,
        /** The brand in the current language: አገልጋይ on Amharic pages, Agelgay elsewhere (by default). */
        brandName,
        brandNameEn,
        brandNameAm,
        shortName,
        officialNameEn,
        officialNameAm,
        /** The official name in the current locale. */
        officialName: locale === 'am' ? officialNameAm : officialNameEn,
        /** "Official name (AA-IEMSS)" for formal subtitles. */
        formalLine: `${locale === 'am' ? officialNameAm : officialNameEn} (${shortName})`,
        logoUrl,
        faviconUrl,
    };
}
