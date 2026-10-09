/**
 * Locale-aware date formatting utilities.
 *
 * - Locale `am` + Ethiopian calendar → Ethiopian display format
 * - All other combinations → Gregorian ISO YYYY-MM-DD
 * - Database and form values are ALWAYS Gregorian ISO strings.
 */

import { gregorianToEthiopian, gregorianIsoToEthiopian } from './ethiopianCalendar';
import type { CalendarSystem } from './calendarSystem';

const ETH_MONTHS_AM = ['', 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
const ETH_MONTHS_EN = ['', 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

/** Format a Gregorian ISO date string for display. */
export function formatDateDisplay(
    gregorianIso: string | null | undefined,
    calendarSystem: CalendarSystem,
    locale: string,
): string {
    if (!gregorianIso) return '';

    // Extract bare date — handles both 'YYYY-MM-DD' and 'YYYY-MM-DDTHH:MM:SS.000Z'
    // (Laravel's 'date' cast serializes as a full ISO datetime)
    const datePart = gregorianIso.slice(0, 10);

    const eth = gregorianIsoToEthiopian(datePart);
    if (calendarSystem === 'ethiopian' && eth) {
        const months = locale === 'am' ? ETH_MONTHS_AM : ETH_MONTHS_EN;
        const monthName = months[eth.month] ?? String(eth.month);
        const suffix = locale === 'am' ? '' : ' E.C.';
        return `${monthName} ${eth.day}, ${eth.year}${suffix}`;
    }

    // Gregorian display: format as DD/MM/YYYY
    if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
        const [y, m, d] = datePart.split('-');
        return `${d}/${m}/${y}`;
    }

    return gregorianIso;
}

/** Format a Gregorian ISO datetime string for display. */
export function formatDateTimeDisplay(
    isoDateTime: string | null | undefined,
    calendarSystem: CalendarSystem,
    locale: string,
): string {
    if (!isoDateTime) return '';
    const datePart = isoDateTime.slice(0, 10);
    // Extract time portion — handle both "T" separator and space separator
    const sep = isoDateTime.indexOf('T') !== -1 ? 'T' : ' ';
    const afterDate = isoDateTime.indexOf(sep) !== -1 ? isoDateTime.slice(isoDateTime.indexOf(sep) + 1) : '';
    const timePart = afterDate ? afterDate.slice(0, 5) : '';
    const displayDate = formatDateDisplay(datePart, calendarSystem, locale);
    return timePart ? `${displayDate} ${timePart}` : displayDate;
}

/**
 * Parse a date input value to a Gregorian ISO string.
 * The frontend always submits Gregorian ISO dates, so this is essentially
 * a validation + normalization function.
 */
export function parseToGregorianIso(value: string | null | undefined): string {
    if (!value) return '';
    const trimmed = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return trimmed;
    // ISO datetime — strip time part
    const datePart = trimmed.split('T')[0];
    if (/^\d{4}-\d{2}-\d{2}$/.test(datePart)) return datePart;
    return '';
}

/** Convert a JS Date to Gregorian ISO string. */
export function dateToGregorianIso(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** Today as Gregorian ISO string. */
export function todayGregorianIso(): string {
    return dateToGregorianIso(new Date());
}

/** Parse Gregorian ISO to JS Date (UTC midnight). */
export function gregorianIsoToDate(iso: string): Date | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return null;
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    return isNaN(date.getTime()) ? null : date;
}

/** Get current Ethiopian date. */
export function todayEthiopian(): { year: number; month: number; day: number } {
    const now = new Date();
    return gregorianToEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate());
}
