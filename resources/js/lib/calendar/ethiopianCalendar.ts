/**
 * Ethiopian Calendar (Amete Mihret) ↔ Gregorian conversion.
 *
 * Algorithm: Julian Day Number (JDN).
 * Epoch: 1 Meskerem 1 = JDN 1724221 (August 29, 8 CE Julian).
 *
 * The leap day (Pagume 6) ends every Ethiopian year Y with Y mod 4 = 3, so the
 * following year starts on 12 September (the Gregorian year before a leap year).
 *
 * Verified vectors:
 *   Sept 12 2023 Greg  =  Meskerem 1,  2016 Eth  (Pagume 6, 2015 = Sept 11 2023)
 *   Sept 11 2026 Greg  =  Meskerem 1,  2019 Eth
 *   Apr 12 1988 Greg   =  Miyazia 4,   1980 Eth
 */

const ETHIOPIAN_EPOCH = 1724221;

// ─── Gregorian ↔ JDN ────────────────────────────────────────────────────────

export function gregorianToJdn(year: number, month: number, day: number): number {
    const a = Math.trunc((14 - month) / 12);
    const y = year + 4800 - a;
    const m = month + 12 * a - 3;
    return (
        day +
        Math.trunc((153 * m + 2) / 5) +
        365 * y +
        Math.trunc(y / 4) -
        Math.trunc(y / 100) +
        Math.trunc(y / 400) -
        32045
    );
}

export function jdnToGregorian(jdn: number): { year: number; month: number; day: number } {
    const a = jdn + 32044;
    const b = Math.trunc((4 * a + 3) / 146097);
    const c = a - Math.trunc((146097 * b) / 4);
    const d = Math.trunc((4 * c + 3) / 1461);
    const e = c - Math.trunc((1461 * d) / 4);
    const m = Math.trunc((5 * e + 2) / 153);
    return {
        year: 100 * b + d - 4800 + Math.trunc(m / 10),
        month: m + 3 - 12 * Math.trunc(m / 10),
        day: e - Math.trunc((153 * m + 2) / 5) + 1,
    };
}

// ─── Ethiopian ↔ JDN ────────────────────────────────────────────────────────

export function jdnToEthiopian(jdn: number): { year: number; month: number; day: number } {
    // Standard Amete Mihret arithmetic: a 1461-day cycle whose fourth year (Y mod 4 = 3) has Pagume 6.
    const offset = ETHIOPIAN_EPOCH - 365;
    const days = jdn - offset;
    const r = ((days % 1461) + 1461) % 1461;
    const n = (r % 365) + 365 * Math.trunc(r / 1460);

    return {
        year: 4 * Math.floor(days / 1461) + Math.trunc(r / 365) - Math.trunc(r / 1460),
        month: Math.trunc(n / 30) + 1,
        day: (n % 30) + 1,
    };
}

export function ethiopianToJdn(year: number, month: number, day: number): number {
    return ETHIOPIAN_EPOCH + 365 * (year - 1) + Math.floor(year / 4) + 30 * (month - 1) + (day - 1);
}

// ─── Public conversions ──────────────────────────────────────────────────────

export function gregorianToEthiopian(year: number, month: number, day: number): { year: number; month: number; day: number } {
    return jdnToEthiopian(gregorianToJdn(year, month, day));
}

export function ethiopianToGregorian(year: number, month: number, day: number): { year: number; month: number; day: number } {
    return jdnToGregorian(ethiopianToJdn(year, month, day));
}

// ─── Validation ──────────────────────────────────────────────────────────────

export function isEthiopianLeapYear(year: number): boolean {
    return year % 4 === 3;
}

export function ethiopianMonthLength(year: number, month: number): number {
    if (month <= 12) return 30;
    return isEthiopianLeapYear(year) ? 6 : 5;
}

export function isValidEthiopianDate(year: number, month: number, day: number): boolean {
    if (month < 1 || month > 13) return false;
    if (day < 1) return false;
    return day <= ethiopianMonthLength(year, month);
}

// ─── ISO string helpers ──────────────────────────────────────────────────────

/** Parse a Gregorian ISO string "YYYY-MM-DD" to Ethiopian components. */
export function gregorianIsoToEthiopian(iso: string): { year: number; month: number; day: number } | null {
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return null;
    return gregorianToEthiopian(Number(m[1]), Number(m[2]), Number(m[3]));
}

/** Convert Ethiopian components to a Gregorian ISO string "YYYY-MM-DD". */
export function ethiopianToGregorianIso(year: number, month: number, day: number): string | null {
    if (!isValidEthiopianDate(year, month, day)) return null;
    const g = ethiopianToGregorian(year, month, day);
    return `${g.year.toString().padStart(4, '0')}-${String(g.month).padStart(2, '0')}-${String(g.day).padStart(2, '0')}`;
}

/** Convert a Gregorian ISO string to another Gregorian ISO string (identity, validates format). */
export function normalizeGregorianIso(iso: string): string | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return null;
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    if (isNaN(date.getTime())) return null;
    if (date.getUTCFullYear() !== y || date.getUTCMonth() + 1 !== m || date.getUTCDate() !== d) return null;
    return iso;
}
