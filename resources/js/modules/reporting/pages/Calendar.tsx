import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { badge, mutedCls, panelCls, secondaryBtn, toLocalInput, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual, Labelled } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';

interface Entry {
    cycle_id: string;
    due_at: string;
    opens_at: string;
    report_type: Bilingual;
    period: Labelled;
    calendar_basis: 'ethiopian' | 'gregorian';
    expected: number;
    submitted: number;
}

interface Props {
    month: string;
    entries: Entry[];
}

function shift(month: string, by: number): string {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/**
 * Deadlines of one month, as a dated list (which reads the same on a phone
 * and to a screen reader). Dates are shown in both calendars; the calendar a
 * schedule recurs on is named beside each entry.
 */
export default function Calendar({ month, entries }: Props): JSX.Element {
    const { rt, name, label, moment, date } = useReporting();
    const days = new Map<string, Entry[]>();
    entries.forEach((entry) => {
        const day = toLocalInput(entry.due_at, false);
        days.set(day, [...(days.get(day) ?? []), entry]);
    });
    const go = (target: string) => router.get(route('reporting.calendar'), { month: target }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('calendar.title')} description={rt('calendar.description')} />}>
            <Head title={rt('calendar.title')} />
            <div className="space-y-4 pb-4">
                <div className={cx(panelCls, 'flex flex-wrap items-center justify-between gap-3 p-3')}>
                    <button type="button" className={secondaryBtn} onClick={() => go(shift(month, -1))}>{rt('calendar.previous')}</button>
                    <div className="text-center">
                        <label htmlFor="cal-month" className="sr-only">{rt('calendar.month')}</label>
                        <input id="cal-month" type="month" value={month} onChange={(e) => e.target.value && go(e.target.value)} className="rounded-control border border-(--app-border) bg-(--app-surface) px-3 py-1.5 text-sm" />
                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{date(`${month}-01T09:00:00Z`)} – {date(`${shift(month, 1)}-01T00:00:00Z`)}</p>
                    </div>
                    <button type="button" className={secondaryBtn} onClick={() => go(shift(month, 1))}>{rt('calendar.next')}</button>
                </div>

                {entries.length === 0 ? <EmptyState title={rt('calendar.emptyTitle')} description={rt('calendar.emptyDescription')} /> : (
                    <ol className="space-y-3">
                        {[...days.entries()].map(([day, list]) => (
                            <li key={day} className={cx(panelCls, 'p-4')}>
                                <h2 className="text-sm font-semibold">{date(`${day}T09:00:00Z`)}</h2>
                                <ul className="mt-2 divide-y divide-(--app-border)">
                                    {list.map((entry) => {
                                        const missing = entry.expected - entry.submitted;
                                        const passed = new Date(entry.due_at).getTime() < Date.now();

                                        return (
                                            <li key={`${entry.cycle_id}-${entry.due_at}`} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                                <div className="min-w-0">
                                                    <Link href={route('reporting.register', { cycle_id: entry.cycle_id })} className="text-sm font-medium text-primary hover:underline">{name(entry.report_type)} · {label(entry.period)}</Link>
                                                    <p className={cx(mutedCls, 'text-xs')}>{rt('calendar.dueAt')}: {moment(entry.due_at)} · {rt(`calendar.basis.${entry.calendar_basis}`)}</p>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={badge('neutral')}>{rt('calendar.submittedOf', { submitted: entry.submitted, expected: entry.expected })}</span>
                                                    {missing > 0 && <span className={badge(passed ? 'danger' : 'warning')}>{rt(passed ? 'calendar.missing' : 'calendar.outstanding', { count: missing })}</span>}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
