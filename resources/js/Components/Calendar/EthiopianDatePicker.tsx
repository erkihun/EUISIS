import { useState, useRef, useEffect, useCallback } from 'react';
import {
    gregorianIsoToEthiopian,
    ethiopianToGregorianIso,
    ethiopianMonthLength,
    isEthiopianLeapYear,
} from '@/lib/calendar/ethiopianCalendar';
import { todayEthiopian } from '@/lib/calendar/dateFormat';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

const MONTHS_AM = ['', 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
const MONTHS_EN = ['', 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];
const WEEKDAYS_AM = ['እሁ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓርብ', 'ቅዳ'];
const WEEKDAYS_EN = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

interface Props {
    value: string;
    onChange: (gregorianIso: string) => void;
    min?: string;
    max?: string;
    disabled?: boolean;
    className?: string;
    placeholder?: string;
    id?: string;
    name?: string;
}

export default function EthiopianDatePicker({
    value,
    onChange,
    min,
    max,
    disabled = false,
    className = '',
    placeholder,
    id,
    name,
}: Props) {
    const { locale } = useLocale();
    const { getString } = useSystemSettings();
    const months = locale === 'am' ? MONTHS_AM : MONTHS_EN;
    /*
     * `localization.first_day_of_week` rotates both the weekday header and the
     * leading blank cells together. The options are Sunday (0), Monday (1) and
     * Saturday (6); anything else falls back to Sunday, which is how the grid
     * was hard-coded before the setting was wired up.
     */
    const weekStart = (() => {
        const raw = Number(getString('localization.first_day_of_week', '1'));
        return [0, 1, 6].includes(raw) ? raw : 0;
    })();

    const baseWeekdays = locale === 'am' ? WEEKDAYS_AM : WEEKDAYS_EN;
    const weekdays = [...baseWeekdays.slice(weekStart), ...baseWeekdays.slice(0, weekStart)];
    const todayEth = todayEthiopian();

    const selectedEth = value ? gregorianIsoToEthiopian(value) : null;
    const minEth = min ? gregorianIsoToEthiopian(min) : null;
    const maxEth = max ? gregorianIsoToEthiopian(max) : null;

    const [viewYear, setViewYear] = useState(selectedEth?.year ?? todayEth.year);
    const [viewMonth, setViewMonth] = useState(selectedEth?.month ?? todayEth.month);
    const [open, setOpen] = useState(false);
    const [openUpward, setOpenUpward] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (value) {
            const eth = gregorianIsoToEthiopian(value);
            if (eth) { setViewYear(eth.year); setViewMonth(eth.month); }
        }
    }, [value]);

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    function prevMonth() {
        if (viewMonth === 1) { setViewYear(y => y - 1); setViewMonth(13); }
        else setViewMonth(m => m - 1);
    }

    function nextMonth() {
        if (viewMonth === 13) { setViewYear(y => y + 1); setViewMonth(1); }
        else setViewMonth(m => m + 1);
    }

    function toggleOpen() {
        if (open) { setOpen(false); return; }
        // Decide whether to open upward based on available space below the button
        if (containerRef.current) {
            const rect = containerRef.current.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            setOpenUpward(spaceBelow < 320);
        }
        setOpen(true);
    }

    function selectDay(day: number) {
        const iso = ethiopianToGregorianIso(viewYear, viewMonth, day);
        if (iso) { onChange(iso); setOpen(false); }
    }

    function isDayDisabled(day: number): boolean {
        const iso = ethiopianToGregorianIso(viewYear, viewMonth, day);
        if (!iso) return true;
        if (min && iso < min) return true;
        if (max && iso > max) return true;
        return false;
    }

    const daysInMonth = ethiopianMonthLength(viewYear, viewMonth);

    // Build grid: first day of month weekday
    // Ethiopian months always start from Monday-Sunday cycle based on JDN
    const firstDayJdn = (() => {
        // JDN of day 1 of the current view month
        const EPOCH = 1724221;
        return EPOCH + 1461 * Math.trunc((viewYear - 1) / 4) + 365 * ((viewYear - 1) % 4) + 30 * (viewMonth - 1);
    })();
    // JDN mod 7: JDN 0 = Monday, so Sun=6,Mon=0,Tue=1... or use standard: JDN % 7 where 0=Mon
    // Standard: (jdn + 1) % 7 gives Sun=0
    // (jdn + 1) % 7 gives Sun=0; shifting by weekStart re-bases it on the
    // configured first column so the offset matches the rotated header.
    const firstWeekday = (((firstDayJdn + 1) % 7) - weekStart + 7) % 7;

    const displayValue = selectedEth
        ? `${months[selectedEth.month]} ${selectedEth.day}, ${selectedEth.year}${locale === 'en' ? ' E.C.' : ''}`
        : '';

    return (
        <div ref={containerRef} className="relative inline-block w-full">
            <input type="hidden" name={name} value={value} />
            <button
                type="button"
                id={id}
                disabled={disabled}
                onClick={toggleOpen}
                className={`flex w-full items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'} ${className}`}
            >
                <span className={displayValue ? '' : 'text-gray-400'}>
                    {displayValue || placeholder || (locale === 'am' ? 'ቀን ይምረጡ' : 'Select date')}
                </span>
                <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </button>

            {open && (
                <div
                    className={`absolute z-50 rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800 ${openUpward ? 'bottom-full mb-1' : 'top-full mt-1'}`}
                    style={{ minWidth: '280px' }}
                >
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-gray-700">
                        <button type="button" onClick={prevMonth} className="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>
                        </button>
                        <span className="text-sm font-medium dark:text-gray-200">
                            {months[viewMonth]} {viewYear}{locale === 'en' ? ' E.C.' : ''}
                        </span>
                        <button type="button" onClick={nextMonth} className="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>

                    {/* Weekday headers */}
                    <div className="grid grid-cols-7 px-2 pt-2">
                        {weekdays.map((wd) => (
                            <div key={wd} className="text-center text-xs font-medium text-gray-400">{wd}</div>
                        ))}
                    </div>

                    {/* Day grid */}
                    <div className="grid grid-cols-7 gap-0.5 p-2">
                        {/* Leading empty cells */}
                        {Array.from({ length: firstWeekday }).map((_, i) => (
                            <div key={`e${i}`} />
                        ))}
                        {Array.from({ length: daysInMonth }).map((_, i) => {
                            const day = i + 1;
                            const iso = ethiopianToGregorianIso(viewYear, viewMonth, day) ?? '';
                            const isSelected = selectedEth?.year === viewYear && selectedEth?.month === viewMonth && selectedEth?.day === day;
                            const isToday = todayEth.year === viewYear && todayEth.month === viewMonth && todayEth.day === day;
                            const disabled2 = isDayDisabled(day);
                            return (
                                <button
                                    key={day}
                                    type="button"
                                    disabled={disabled2}
                                    onClick={() => selectDay(day)}
                                    className={`rounded py-1 text-center text-sm transition-colors
                                        ${isSelected ? 'bg-indigo-600 text-white' : ''}
                                        ${!isSelected && isToday ? 'border border-indigo-400 text-indigo-600 dark:text-indigo-400' : ''}
                                        ${!isSelected && !isToday && !disabled2 ? 'hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-gray-200' : ''}
                                        ${disabled2 ? 'cursor-not-allowed text-gray-300 dark:text-gray-600' : 'cursor-pointer'}
                                    `}
                                >
                                    {day}
                                </button>
                            );
                        })}
                    </div>

                    {/* Today / Clear */}
                    <div className="flex justify-between border-t border-gray-200 px-3 py-2 dark:border-gray-700">
                        <button
                            type="button"
                            onClick={() => {
                                const today = todayEthiopian();
                                const iso = ethiopianToGregorianIso(today.year, today.month, today.day);
                                if (!iso) return;
                                if (min && iso < min) return;
                                if (max && iso > max) return;
                                setViewYear(today.year);
                                setViewMonth(today.month);
                                onChange(iso);
                                setOpen(false);
                            }}
                            className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            {locale === 'am' ? 'ዛሬ' : 'Today'}
                        </button>
                        {value && (
                            <button
                                type="button"
                                onClick={() => { onChange(''); setOpen(false); }}
                                className="text-xs text-gray-500 hover:underline dark:text-gray-400"
                            >
                                {locale === 'am' ? 'አጥፋ' : 'Clear'}
                            </button>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
