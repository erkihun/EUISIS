const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [['year', 31_536_000], ['month', 2_592_000], ['day', 86_400], ['hour', 3_600], ['minute', 60], ['second', 1]];

/**
 * Parses epoch seconds, ISO strings and Laravel's "Y-m-d H:i:s" (read as local time) into a Date.
 * Returns null for missing or unparseable values instead of an Invalid Date.
 */
export function toDate(value: number | string | null | undefined): Date | null {
    if (value === null || value === undefined || value === '') return null;
    const date = typeof value === 'number' ? new Date(value * 1000) : new Date(/^\d{4}-\d{2}-\d{2} \d/.test(value) ? value.replace(' ', 'T') : value);
    return Number.isNaN(date.getTime()) ? null : date;
}

/** "3 hours ago" / "in 2 days" in the active UI locale; null when the value is missing. */
export function formatRelative(value: number | string | null | undefined, locale: string): string | null {
    const date = toDate(value);
    if (!date) return null;
    const seconds = (date.getTime() - Date.now()) / 1000;
    const [unit, size] = UNITS.find(([, length]) => Math.abs(seconds) >= length) ?? UNITS[UNITS.length - 1];
    try {
        return new Intl.RelativeTimeFormat(locale === 'am' ? 'am' : 'en', { numeric: 'auto' }).format(Math.round(seconds / size), unit);
    } catch {
        return date.toLocaleString();
    }
}
