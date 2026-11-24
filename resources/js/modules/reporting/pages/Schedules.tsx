import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { When } from '@/modules/reporting/components/Badges';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import SpecialReportForm from '@/modules/reporting/components/SpecialReportForm';
import { badge, mutedCls, panelCls, primaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { Bilingual, Labelled } from '@/modules/reporting/types';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface ScheduleRow extends Bilingual {
    id: string;
    frequency: string;
    calendar_basis: string;
    is_active: boolean;
    due_time: string;
    report_type: Bilingual | null;
    targets: number;
    starts_on: string;
}

interface CycleRow extends Labelled {
    id: string;
    special: boolean;
    report_type: Bilingual;
    opens_at: string;
    due_at: string;
    obligations: number;
}

/** Schedules, and the reporting periods already published from them. */
export default function Schedules({ schedules, cycles, report_types }: { schedules: ScheduleRow[]; cycles: CycleRow[]; report_types: ({ id: string } & Bilingual)[] }): JSX.Element {
    const { rt, name, label, moment } = useReporting();

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('schedules.title')} description={rt('schedules.description')} actions={<Link href={route('reporting.schedules.create')} className={primaryBtn}>{rt('schedules.add')}</Link>} />}>
            <Head title={rt('schedules.title')} />
            <ActionErrors />
            <div className="space-y-5 pb-4">
                {schedules.length === 0 ? <EmptyState title={rt('schedules.emptyTitle')} description={rt('schedules.emptyDescription')} action={<Link href={route('reporting.schedules.create')} className={primaryBtn}>{rt('schedules.add')}</Link>} /> : (
                    <ul className="space-y-3">
                        {schedules.map((schedule) => (
                            <li key={schedule.id} className={cx(panelCls, 'flex flex-wrap items-center justify-between gap-3 p-4')}>
                                <div className="min-w-0">
                                    <Link href={route('reporting.schedules.edit', schedule.id)} className="text-base font-semibold text-primary hover:underline">{name(schedule)}</Link>
                                    <p className="text-sm">{name(schedule.report_type)}</p>
                                    <p className={cx(mutedCls, 'text-xs')}>{rt(`frequency.${schedule.frequency}`)} · {rt(`calendar.basis.${schedule.calendar_basis}`)} · {rt('schedules.dueAtTime', { time: schedule.due_time })}</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={badge('neutral')}>{rt('schedules.institutions', { count: schedule.targets })}</span>
                                    <span className={badge(schedule.is_active ? 'success' : 'neutral')}>{rt(schedule.is_active ? 'common.active' : 'common.inactive')}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <SpecialReportForm reportTypes={report_types} />

                <section className={cx(panelCls, 'p-4')} aria-labelledby="sc-cycles">
                    <h2 id="sc-cycles" className={sectionTitleCls}>{rt('schedules.publishedPeriods')}</h2>
                    <p className={cx(mutedCls, 'text-xs')}>{rt('schedules.publishedPeriodsHelp')}</p>
                    {cycles.length === 0 ? <p className={cx(mutedCls, 'mt-2')}>{rt('schedules.noPeriods')}</p> : (
                        <div className="mt-2 overflow-x-auto">
                            <table className="w-full min-w-[44rem] text-sm">
                                <thead>
                                    <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                        {['period', 'opens', 'due', 'expected'].map((column) => <th key={column} scope="col" className="px-2 py-2 text-start font-medium">{rt(`schedules.col.${column}`)}</th>)}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-(--app-border)">
                                    {cycles.map((cycle) => (
                                        <tr key={cycle.id}>
                                            <th scope="row" className="px-2 py-2 text-start font-normal">
                                                <Link href={route('reporting.register', { cycle_id: cycle.id })} className="font-medium text-primary hover:underline">{name(cycle.report_type)} · {label(cycle)}</Link>
                                                {cycle.special && <span className={cx(badge('info'), 'ms-2')}>{rt('schedules.special')}</span>}
                                            </th>
                                            <td className="px-2 py-2"><When iso={cycle.opens_at} /></td>
                                            <td className="px-2 py-2"><When iso={cycle.due_at} strong /></td>
                                            <td className="px-2 py-2 tabular-nums">{cycle.obligations}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
