/**
 * Formatting governed by Localization settings.
 *
 * These three settings existed in System Settings but nothing read them, so
 * changing them did nothing. The rules live here rather than at each call site
 * so a screen honours them by using the hook, not by remembering to.
 */

export type OrganizationNameDisplay = 'english' | 'amharic' | 'both';
export type EmployeeNameDisplay = 'full_name' | 'first_last';
export type NumberFormat = '1,234.56' | '1 234.56';

/**
 * Organization (and other bilingual entity) name.
 *
 * `english` / `amharic` pin the language regardless of the interface locale —
 * a bureau's registered English name may be the legally correct one to show
 * even to an Amharic-speaking clerk. `both` shows the active locale's name
 * with the other in parentheses, for bilingual documents and cross-checking.
 *
 * Falls back rather than showing nothing: an organization with no Amharic name
 * still renders its English one under `amharic`.
 */
export function organizationDisplayName(
    nameEn: string | null | undefined,
    nameAm: string | null | undefined,
    locale: string,
    mode: OrganizationNameDisplay = 'english',
): string {
    const en = nameEn?.trim() ?? '';
    const am = nameAm?.trim() ?? '';

    if (mode === 'amharic') return am || en;
    if (mode === 'english') return en || am;

    /* both — lead with the reader's own language. */
    const [primary, secondary] = locale === 'am' ? [am, en] : [en, am];
    if (!primary) return secondary;
    if (!secondary || primary === secondary) return primary;

    return `${primary} (${secondary})`;
}

export type EmployeeNameParts = {
    full_name?: string | null;
    first_name?: string | null;
    middle_name?: string | null;
    last_name?: string | null;
};

/**
 * Employee name.
 *
 * `full_name` is the stored name as registered, which in Ethiopian practice is
 * given name + father's name + grandfather's name. `first_last` drops the
 * middle element for compact table columns.
 *
 * Falls back to `full_name` whenever the parts are not loaded — several list
 * endpoints send only the composed name, and a blank cell would be worse than
 * an unabbreviated one.
 */
export function employeeDisplayName(
    employee: EmployeeNameParts,
    mode: EmployeeNameDisplay = 'full_name',
): string {
    const full = employee.full_name?.trim() ?? '';

    if (mode !== 'first_last') return full;

    const first = employee.first_name?.trim() ?? '';
    const last = employee.last_name?.trim() ?? '';
    const shortened = [first, last].filter(Boolean).join(' ');

    return shortened || full;
}

/**
 * Number grouping.
 *
 * `1 234.56` uses a non-breaking thin space as the thousands separator, the
 * SI/ISO convention some Ethiopian government publications follow. Formatting
 * goes through `en-US` first so the decimal point is stable, then the group
 * separator is swapped — deriving both from a locale would also change the
 * decimal mark, which neither option asks for.
 */
export function formatNumber(value: number | string | null | undefined, format: NumberFormat = '1,234.56'): string {
    if (value === null || value === undefined || value === '') return '';

    const numeric = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(numeric)) return String(value);

    const grouped = numeric.toLocaleString('en-US');

    return format === '1 234.56' ? grouped.replace(/,/g, ' ') : grouped;
}
