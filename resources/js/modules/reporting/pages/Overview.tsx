import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Deadline, WorkflowBadge } from '@/modules/reporting/components/Badges';
import ComplianceSummaryPanel from '@/modules/reporting/components/ComplianceSummaryPanel';
import { PausedNotice } from '@/modules/reporting/components/Notices';
import { inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { ComplianceSummary, FilterOptions, Labelled, ObligationRow } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';

interface TrendPoint extends Labelled {
    cycle_id: string;
    due_at: string;
    due_required: number;
    submission_rate: number | null;
    on_time_rate: number | null;
    approval_rate: number | null;
}

interface Props {
    filters: Record<string, string>;
    options: FilterOptions;
    summary: ComplianceSummary;
    trend: TrendPoint[];
    role: { oversight: boolean; prepares: boolean; reviews: boolean };
    queues: { assignments_open: number; review_inbox: number; review_mine: number; extensions_pending: number };
    next_due: ObligationRow[];
    paused: boolean;
}

/**
 * One overview for every role. Leadership sees who reported, who did not and
 * who was late across the institutions in their scope; a preparer sees what
 * is due next; a reviewer sees what is waiting.
 */
export default function Overview({ filters, options, summary, trend, role, queues, next_due, paused }: Props): JSX.Element {
    const { rt, name, label, percent, date, subject } = useReporting();
    const historical = 'as_of' in filters;
    const apply = (key: string, value: string) => router.get(route('reporting.overview'), { ...filters, [key]: value || undefined }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('overview.title')} description={rt(role.oversight ? 'overview.descriptionOversight' : 'overview.description')}
            actions={<Link href={route('reporting.register', filters)} className={secondaryBtn}>{rt('nav.register')}</Link>} />}>
            <Head title={rt('overview.title')} />
            <PausedNotice paused={paused} />
            <div className="space-y-5 pb-4">
                {/* What needs me */}
                {(role.prepares || role.reviews) && (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {role.prepares && <QueueCard href={route('reporting.assignments')} value={queues.assignments_open} text={rt('overview.queueAssignments')} urgent={queues.assignments_open > 0} />}
                        {role.reviews && <QueueCard href={route('reporting.review.index', { mine: 1 })} value={queues.review_mine} text={rt('overview.queueReviewMine')} urgent={queues.review_mine > 0} />}
                        {role.reviews && <QueueCard href={route('reporting.review.index')} value={queues.review_inbox} text={rt('overview.queueReview')} />}
                        {queues.extensions_pending > 0 && <QueueCard href={route('reporting.review.index')} value={queues.extensions_pending} text={rt('overview.queueExtensions')} urgent />}
                    </div>
                )}

                <form className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-3')} aria-label={rt('filters.title')} onSubmit={(e) => e.preventDefault()}>
                    <div>
                        <label htmlFor="ov-type" className={labelCls}>{rt('filters.reportType')}</label>
                        <select id="ov-type" className={inputCls} value={filters.report_type_id ?? ''} onChange={(e) => apply('report_type_id', e.target.value)}>
                            <option value="">{rt('filters.allTypes')}</option>
                            {options.report_types.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="ov-cycle" className={labelCls}>{rt('filters.period')}</label>
                        <select id="ov-cycle" className={inputCls} value={filters.cycle_id ?? ''} onChange={(e) => apply('cycle_id', e.target.value)}>
                            <option value="">{rt('filters.allPeriods')}</option>
                            {options.cycles.filter((c) => !filters.report_type_id || c.report_type_id === filters.report_type_id).map((cycle) => <option key={cycle.id} value={cycle.id}>{label(cycle)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="ov-asof" className={labelCls}>{rt('filters.asOf')}</label>
                        <input id="ov-asof" type="date" className={inputCls} value={filters.as_of ?? ''} onChange={(e) => apply('as_of', e.target.value)} aria-describedby="ov-asof-help" />
                        <p id="ov-asof-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('filters.asOfHelp')}</p>
                    </div>
                </form>

                {summary.counts.expected === 0
                    ? <EmptyState title={rt('overview.emptyTitle')} description={rt('overview.emptyDescription')} />
                    : <ComplianceSummaryPanel summary={summary} filters={filters} historical={historical} />}

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="ov-trend">
                        <h2 id="ov-trend" className={sectionTitleCls}>{rt('overview.trendTitle')}</h2>
                        <p className={cx(mutedCls, 'text-xs')}>{rt('overview.trendHelp')}</p>
                        {trend.length === 0 ? <p className={cx(mutedCls, 'mt-3')}>{rt('overview.trendEmpty')}</p> : (
                            <div className="mt-3 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-start text-xs text-(--app-muted-foreground)">
                                            <th scope="col" className="py-1 pe-3 text-start font-medium">{rt('filters.period')}</th>
                                            <th scope="col" className="py-1 pe-3 text-end font-medium">{rt('summary.dueRequired')}</th>
                                            <th scope="col" className="py-1 pe-3 text-start font-medium">{rt('summary.submissionRate')}</th>
                                            <th scope="col" className="py-1 text-start font-medium">{rt('summary.onTimeRate')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-(--app-border)">
                                        {trend.map((point) => (
                                            <tr key={point.cycle_id}>
                                                <th scope="row" className="py-1.5 pe-3 text-start font-normal">
                                                    <Link href={route('reporting.register', { cycle_id: point.cycle_id })} className="text-primary hover:underline">{label(point)}</Link>
                                                    <span className={cx(mutedCls, 'block text-xs')}>{date(point.due_at)}</span>
                                                </th>
                                                <td className="py-1.5 pe-3 text-end tabular-nums">{point.due_required}</td>
                                                <td className="py-1.5 pe-3"><RateBar rate={point.submission_rate} text={percent(point.submission_rate)} /></td>
                                                <td className="py-1.5"><RateBar rate={point.on_time_rate} text={percent(point.on_time_rate)} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    {role.prepares && (
                        <section className={cx(panelCls, 'p-4')} aria-labelledby="ov-next">
                            <div className="flex items-baseline justify-between gap-2">
                                <h2 id="ov-next" className={sectionTitleCls}>{rt('overview.nextDue')}</h2>
                                <Link href={route('reporting.assignments')} className="text-sm text-primary hover:underline">{rt('nav.assignments')}</Link>
                            </div>
                            {next_due.length === 0 ? <p className={cx(mutedCls, 'mt-3')}>{rt('assignments.emptyOpen')}</p> : (
                                <ul className="mt-2 divide-y divide-(--app-border)">
                                    {next_due.map((row) => (
                                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">{name(row.report_type)} · {label(row.period)}</p>
                                                <p className={cx(mutedCls, 'text-xs')}>{subject(row)}</p>
                                                <Deadline row={row} />
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <WorkflowBadge status={row.workflow} />
                                                <Link href={route('reporting.prepare.edit', row.id)} className={primaryBtn}>{rt(row.workflow === 'not_started' ? 'assignments.start' : 'assignments.continue')}</Link>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function QueueCard({ href, value, text, urgent = false }: { href: string; value: number; text: string; urgent?: boolean }): JSX.Element {
    return (
        <Link href={href} className={cx(panelCls, 'flex items-center gap-3 p-4 hover:bg-(--app-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary')}>
            <span className={cx('text-3xl font-semibold tabular-nums', urgent ? 'text-amber-700 dark:text-amber-400' : 'text-(--app-foreground)')}>{value}</span>
            <span className="text-sm">{text}</span>
        </Link>
    );
}

/** A rate as a bar with its figure in text; "N/A" draws no bar at all. */
function RateBar({ rate, text }: { rate: number | null; text: string }): JSX.Element {
    return (
        <span className="flex items-center gap-2">
            <span className="h-2 w-24 overflow-hidden rounded-full bg-(--app-muted)" aria-hidden="true">
                {rate !== null && <span className="block h-full rounded-full bg-primary" style={{ width: `${Math.round(rate * 100)}%` }} />}
            </span>
            <span className="tabular-nums">{text}</span>
        </span>
    );
}
