/** A date as YYYY-MM-DD, or a dash. */
export function formatDate(value: string | null | undefined): string {
    return value ? value.slice(0, 10) : '—';
}

/** A timestamp as YYYY-MM-DD HH:MM in the viewer's time zone, or a dash. */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function formatNumber(value: number): string {
    return value.toLocaleString();
}

/** The Amharic name when the page is Amharic and one exists, else the English one. */
export function localized(locale: string, english: string | null | undefined, amharic: string | null | undefined): string {
    return (locale === 'am' ? amharic || english : english || amharic) ?? '—';
}
