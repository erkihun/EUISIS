/**
 * The product's name, written once for the frontend (docs/branding.md).
 * Components read it through useBranding(); translations write `{brand}` and
 * useLocale().t fills it in. Nothing else spells the name out.
 */

/** The safe branding shape shared by HandleInertiaRequests (BrandingService::toArray). */
export type Branding = {
    /** In the language the server rendered the page in; prefer brandNameFor(). */
    brandName: string;
    brandNameEn: string;
    brandNameAm: string;
    shortName: string;
    officialNameEn: string;
    officialNameAm: string;
    logoUrl: string | null;
    faviconUrl: string | null;
};

/** Boot fallbacks, mirroring config/branding.php; used only when the server sent nothing. */
export const BRANDING_FALLBACK: Branding = {
    brandName: 'Agelgay',
    brandNameEn: 'Agelgay',
    brandNameAm: 'አገልጋይ',
    shortName: 'AA-IEMSS',
    officialNameEn: 'Addis Ababa Integrated Employee Management & Service System',
    officialNameAm: 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት',
    logoUrl: null,
    faviconUrl: null,
};

const text = (value: unknown): string | undefined => (typeof value === 'string' ? value.trim() : undefined);

/**
 * The brand in a language: the Ge'ez-script name on Amharic pages, the Latin
 * one elsewhere. Live settings (System Settings › General, including the
 * settings preview) win over the shared prop, which wins over the fallback;
 * a blank value counts as unset, as everywhere in System Settings.
 */
export function brandNameFor(
    locale: string,
    settings: Record<string, unknown> = {},
    shared: Partial<Branding> = {},
): string {
    if (locale === 'am') {
        return text(settings['app.name_am']) || text(shared.brandNameAm) || BRANDING_FALLBACK.brandNameAm;
    }

    return text(settings['app.name']) || text(shared.brandNameEn) || BRANDING_FALLBACK.brandNameEn;
}
