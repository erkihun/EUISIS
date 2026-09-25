import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import {
    ethiopianToGregorianIso,
    ethiopianToJdn,
    ethiopianMonthLength,
    gregorianIsoToEthiopian,
} from '@/lib/calendar/ethiopianCalendar';
import type { PageProps } from '@/types';
import { SVGProps, useEffect, useMemo, useState } from 'react';

/* ── icons ──────────────────────────────────────────────────────────────── */
type IP = SVGProps<SVGSVGElement>;
const Ic = {
    Utensils: (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zM21 15v7"/></svg>,
    Layers:   (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>,
    ChevL:    (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}><polyline points="15 18 9 12 15 6"/></svg>,
    ChevR:    (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}><polyline points="9 18 15 12 9 6"/></svg>,
    Check:    (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" {...p}><polyline points="20 6 9 17 4 12"/></svg>,
    Sun:      (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>,
    Week:     (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>,
    Bus:      (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="4" y="3" width="16" height="15" rx="2"/><path d="M4 11h16M8 18v3M16 18v3"/><circle cx="8" cy="14.5" r="1"/><circle cx="16" cy="14.5" r="1"/></svg>,
    Receipt:  (p: IP) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>,
};

/* ── types ──────────────────────────────────────────────────────────────── */
type CafeteriaActivity = {
    type: 'cafeteria';
    daily:   { date: string; consumed: boolean; subsidy: number };
    weekly:  { week_start: string; week_end: string; days_consumed: number; days_total: number; days_remaining: number; subsidy_used: number; subsidy_remaining: number | null; daily_rate: number | null; balance: number; pending: number };
    monthly: { label: string; month: number; year: number; days_consumed: number; working_days: number; subsidy_used: number };
    yearly:  { year: number; days_consumed: number; subsidy_used: number };
    employee: { id: string; name: string | null; number: string | null };
    today: string;
    consumed: Record<string, number>;          // { "YYYY-MM-DD": subsidy }
    holidays: string[];                          // ["YYYY-MM-DD", ...]
    window_start: string;
    window_end: string;
    transactions: { date: string | null; time: string | null; subsidy: number; meal_amount: number; employee_pays: number; provider: string | null; status: string | null }[];
};
type ServiceActivity = {
    type: 'service';
    transactions: { date: string | null; time: string | null; service: string | null; service_am?: string | null; provider: string | null; amount: number | null; status: string | null }[];
};
/** Transport comes from the transport module: passes and boarding scans. */
type TransportActivity = {
    type: 'transport';
    passes: {
        id: string; status: string;
        route: string | null; route_am: string | null; route_code: string | null;
        origin: string | null; origin_am: string | null; destination: string | null; destination_am: string | null;
        provider: string | null; provider_am: string | null;
        valid_from: string | null; valid_until: string | null;
    }[];
    rides_this_month: number;
    transactions: { date: string | null; time: string | null; route: string | null; route_am: string | null; provider: string | null; provider_am: string | null; status: string; rejection_reason: string | null }[];
};
type Entitlement = {
    id: string; status: string;
    service: string | null; service_am: string | null; service_code: string | null;
    provider: string | null; quota_limit: number | null; quota_used: number | null;
    effective_from: string | null; effective_to: string | null;
    activity: CafeteriaActivity | ServiceActivity | TransportActivity | null;
};
type Props = PageProps & { entitlements: Entitlement[]; has_employee: boolean };

/* ── helpers ────────────────────────────────────────────────────────────── */
const STATUS_STYLE: Record<string, string> = {
    active:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    paused:    'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    revoked:   'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
    expired:   'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400',
    exhausted: 'bg-red-100 text-red-600 dark:bg-red-950/40 dark:text-red-400',
    suspended: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    accepted:  'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    rejected:  'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

/** A status word in the reader's language; unknown values fall back to the raw word. */
function useStatusLabel() {
    const { t } = useLocale();
    return (status: string | null | undefined): string => {
        if (!status) return '';
        const key = `employeePortal.statuses.${status}`;
        const label = t(key);
        return label && label !== key ? label : status.replace(/_/g, ' ');
    };
}

function StatusChip({ status }: { status: string | null | undefined }) {
    const label = useStatusLabel()(status);
    if (!status) return null;
    return <span className={`inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLE[status] ?? STATUS_STYLE.expired}`}>{label}</span>;
}

function fmt(n: number | null | undefined) {
    if (n == null) return '—';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function QuotaBar({ used, limit }: { used: number; limit: number }) {
    const pct   = Math.min(100, Math.round((used / limit) * 100));
    const color = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-amber-400' : 'bg-emerald-500';
    return (
        <div className="mt-2">
            <div className="mb-1 flex justify-between text-xs text-gray-400 dark:text-slate-500">
                <span>{used} / {limit} used</span><span>{pct}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                <div className={`h-full rounded-full transition-all ${color}`} style={{ width: `${pct}%` }} />
            </div>
        </div>
    );
}

/* ── calendar building (mirrors cafeteria/scan) ─────────────────────────── */
type CalCell = { day: number; iso: string } | null;

const ETH_MONTHS_AM = ['መስከረም','ጥቅምት','ኅዳር','ታኅሣሥ','ጥር','የካቲት','መጋቢት','ሚያዝያ','ግንቦት','ሰኔ','ሐምሌ','ነሐሴ','ጳጉሜ'];
const ETH_MONTHS_EN = ['Meskerem','Tikimt','Hidar','Tahsas','Tir','Yekatit','Megabit','Miyazia','Ginbot','Sene','Hamle','Nehase','Pagume'];

function isoOf(year: number, monthIdx0: number, day: number): string {
    return `${year}-${String(monthIdx0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** Build Sunday-based calendar cells for the given month, in the active system. */
function buildCells(year: number, month: number, isEthiopian: boolean): CalCell[] {
    if (isEthiopian) {
        const firstJdn = ethiopianToJdn(year, month, 1);
        const firstDow = (firstJdn + 1) % 7;             // 0 = Sunday
        const total    = ethiopianMonthLength(year, month);
        const cells: CalCell[] = Array(firstDow).fill(null);
        for (let d = 1; d <= total; d++) {
            cells.push({ day: d, iso: ethiopianToGregorianIso(year, month, d) ?? '' });
        }
        return cells;
    }
    const firstDow = new Date(year, month, 1).getDay();   // month is 0-based here
    const total    = new Date(year, month + 1, 0).getDate();
    const cells: CalCell[] = Array(firstDow).fill(null);
    for (let d = 1; d <= total; d++) cells.push({ day: d, iso: isoOf(year, month, d) });
    return cells;
}

function monthTitle(year: number, month: number, isEthiopian: boolean, isAmharic: boolean): string {
    if (isEthiopian) {
        const names = isAmharic ? ETH_MONTHS_AM : ETH_MONTHS_EN;
        return `${names[(month - 1) % 13]} ${year}${isAmharic ? ' ዓ.ም' : ''}`;
    }
    return new Intl.DateTimeFormat(isAmharic ? 'am-ET' : 'en', { month: 'long', year: 'numeric' })
        .format(new Date(year, month, 1));
}

function calendarViewFromToday(today: string, isEthiopian: boolean): { year: number; month: number } {
    if (isEthiopian) {
        const ethToday = gregorianIsoToEthiopian(today);

        if (ethToday) {
            return { year: ethToday.year, month: ethToday.month };
        }
    }

    const [year, month] = today.split('-').map(Number);

    return {
        year: Number.isFinite(year) ? year : new Date().getFullYear(),
        month: Number.isFinite(month) ? month - 1 : new Date().getMonth(),
    };
}

function calendarMonthSummary(a: CafeteriaActivity, isEthiopian: boolean): {
    label: string;
    month: number;
    year: number;
    daysConsumed: number;
    workingDays: number;
    subsidyUsed: number;
} {
    const view = calendarViewFromToday(a.today, isEthiopian);
    const holidays = new Set(a.holidays);
    const consumed = a.consumed ?? {};
    let daysConsumed = 0;
    let workingDays = 0;
    let subsidyUsed = 0;

    for (const cell of buildCells(view.year, view.month, isEthiopian)) {
        if (!cell) {
            continue;
        }

        const date = new Date(`${cell.iso}T00:00:00`);
        const day = date.getDay();
        const isWorkingDay = day !== 0 && day !== 6 && !holidays.has(cell.iso);
        const subsidy = consumed[cell.iso];

        if (isWorkingDay) {
            workingDays += 1;
        }

        if (subsidy !== undefined) {
            daysConsumed += 1;
            subsidyUsed += subsidy;
        }
    }

    return {
        label: a.monthly.label,
        month: view.month,
        year: view.year,
        daysConsumed,
        workingDays,
        subsidyUsed: Math.round(subsidyUsed * 100) / 100,
    };
}

/* ── month calendar ─────────────────────────────────────────────────────── */
function CafeteriaCalendar({ a }: { a: CafeteriaActivity }) {
    const { t } = useLocale();
    const { locale } = useLocale();
    const isAmharic   = locale === 'am';
    const isEthiopian = useCalendarSystem() === 'ethiopian';

    // Today in the active system → initial view month
    const initialView = useMemo(
        () => calendarViewFromToday(a.today, isEthiopian),
        [a.today, isEthiopian],
    );
    const [calYear, setCalYear] = useState(initialView.year);
    const [calMonth, setCalMonth] = useState(initialView.month); // eth:1-13, greg:0-11

    useEffect(() => {
        setCalYear(initialView.year);
        setCalMonth(initialView.month);
    }, [initialView]);

    const dayLabels = isAmharic
        ? ['እሁ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓር', 'ቅዳ']
        : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    const cells = buildCells(calYear, calMonth, isEthiopian);
    const holidaySet = new Set(a.holidays);
    const consumed = a.consumed ?? {};

    // Per-cell status from the flat Gregorian maps + client-side date logic
    function meta(iso: string) {
        const subsidy   = consumed[iso];
        const isConsumed = subsidy !== undefined;
        const dt        = new Date(iso + 'T00:00:00');
        const dow       = dt.getDay();
        const isWeekend = dow === 0 || dow === 6;
        const isHoliday = holidaySet.has(iso);
        const isToday   = iso === a.today;
        const isPast    = iso < a.today;
        const isAvailable = !isWeekend && !isHoliday && !isConsumed && !isPast;
        return { subsidy, isConsumed, isWeekend, isHoliday, isToday, isPast, isAvailable };
    }

    // In-view month stats
    let consumedCount = 0, subsidySum = 0, workingDays = 0;
    for (const c of cells) {
        if (!c) continue;
        const m = meta(c.iso);
        if (m.isConsumed) { consumedCount++; subsidySum += m.subsidy ?? 0; }
        if (!m.isWeekend && !m.isHoliday) workingDays++;
    }

    function dayCls(m: ReturnType<typeof meta>): string {
        if (m.isConsumed)  return 'bg-emerald-500 text-white rounded-card font-bold shadow-sm';
        if (m.isToday)     return 'ring-2 ring-[var(--color-primary)] ring-offset-1 dark:ring-offset-slate-900 font-bold text-[var(--color-primary)] rounded-card';
        if (m.isHoliday)   return 'bg-amber-50 text-amber-500 dark:bg-amber-950/30 dark:text-amber-400 rounded-card';
        if (m.isWeekend)   return 'text-gray-300 dark:text-slate-700';
        if (m.isAvailable) return 'portal-service-accent rounded-card hover:brightness-95';
        if (m.isPast)      return 'text-gray-300 dark:text-slate-700';
        return 'text-gray-500 dark:text-slate-400';
    }

    function prev() {
        if (isEthiopian) {
            if (calMonth === 1) { setCalYear(y => y - 1); setCalMonth(13); }
            else setCalMonth(m => m - 1);
        } else {
            if (calMonth === 0) { setCalYear(y => y - 1); setCalMonth(11); }
            else setCalMonth(m => m - 1);
        }
    }
    function next() {
        if (isEthiopian) {
            if (calMonth === 13) { setCalYear(y => y + 1); setCalMonth(1); }
            else setCalMonth(m => m + 1);
        } else {
            if (calMonth === 11) { setCalYear(y => y + 1); setCalMonth(0); }
            else setCalMonth(m => m + 1);
        }
    }

    // Group cells into weeks of 7
    const weeks: CalCell[][] = [];
    for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));

    return (
        <div>
            {/* Navigator */}
            <div className="mb-4 flex items-center justify-between">
                <button type="button" onClick={prev}
                    className="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800">
                    <Ic.ChevL className="h-4 w-4"/>
                </button>
                <span className="text-sm font-bold text-gray-900 dark:text-slate-100">
                    {monthTitle(calYear, calMonth, isEthiopian, isAmharic)}
                </span>
                <button type="button" onClick={next}
                    className="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800">
                    <Ic.ChevR className="h-4 w-4"/>
                </button>
            </div>

            {/* In-view month stats */}
            <div className="mb-4 flex flex-wrap justify-center gap-2 text-xs">
                <span className="flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1.5 font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <span className="h-2 w-2 rounded-sm bg-emerald-500" />{consumedCount} {t('cafeteria.consumedLabel')}
                </span>
                <span className="flex items-center gap-1.5 rounded-lg bg-gray-50 px-2.5 py-1.5 text-gray-600 dark:bg-slate-800 dark:text-slate-400">
                    {workingDays} {t('cafeteria.workingDaysLabel')}
                </span>
                <span className="portal-service-accent flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 font-semibold">
                    {fmt(subsidySum)} {t('cafeteria.etbUsed')}
                </span>
            </div>

            {/* Day-name header */}
            <div className="grid grid-cols-7 overflow-hidden rounded-t-card border border-b-0 border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/60">
                {dayLabels.map(n => (
                    <div key={n} className="py-2.5 text-center text-[10px] font-bold text-gray-400 dark:text-slate-500">{n}</div>
                ))}
            </div>

            {/* Week rows */}
            <div className="overflow-hidden rounded-b-card border border-gray-100 dark:border-slate-800">
                {weeks.map((week, wi) => (
                    <div key={wi} className="grid grid-cols-7 divide-x divide-gray-50 dark:divide-slate-800/50 [&:not(:first-child)]:border-t [&:not(:first-child)]:border-gray-50 dark:[&:not(:first-child)]:border-slate-800/50">
                        {Array.from({ length: 7 }).map((_, di) => {
                            const c = week[di] ?? null;
                            if (!c) return <div key={di} className="h-11 bg-gray-50/60 dark:bg-slate-900/50" />;
                            const m = meta(c.iso);
                            return (
                                <div key={di}
                                    title={m.isConsumed && m.subsidy ? `${fmt(m.subsidy)} ${t('employeePortal.currency')}` : m.isHoliday ? t('cafeteria.legendHoliday') : undefined}
                                    className="group relative flex h-11 items-center justify-center">
                                    <span className={`flex h-8 w-8 items-center justify-center text-sm transition-all ${dayCls(m)}`}>
                                        {m.isConsumed ? <Ic.Check className="h-3.5 w-3.5"/> : c.day}
                                    </span>
                                    {m.isConsumed && m.subsidy && (
                                        <span className="pointer-events-none absolute -top-8 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded bg-gray-900 px-1.5 py-0.5 text-[10px] text-white opacity-0 transition-opacity group-hover:opacity-100 dark:bg-slate-700">
                                            {fmt(m.subsidy)} {t('employeePortal.currency')}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ))}
            </div>

            {/* Legend */}
            <div className="mt-3 flex flex-wrap gap-3 text-[10px] text-gray-400 dark:text-slate-500">
                <span className="flex items-center gap-1.5"><span className="flex h-4 w-4 items-center justify-center rounded-sm bg-emerald-500"><Ic.Check className="h-2.5 w-2.5 text-white"/></span>{t('cafeteria.legendUsed')}</span>
                <span className="flex items-center gap-1.5"><span className="portal-service-accent h-4 w-4 rounded-sm border"/>{t('cafeteria.legendAvailable')}</span>
                <span className="flex items-center gap-1.5"><span className="h-4 w-4 rounded-sm bg-amber-50 dark:bg-amber-950/30"/>{t('cafeteria.legendHoliday')}</span>
                <span className="flex items-center gap-1.5"><span className="h-4 w-4 rounded-sm ring-2 ring-[var(--color-primary)]"/>{t('cafeteria.legendToday')}</span>
            </div>
        </div>
    );
}

/* ── stat card ───────────────────────────────────────────────────────────── */
function StatCard({ icon: I, period, primary, secondary, badge, colorKey }: {
    icon: (p: IP) => JSX.Element; period: string;
    primary: string; secondary: string; badge: string; colorKey: string;
}) {
    const badgeTone = colorKey === 'emerald'
        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'
        : colorKey === 'gray'
            ? 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400'
            : 'portal-service-accent';
    return (
        <div className="portal-panel rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-2 flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-[10px] font-semibold text-[color:var(--color-primary)]">
                    <I className="h-3.5 w-3.5"/>{period}
                </span>
                <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold ${badgeTone}`}>{badge}</span>
            </div>
            <p className="text-sm font-bold text-gray-900 dark:text-slate-100">{primary}</p>
            <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{secondary}</p>
        </div>
    );
}

/* ── cafeteria detail ────────────────────────────────────────────────────── */
function CafeteriaDetail({ a }: { a: CafeteriaActivity }) {
    const { locale, t } = useLocale();
    const isEthiopian = useCalendarSystem() === 'ethiopian';
    const monthSummary = calendarMonthSummary(a, isEthiopian);
    const monthBadge = monthTitle(monthSummary.year, monthSummary.month, isEthiopian, locale === 'am').split(' ')[0];

    return (
        <div className="space-y-5">

            {/* Stat cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard icon={Ic.Sun}  period={t('cafeteria.periodDaily')} colorKey={a.daily.consumed ? 'emerald' : 'gray'}
                    primary={a.daily.consumed ? t('cafeteria.usedToday') : t('cafeteria.notYetToday')}
                    secondary={a.daily.consumed ? `${fmt(a.daily.subsidy)} ${t('employeePortal.currency')}` : a.weekly.daily_rate ? `${fmt(a.weekly.daily_rate)} ${t('cafeteria.ratePerDay')}` : '—'}
                    badge={a.daily.consumed ? '✓' : '○'}
                />
                <StatCard icon={Ic.Week} period={t('cafeteria.periodWeekly')} colorKey="blue"
                    primary={`${a.weekly.days_consumed} / ${a.weekly.days_total} ${t('cafeteria.daysUnit')}`}
                    secondary={`${fmt(a.weekly.subsidy_used)} ${t('cafeteria.etbUsed')}`}
                    badge={`${a.weekly.days_remaining} ${t('cafeteria.daysLeft')}`}
                />
                <StatCard icon={Ic.Week} period={t('cafeteria.periodMonthly')} colorKey="purple"
                    primary={`${monthSummary.daysConsumed} / ${monthSummary.workingDays} ${t('cafeteria.daysUnit')}`}
                    secondary={`${fmt(monthSummary.subsidyUsed)} ${t('cafeteria.etbUsed')}`}
                    badge={monthBadge}
                />
                <StatCard icon={Ic.Receipt} period={t('cafeteria.periodYearly')} colorKey="orange"
                    primary={`${a.yearly.days_consumed} ${t('cafeteria.daysUnit')}`}
                    secondary={`${fmt(a.yearly.subsidy_used)} ${t('cafeteria.etbUsed')}`}
                    badge={String(a.yearly.year)}
                />
            </div>

            {/* Balance strip */}
            <div className="flex flex-wrap gap-3">
                <div className={`flex items-center gap-2 rounded-card border px-4 py-2 ${a.weekly.balance < 0 ? 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/20' : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/20'}`}>
                    <span className={`text-xs font-medium ${a.weekly.balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-300'}`}>
                        {t('cafeteria.balanceLabel')}: <strong>{fmt(a.weekly.balance)} {t('employeePortal.currency')}</strong>
                    </span>
                </div>
                {a.weekly.subsidy_remaining != null && (
                    <div className="portal-service-accent flex items-center gap-2 rounded-card border px-4 py-2">
                        <span className="text-xs font-medium">
                            {t('cafeteria.thisWeek')}: <strong>{fmt(a.weekly.subsidy_remaining)} {t('employeePortal.currency')}</strong> {t('cafeteria.remainingLabel')}
                        </span>
                    </div>
                )}
                {a.weekly.daily_rate != null && (
                    <div className="flex items-center gap-2 rounded-card border border-gray-200 bg-gray-50 px-4 py-2 dark:border-slate-700 dark:bg-slate-800/60">
                        <span className="text-xs font-medium text-gray-600 dark:text-slate-300">
                            {t('cafeteria.rateLabel')}: <strong>{fmt(a.weekly.daily_rate)} {t('cafeteria.ratePerDay')}</strong>
                        </span>
                    </div>
                )}
            </div>

            {/* Calendar + transactions */}
            <div className="grid gap-5 xl:grid-cols-[1fr_300px]">

                {/* Calendar panel */}
                <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <CafeteriaCalendar a={a} />
                </div>

                {/* Transactions panel */}
                <div className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-slate-800">
                        <Ic.Receipt className="h-4 w-4 text-gray-400"/>
                        <div className="min-w-0">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.myRecentActivity')}</h3>
                            <p className="truncate text-xs text-gray-400 dark:text-slate-500">
                                {a.employee.name ?? t('cafeteria.employeeActivityFallback')}
                                {a.employee.number && ` · ${a.employee.number}`}
                            </p>
                        </div>
                        {a.transactions.length > 0 && (
                            <span className="ml-auto rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                                {a.transactions.length}
                            </span>
                        )}
                    </div>
                    {a.transactions.length === 0
                        ? <p className="py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('cafeteria.noTransactionsYet')}</p>
                        : <div className="max-h-[440px] divide-y divide-gray-50 overflow-y-auto dark:divide-slate-800">
                            {a.transactions.map((tx, i) => (
                                <div key={i} className="flex items-start justify-between px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium text-gray-800 dark:text-slate-200">{tx.provider ?? t('cafeteria.moduleName')}</p>
                                        <p className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">{tx.date}{tx.time && ` · ${tx.time}`}</p>
                                        {tx.status && tx.status !== 'accepted' && (
                                            <p className="mt-0.5 text-[10px] capitalize text-amber-500">{tx.status}</p>
                                        )}
                                    </div>
                                    <div className="ml-2 shrink-0 text-right">
                                        <p className="text-xs font-semibold text-emerald-600 dark:text-emerald-400">-{fmt(tx.subsidy)} {t('employeePortal.currency')}</p>
                                        {tx.employee_pays > 0 && (
                                            <p className="text-[10px] text-gray-400">+{fmt(tx.employee_pays)} {t('cafeteria.paidLabel')}</p>
                                        )}
                                    </div>
                                </div>
                            ))}
                          </div>
                    }
                </div>
            </div>
        </div>
    );
}

/* ── generic service detail ─────────────────────────────────────────────── */
function ServiceDetail({ a }: { a: ServiceActivity }) {
    const { t, locale } = useLocale();
    if (!a.transactions.length)
        return <p className="py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('cafeteria.noTransactionsYet')}</p>;
    return (
        <div className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="divide-y divide-gray-50 dark:divide-slate-800">
                {a.transactions.map((tx, i) => (
                    <div key={i} className="flex items-center justify-between px-4 py-3 text-sm">
                        <div>
                            <p className="font-medium text-gray-800 dark:text-slate-200">{(locale === 'am' && tx.service_am) || tx.service || tx.provider || '—'}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={tx.date} />{tx.time && ` · ${tx.time}`}</p>
                        </div>
                        <div className="flex flex-col items-end gap-1 text-right text-xs">
                            {tx.amount != null && <p className="font-semibold text-gray-700 dark:text-slate-300">{fmt(tx.amount)} {t('employeePortal.currency')}</p>}
                            <StatusChip status={tx.status} />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ── transport detail ───────────────────────────────────────────────────── */
function TransportDetail({ a }: { a: TransportActivity }) {
    const { t, locale } = useLocale();
    const pick = (en: string | null, am: string | null) => (locale === 'am' && am ? am : en ?? am ?? '—');
    const panel = 'rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900';

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className={`${panel} p-4`}>
                    <p className="text-xs text-gray-500 dark:text-slate-400">{t('employeePortal.ridesThisMonth')}</p>
                    <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{a.rides_this_month}</p>
                </div>
                <div className={`${panel} p-4`}>
                    <p className="text-xs text-gray-500 dark:text-slate-400">{t('employeePortal.transportPass')}</p>
                    {a.passes[0] ? (
                        <div className="mt-1 flex items-center gap-2">
                            <p className="text-base font-semibold text-gray-900 dark:text-slate-100">{a.passes[0].route_code ?? pick(a.passes[0].route, a.passes[0].route_am)}</p>
                            <StatusChip status={a.passes[0].status} />
                        </div>
                    ) : (
                        <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noTransportPass')}</p>
                    )}
                </div>
            </div>

            {a.passes.length > 0 && (
                <section className={panel}>
                    <h3 className="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">{t('employeePortal.transportPasses')}</h3>
                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                        {a.passes.map((pass) => (
                            <li key={pass.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium text-gray-900 dark:text-slate-100">
                                        {pick(pass.route, pass.route_am)}{pass.route_code && <span className="ms-1 text-xs text-gray-500">({pass.route_code})</span>}
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">
                                        {pick(pass.origin, pass.origin_am)} → {pick(pass.destination, pass.destination_am)}
                                        {(pass.provider || pass.provider_am) && <> · {pick(pass.provider, pass.provider_am)}</>}
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">
                                        <LocalizedDateDisplay value={pass.valid_from} /> – <LocalizedDateDisplay value={pass.valid_until} />
                                    </p>
                                </div>
                                <StatusChip status={pass.status} />
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className={panel}>
                <h3 className="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">{t('employeePortal.recentRides')}</h3>
                {a.transactions.length === 0 ? (
                    <p className="px-4 py-6 text-center text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noRides')}</p>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                        {a.transactions.map((tx, i) => (
                            <li key={i} className="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-gray-800 dark:text-slate-200">{pick(tx.route, tx.route_am)}</p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={tx.date} />{tx.time && ` · ${tx.time}`}{(tx.provider || tx.provider_am) && <> · {pick(tx.provider, tx.provider_am)}</>}</p>
                                    {tx.rejection_reason && <p className="text-xs text-red-700 dark:text-red-400">{tx.rejection_reason}</p>}
                                </div>
                                <StatusChip status={tx.status} />
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

/* ── sidebar item ────────────────────────────────────────────────────────── */
function SideItem({ e, selected, onClick, useAmharic }: { e: Entitlement; selected: boolean; onClick: () => void; useAmharic: boolean }) {
    const isActive = e.status === 'active';
    const isCafe   = e.service_code === 'cafeteria';
    const isBus    = e.service_code === 'transport';
    const name     = (useAmharic ? e.service_am : null) ?? e.service ?? e.service_code ?? '—';
    return (
        <button type="button" onClick={isActive ? onClick : undefined}
            className={['flex w-full items-center gap-3 rounded-card px-3 py-3 text-left transition-all',
                isActive ? 'cursor-pointer' : 'cursor-default opacity-55',
                selected ? 'portal-service-accent ring-1 ring-inset ring-current' : isActive ? 'hover:bg-gray-50 dark:hover:bg-slate-800' : '',
            ].join(' ')}
        >
            <div className="portal-service-accent flex h-9 w-9 shrink-0 items-center justify-center rounded-card">
                {isCafe ? <Ic.Utensils className="h-4 w-4"/> : isBus ? <Ic.Bus className="h-4 w-4"/> : <Ic.Layers className="h-4 w-4"/>}
            </div>
            <div className="min-w-0 flex-1">
                <p className={`truncate text-sm font-medium ${selected ? 'text-[var(--color-primary)]' : 'text-gray-900 dark:text-slate-100'}`}>{name}</p>
                {e.provider && <p className="truncate text-xs text-gray-400">{e.provider}</p>}
            </div>
            <StatusChip status={e.status} />
        </button>
    );
}

/* ── page ────────────────────────────────────────────────────────────────── */
export default function MyEntitlements({ entitlements, has_employee }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const active   = entitlements.filter(e => e.status === 'active');
    const inactive = entitlements.filter(e => e.status !== 'active');
    const [selectedId, setSelectedId] = useState<string | null>(active[0]?.id ?? null);
    const selected = entitlements.find(e => e.id === selectedId) ?? null;

    return (
        <PortalPage title={t('employeePortal.myServices')} description={t('employeePortal.myServicesIntro')}>

            {!has_employee ? (
                <div className="rounded-panel border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-900/50 dark:bg-amber-950/20">
                    <p className="font-medium text-amber-800 dark:text-amber-300">{t('transfers.noEmployeeProfile')}</p>
                </div>
            ) : entitlements.length === 0 ? (
                <div className="rounded-panel border border-gray-200 bg-white p-12 text-center dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-gray-400">{t('entitlements.noEntitlements')}</p>
                </div>
            ) : (
                // Stacked on phones and tablets; list beside the details on wide screens.
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-5">

                    {/* Sidebar */}
                    <div className="w-full shrink-0 rounded-panel border border-gray-200 bg-white p-2 lg:sticky lg:top-6 lg:w-64 dark:border-slate-800 dark:bg-slate-900">
                        <p className="mb-1.5 px-2 text-[10px] font-semibold text-gray-400">{t('entitlements.title')}</p>
                        {active.map(e => (
                            <SideItem key={e.id} e={e} selected={selectedId === e.id} onClick={() => setSelectedId(e.id)} useAmharic={useAmharic} />
                        ))}
                        {inactive.length > 0 && (
                            <>
                                <div className="my-2 h-px bg-gray-100 dark:bg-slate-800" />
                                <p className="mb-1.5 px-2 text-[10px] font-semibold text-gray-300 dark:text-slate-600">{t('employeePortal.inactive')}</p>
                                {inactive.map(e => (
                                    <SideItem key={e.id} e={e} selected={false} onClick={() => {}} useAmharic={useAmharic} />
                                ))}
                            </>
                        )}
                    </div>

                    {/* Main */}
                    <div className="min-w-0 flex-1">
                        {selected ? (
                            <>
                                {/* Header */}
                                <div className="mb-5 flex flex-wrap items-center gap-3">
                                    <div className="portal-service-accent flex h-11 w-11 shrink-0 items-center justify-center rounded-card">
                                        {selected.service_code === 'cafeteria'
                                            ? <Ic.Utensils className="h-5 w-5"/>
                                            : selected.service_code === 'transport'
                                                ? <Ic.Bus className="h-5 w-5 text-[color:var(--color-primary)]"/>
                                                : <Ic.Layers className="h-5 w-5 text-[color:var(--color-primary)]"/>
                                        }
                                    </div>
                                    <div>
                                        <h2 className="text-lg font-bold text-gray-900 dark:text-slate-100">
                                            {(useAmharic ? selected.service_am : null) ?? selected.service ?? selected.service_code}
                                        </h2>
                                        {selected.provider && <p className="text-xs text-gray-400">{selected.provider}</p>}
                                    </div>
                                    {selected.quota_limit != null && (
                                        <div className="w-full sm:ms-auto sm:w-auto sm:min-w-[160px]">
                                            <QuotaBar used={selected.quota_used ?? 0} limit={selected.quota_limit} />
                                        </div>
                                    )}
                                </div>

                                {/* Activity */}
                                {selected.activity
                                    ? selected.activity.type === 'cafeteria'
                                        ? <CafeteriaDetail a={selected.activity as CafeteriaActivity} />
                                        : selected.activity.type === 'transport'
                                            ? <TransportDetail a={selected.activity as TransportActivity} />
                                            : <ServiceDetail a={selected.activity as ServiceActivity} />
                                    : <div className="rounded-panel border border-gray-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                                        <p className="text-sm text-gray-400">{t('cafeteria.activityUnavailable')}</p>
                                      </div>
                                }
                            </>
                        ) : (
                            <div className="rounded-panel border border-gray-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-900">
                                <p className="text-sm text-gray-400">{t('cafeteria.selectEntitlement')}</p>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </PortalPage>
    );
}
