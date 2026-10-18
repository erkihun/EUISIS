import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import type { CalendarSystem } from '@/lib/calendar/calendarSystem';

/** Replace :name placeholders in a translated string. */
export function tr(text: string, params: Record<string, string | number>): string {
    return Object.entries(params).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), text);
}

/** Clock time of a message, in the interface language. */
export function clock(iso: string | null, locale: string): string {
    if (!iso) return '';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en-GB', { hour: '2-digit', minute: '2-digit' }).format(date);
}

/** Local calendar day key (YYYY-MM-DD) used to group messages under day separators. */
export function dayKey(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** "Today", "Yesterday", or the date in the interface's calendar (Ethiopian in Amharic). */
export function dayLabel(iso: string | null, locale: string, system: CalendarSystem, today: string, yesterday: string): string {
    const key = dayKey(iso);
    const now = new Date();
    const todayKey = dayKey(now.toISOString());
    const y = new Date(now);
    y.setDate(now.getDate() - 1);
    if (key === todayKey) return today;
    if (key === dayKey(y.toISOString())) return yesterday;

    return formatDateDisplay(key, system, locale);
}

/** Short time for list rows: clock today, otherwise the date. */
export function listTime(iso: string | null, locale: string, system: CalendarSystem): string {
    if (!iso) return '';
    return dayKey(iso) === dayKey(new Date().toISOString()) ? clock(iso, locale) : formatDateDisplay(dayKey(iso), system, locale);
}

export function fileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Initials for an avatar (works for Ethiopic names: the first character of the first two words). */
export function initials(name: string | null | undefined): string {
    const words = (name ?? '').trim().split(/\s+/).filter(Boolean);

    return (words.slice(0, 2).map((w) => Array.from(w)[0] ?? '').join('') || '?').toUpperCase();
}
