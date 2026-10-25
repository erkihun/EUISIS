/**
 * Ethiopian Calendar (Amete Mihret) ↔ Gregorian conversion.
 *
 * Algorithm: Julian Day Number (JDN).
 * Epoch: 1 Meskerem 1 = JDN 1724221 (August 29, 8 CE Julian).
 *
 * Verified vectors:
 *   Sept 11 2023 Greg  =  Meskerem 1,  2016 Eth
 *   Jan 7  2024 Greg   =  Tahsas   29, 2016 Eth
 *   May 26 2026 Greg   =  Ginbot   18, 2018 Eth
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
    let diff = jdn - ETHIOPIAN_EPOCH;
    let baseYear = 0;

    if (diff < 0) {
        const cycles = Math.ceil(-diff / 1461);
        diff += cycles * 1461;
        baseYear = -4 * cycles;
    }

    const cycle = Math.trunc(diff / 1461);
    const r = diff % 1461;
    const yearInCycle = Math.trunc(r / 365) - Math.trunc(r / 1460);
    const dayOfYear = r - yearInCycle * 365;

    return {
        year: 4 * cycle + yearInCycle + 1 + baseYear,
        month: Math.trunc(dayOfYear / 30) + 1,
        day: (dayOfYear % 30) + 1,
    };
}

export function ethiopianToJdn(year: number, month: number, day: number): number {
    return (
        ETHIOPIAN_EPOCH +
        1461 * Math.trunc((year - 1) / 4) +
        365 * ((year - 1) % 4) +
        30 * (month - 1) +
        (day - 1)
    );
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
