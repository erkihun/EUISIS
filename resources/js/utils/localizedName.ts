/**
 * Returns the locale-aware display name for a bilingual entity.
 * When the locale is 'am' and nameAm is non-empty, returns nameAm.
 * Otherwise falls back to nameEn, then nameAm, then an empty string.
 */
export function localizedName(
    nameEn: string | null | undefined,
    nameAm: string | null | undefined,
    locale: string,
): string {
    if (locale === 'am' && nameAm) return nameAm;
    return nameEn ?? nameAm ?? '';
}
