import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { todayEthiopian, todayGregorianIso } from '@/lib/calendar/dateFormat';
import {
    ethiopianMonthLength,
    ethiopianToGregorianIso,
    gregorianIsoToEthiopian,
} from '@/lib/calendar/ethiopianCalendar';
import { cx, Select } from '@/Components/ui';

const MONTHS_ET_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
const MONTHS_ET_EN = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

/** The oldest birth year offered, counted back from this year. */
const OLDEST_YEARS = 90;

type Parts = { year: string; month: string; day: string };

interface Props {
    /** Gregorian ISO date (YYYY-MM-DD) or ''. */
    value: string;
    onChange: (gregorianIso: string) => void;
    id?: string;
    invalid?: boolean;
    /** Show the resulting age under the fields (a date of birth); off for other past dates. */
    showAge?: boolean;
}

function gregorianDaysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function ageOn(birthIso: string, todayIso: string): number {
    const [by, bm, bd] = birthIso.split('-').map(Number);
    const [ty, tm, td] = todayIso.split('-').map(Number);
    return ty - by - (tm < bm || (tm === bm && td < bd) ? 1 : 0);
}

/**
 * Date of birth as three dropdowns (day, month, year) in the user's calendar:
 * Ethiopian on Amharic pages, Gregorian otherwise. A year is one pick instead
 * of paging a calendar back month by month. Stores a Gregorian ISO date and
 * shows the resulting age, with a gentle check when it looks unlikely for an
 * employee.
 */
export default function BirthDatePicker({ value, onChange, id, invalid = false, showAge = true }: Props) {
    const { t, locale } = useLocale();
    const ethiopian = useCalendarSystem() === 'ethiopian';
    const todayIso = todayGregorianIso();

    const toParts = (iso: string): Parts => {
        if (!iso) return { year: '', month: '', day: '' };
        if (ethiopian) {
            const eth = gregorianIsoToEthiopian(iso);
            return eth ? { year: String(eth.year), month: String(eth.month), day: String(eth.day) } : { year: '', month: '', day: '' };
        }
        const [y, m, d] = iso.split('-').map(Number);
        return { year: String(y), month: String(m), day: String(d) };
    };

    const [parts, setParts] = useState<Parts>(() => toParts(value));
    // The last value this picker sent: a half-filled selection sends '' and must survive it.
    const emitted = useRef(value);

    // Follow an outside change of value (a reset, a loaded record) or of calendar, not our own edits.
    useEffect(() => {
        if (value !== emitted.current) {
            emitted.current = value;
            setParts(toParts(value));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);
    useEffect(() => {
        setParts(toParts(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ethiopian]);

    const currentYear = ethiopian ? todayEthiopian().year : Number(todayIso.slice(0, 4));
    const years = useMemo(() => Array.from({ length: OLDEST_YEARS + 1 }, (_, i) => currentYear - i), [currentYear]);
    const monthNames = ethiopian
        ? (locale === 'am' ? MONTHS_ET_AM : MONTHS_ET_EN)
        : Array.from({ length: 12 }, (_, i) => new Date(Date.UTC(2000, i, 1)).toLocaleDateString(locale === 'am' ? 'am-ET' : 'en-US', { month: 'long', timeZone: 'UTC' }));

    const year = Number(parts.year);
    const month = Number(parts.month);
    const daysInMonth = parts.year && parts.month
        ? (ethiopian ? ethiopianMonthLength(year, month) : gregorianDaysInMonth(year, month))
        : (ethiopian ? 30 : 31);

    function update(next: Parts) {
        // A day the new month does not have (31 → a 30-day month, Pagume) moves to its last day.
        const y = Number(next.year);
        const m = Number(next.month);
        let d = Number(next.day);
        if (next.year && next.month && next.day) {
            const max = ethiopian ? ethiopianMonthLength(y, m) : gregorianDaysInMonth(y, m);
            if (d > max) {
                d = max;
                next = { ...next, day: String(max) };
            }
        }
        setParts(next);

        if (!next.year || !next.month || !next.day) {
            if (value !== '') {
                emitted.current = '';
                onChange('');
            }
            return;
        }
        const iso = ethiopian
            ? ethiopianToGregorianIso(y, m, d)
            : `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        emitted.current = iso ?? '';
        onChange(iso ?? '');
    }

    const age = value ? ageOn(value, todayIso) : null;
    const unlikely = age !== null && (age < 18 || age > 70);
    const selectCls = cx('w-full', invalid && 'border-red-500');

    return (
        <div>
            <div className="grid grid-cols-[1fr_1.6fr_1.2fr] gap-2">
                <Select
                    id={id}
                    aria-label={t('employees.birthDay')}
                    className={selectCls}
                    value={parts.day}
                    onChange={(e) => update({ ...parts, day: e.target.value })}
                >
                    <option value="">{t('employees.birthDay')}</option>
                    {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </Select>
                <Select
                    aria-label={t('employees.birthMonth')}
                    className={selectCls}
                    value={parts.month}
                    onChange={(e) => update({ ...parts, month: e.target.value })}
                >
                    <option value="">{t('employees.birthMonth')}</option>
                    {monthNames.map((name, i) => <option key={name} value={i + 1}>{name}</option>)}
                </Select>
                <Select
                    aria-label={t('employees.birthYear')}
                    className={selectCls}
                    value={parts.year}
                    onChange={(e) => update({ ...parts, year: e.target.value })}
                >
                    <option value="">{t('employees.birthYear')}</option>
                    {years.map((y) => <option key={y} value={y}>{y}{ethiopian && locale === 'en' ? ' E.C.' : ''}</option>)}
                </Select>
            </div>
            {showAge && age !== null && (
                <p className={cx('mt-1 text-xs', unlikely ? 'text-amber-700 dark:text-amber-300' : 'text-(--app-muted-foreground)')}>
                    {t('employees.ageYears').replace('{age}', String(age))}
                    {unlikely && ` · ${t('employees.ageCheck')}`}
                </p>
            )}
        </div>
    );
}
