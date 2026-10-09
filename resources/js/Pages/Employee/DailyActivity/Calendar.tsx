import PortalPage from '@/Components/employees/portal/PortalPage';
import { panelCls, secondaryBtn } from '@/Components/dailyActivity/helpers';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import {
    ethiopianMonthLength, ethiopianToGregorianIso, gregorianIsoToEthiopian,
} from '@/lib/calendar/ethiopianCalendar';
import { useLocale } from '@/hooks/useLocale';
import { Link, router } from '@inertiajs/react';
import { useEffect, type JSX } from 'react';

type Day = {
    date: string;
    status: string;
    is_required: boolean;
    log_id: string | null;
    is_late: boolean;
    items_count: number;
    holiday_name_en: string | null;
    holiday_name_am: string | null;
    can_register: boolean;
};

type Props = {
    has_employee: boolean;
    today: string;
    anchor: string;
    from: string;
    to: string;
    days: Day[];
    summary: Record<string, number> | null;
};

/*
 * One mark per state, always paired with colour and a text label, so the
 * grid reads the same in greyscale and to a screen reader.
 */
const MARK: Record<string, { symbol: string; cls: string }> = {
    submitted: { symbol: '✓', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900' },
    approved: { symbol: '✓', cls: 'bg-emerald-100 text-emerald-900 ring-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-200 dark:ring-emerald-800' },
    missing: { symbol: '!', cls: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900' },
    returned: { symbol: '↩', cls: 'bg-amber-50 text-amber-900 ring-amber-300 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900' },
    draft: { symbol: '…', cls: 'bg-gray-50 text-gray-700 ring-gray-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600' },
    required: { symbol: '•', cls: 'bg-[color:var(--color-primary-50)] text-[color:var(--color-primary-800)] ring-[color:var(--color-primary-300)] dark:bg-[color:var(--color-primary-950)] dark:text-[color:var(--color-primary-200)] dark:ring-[color:var(--color-primary-800)]' },
    leave: { symbol: 'L', cls: 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900' },
    public_holiday: { symbol: 'H', cls: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-950/40 dark:text-violet-300 dark:ring-violet-900' },
    weekend: { symbol: '–', cls: 'bg-transparent text-gray-400 ring-gray-100 dark:text-slate-600 dark:ring-slate-800' },
    future: { symbol: '', cls: 'bg-transparent text-gray-400 ring-gray-100 dark:text-slate-600 dark:ring-slate-800' },
    not_tracked: { symbol: '', cls: 'bg-transparent text-gray-400 ring-gray-100 dark:text-slate-600 dark:ring-slate-800' },
    not_employed: { symbol: '', cls: 'bg-transparent text-gray-300 ring-gray-100 dark:text-slate-700 dark:ring-slate-800' },
    not_assigned: { symbol: '', cls: 'bg-transparent text-gray-300 ring-gray-100 dark:text-slate-700 dark:ring-slate-800' },
};

function isoAddDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

/** Gregorian ISO range of the month containing `iso`, in the active calendar. */
function monthRange(iso: string, ethiopian: boolean): { from: string; to: string } {
    if (ethiopian) {
        const eth = gregorianIsoToEthiopian(iso);
        if (eth) {
            return {
                from: ethiopianToGregorianIso(eth.year, eth.month, 1) ?? iso,
                to: ethiopianToGregorianIso(eth.year, eth.month, ethiopianMonthLength(eth.year, eth.month)) ?? iso,
            };
        }
    }
    const [year, month] = iso.split('-').map(Number);
    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
    return { from: `${iso.slice(0, 7)}-01`, to: `${iso.slice(0, 7)}-${String(last).padStart(2, '0')}` };
}

/**
 * My Activity Calendar.
 *
 * English shows a Gregorian month; Amharic shows an Ethiopian month
 * (including the 5-6 day Pagume). Either way the server is asked for a
 * Gregorian ISO range and every cell links by Gregorian date, so switching
 * language changes only the grid, never which records are shown.
 */
export default function DailyActivityCalendar({ has_employee, today, anchor, from, to, days, summary }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const ethiopian = useCalendarSystem() === 'ethiopian';

    // Re-align the requested range to the active calendar's month.
    const expected = monthRange(anchor, ethiopian);
    useEffect(() => {
        if (expected.from !== from || expected.to !== to) {
            router.get(route('employee.daily-activity.calendar'), { ...expected, anchor }, { replace: true, preserveScroll: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ethiopian, anchor, from, to]);

    const go = (anchor: string) => router.get(route('employee.daily-activity.calendar'), { ...monthRange(anchor, ethiopian), anchor }, { preserveScroll: true });

    const eth = gregorianIsoToEthiopian(from);
    const title = ethiopian && eth
        ? `${t(`calendar.monthsEth.${eth.month}`)} ${eth.year}`
        : `${t(`calendar.months.${Number(from.slice(5, 7))}`)} ${from.slice(0, 4)}`;

    // Monday-first grid: pad the first week.
    const firstWeekday = (new Date(`${from}T00:00:00Z`).getUTCDay() + 6) % 7;
    const weekdayNames = (() => {
        const short = [0, 1, 2, 3, 4, 5, 6].map((i) => t(`calendar.weekdays.short.${i}`));
        return [...short.slice(1), short[0]]; // Mon … Sun
    })();

    const dayNumber = (iso: string): number => {
        if (ethiopian) return gregorianIsoToEthiopian(iso)?.day ?? Number(iso.slice(8));
        return Number(iso.slice(8));
    };

    if (!has_employee) {
        return (
            <PortalPage title={t('dailyActivities.calendar.title')}>
                <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{t('dailyActivities.noEmployee')}</p>
            </PortalPage>
        );
    }

    return (
        <PortalPage
            title={t('dailyActivities.calendar.title')}
            actions={
                <div className="flex items-center gap-2">
                    <button type="button" onClick={() => go(isoAddDays(from, -1))} className={`${secondaryBtn} min-h-9 px-3 py-1.5`} aria-label={t('dailyActivities.actions.previous')}>‹</button>
                    <span className="min-w-36 text-center text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</span>
                    <button type="button" onClick={() => go(isoAddDays(to, 1))} disabled={to >= today} className={`${secondaryBtn} min-h-9 px-3 py-1.5`} aria-label={t('dailyActivities.actions.next')}>›</button>
                </div>
            }
        >
            <div className="space-y-3">

                {summary && (
                    <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-slate-400">
                        {(['required', 'submitted', 'approved', 'returned', 'missing', 'leave', 'holiday'] as const).map((key) => (
                            <span key={key}>
                                {t(`dailyActivities.summary.${key}`)}{' '}
                                <strong className={key === 'missing' && summary[key] > 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-900 dark:text-slate-100'}>{summary[key] ?? 0}</strong>
                            </span>
                        ))}
                    </p>
                )}

                <div className={`${panelCls} p-2 sm:p-3`}>
                    <div className="grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-gray-500 sm:text-xs dark:text-slate-400">
                        {weekdayNames.map((name, index) => <div key={index} className="py-1">{name}</div>)}
                    </div>
                    <div className="grid grid-cols-7 gap-1">
                        {Array.from({ length: firstWeekday }).map((_, index) => <div key={`pad-${index}`} />)}
                        {days.map((day) => {
                            const mark = MARK[day.status] ?? MARK.future;
                            const label = t(`dailyActivities.dayStatuses.${day.status}`);
                            const holiday = locale === 'am' ? day.holiday_name_am || day.holiday_name_en : day.holiday_name_en;
                            const content = (
                                <>
                                    <span className="text-[11px] leading-none sm:text-xs">{dayNumber(day.date)}</span>
                                    <span className="text-base font-semibold leading-none sm:text-lg" aria-hidden="true">{mark.symbol}</span>
                                </>
                            );
                            const cellCls = [
                                // Fixed height, not square: at full width square cells would be huge.
                                'flex h-12 flex-col items-center justify-between rounded-control p-1 ring-1 ring-inset sm:h-16',
                                mark.cls,
                                day.date === today ? 'outline outline-2 outline-offset-1 outline-[color:var(--color-primary)]' : '',
                            ].join(' ');
                            const title = [label, holiday, day.is_late ? t('dailyActivities.summary.late') : '', day.items_count ? `${day.items_count} ${t('dailyActivities.summary.items')}` : '']
                                .filter(Boolean).join(' · ');

                            return day.can_register ? (
                                <Link key={day.date} href={route('employee.daily-activity.entry', { date: day.date })} className={`${cellCls} hover:brightness-95`} title={title} aria-label={title}>
                                    {content}
                                </Link>
                            ) : (
                                <div key={day.date} className={cellCls} title={title} aria-label={title}>{content}</div>
                            );
                        })}
                    </div>
                </div>

                <section aria-label={t('dailyActivities.calendar.legend')}>
                    <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-gray-600 dark:text-slate-400">
                        {([
                            ['submitted', 'legendSubmitted'],
                            ['missing', 'legendMissing'],
                            ['returned', 'legendReturned'],
                            ['draft', 'legendDraft'],
                            ['required', 'legendDue'],
                            ['leave', 'legendLeave'],
                            ['public_holiday', 'legendHoliday'],
                            ['weekend', 'legendNonWorking'],
                        ] as const).map(([status, key]) => (
                            <li key={status} className="flex items-center gap-1.5">
                                <span className={`inline-flex h-5 w-5 items-center justify-center rounded-control text-xs font-semibold ring-1 ring-inset ${MARK[status].cls}`} aria-hidden="true">{MARK[status].symbol}</span>
                                {t(`dailyActivities.calendar.${key}`)}
                            </li>
                        ))}
                    </ul>
                </section>

                <p className="text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.notAttendance')}</p>
            </div>
        </PortalPage>
    );
}
