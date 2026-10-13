import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import type { PageProps } from '@/types';

/** The safe branding shape shared by HandleInertiaRequests (BrandingService::toArray). */
export type Branding = {
    brandName: string;
    shortName: string;
    officialNameEn: string;
    officialNameAm: string;
    logoUrl: string | null;
    faviconUrl: string | null;
};

/**
 * The only place the product's name is written in the frontend. Everything
 * else reads it from here: System Settings (live, including the settings
 * preview) → the shared `branding` prop → these boot fallbacks, which mirror
 * config/branding.php.
 */
export const BRANDING_FALLBACK: Branding = {
    brandName: 'Addis One',
    shortName: 'AA-IEMSS',
    officialNameEn: 'Addis Ababa Integrated Employee Management & Service System',
    officialNameAm: 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት',
    logoUrl: null,
    faviconUrl: null,
};

export function useBranding() {
    const { props } = usePage<PageProps<{ branding?: Partial<Branding> }>>();
    const { getString } = useSystemSettings();
    const { locale } = useLocale();
    const shared = props.branding ?? {};

    const pick = (settingKey: string, propKey: keyof Branding): string =>
        getString(settingKey, (shared[propKey] as string | undefined) || (BRANDING_FALLBACK[propKey] as string));

    const brandName = pick('app.name', 'brandName');
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
        brandName,
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
