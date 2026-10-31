import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Compliance, ComplianceStatus, ComplianceTally, Named, Period, ReportingLevel } from '@/types/serviceMonitoring';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

type Mine = { participant_id: string; level: ReportingLevel; organization: Named & { id: string }; compliance: Compliance | null; can_start: boolean };
type Listed<T> = { count: number; items: T[] };

interface Props {
    period: Period | null;
    mine: Mine[];
    compliance?: { tally: ComplianceTally; participants: number } | null;
    overdue?: Listed<{ id: string; title: string; due_on: string; status: string; owner: Named }>;
    clarifications?: Listed<{ id: string; subject: string; status: string; created_at: string | null; organization: Named }>;
    can: { manage_periods: boolean; manage_forms: boolean; review: boolean; verify: boolean; reports: boolean };
}

const ORDER: ComplianceStatus[] = ['on_time', 'late_extension', 'in_review', 'returned', 'pending', 'missing', 'not_submitted'];

/** The module's home: the current period, my institution's report, and (loaded after the page) what needs attention in scope. */
export default function Dashboard({ period, mine, compliance, overdue, clarifications, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const hasManagementActions = can.review || can.verify || can.reports || can.manage_forms || can.manage_periods;

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('dashboard.title')} description={sm('dashboard.description')} />}>
            <Head title={sm('dashboard.title')} />
            <ActionErrors />
            <div className="space-y-5">
                {period === null ? (
                    <section className="border-s-4 border-amber-500 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">
                        <p className="font-semibold">{sm('dashboard.noPeriod')}</p>
                        {can.manage_periods && <Link className="mt-2 inline-block underline" href={route('service-monitoring.periods.index')}>{sm('nav.periods')}</Link>}
                    </section>
                ) : (
                    <section className="flex flex-wrap items-center justify-between gap-4 border-y border-(--app-border) py-4" aria-label={sm('dashboard.currentPeriod')}>
                        <div>
                            <p className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{sm('dashboard.currentPeriod')}</p>
                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                <h2 className="text-xl font-semibold text-(--app-foreground)">{period.label}</h2>
                                <SmStatusBadge kind="period" status={period.status} />
                            </div>
                        </div>
                        <div className="text-start sm:text-end">
                            <p className="text-xs text-(--app-muted-foreground)">{sm('dashboard.deadline')}</p>
                            <p className="font-semibold text-(--app-foreground)"><LocalizedDateDisplay value={period.submission_due_on} /></p>
                        </div>
                    </section>
                )}

                <section aria-labelledby="sm-next-task">
                    <div className="mb-3">
                        <h2 id="sm-next-task" className="text-lg font-semibold text-(--app-foreground)">{sm('dashboard.nextTask')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.nextTaskHelp')}</p>
                    </div>
                    {mine.length === 0 ? (
                        <div className={`${panelCls} border-s-4 border-(--app-border-strong) p-5`}>
                            <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.noParticipant')}</p>
                        </div>
                    ) : (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {mine.map((row) => {
                                const href = row.compliance?.submission_id
                                    ? route(row.compliance.submission_status === 'draft' || row.compliance.submission_status === 'returned' ? 'service-monitoring.submissions.edit' : 'service-monitoring.submissions.show', row.compliance.submission_id)
                                    : null;
                                const action = row.compliance?.submission_id
                                    ? (row.compliance.submission_status === 'draft' || row.compliance.submission_status === 'returned' ? sm('dashboard.continue') : sm('dashboard.view'))
                                    : sm('dashboard.start');
                                return (
                                    <article key={row.participant_id} className="border border-(--app-border) bg-(--app-surface) p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{sm(`level.${row.level}`)}</p>
                                                <h3 className="mt-1 text-base font-semibold text-(--app-foreground)">{name(row.organization)}</h3>
                                            </div>
                                            {row.compliance && <SmStatusBadge kind="compliance" status={row.compliance.status} />}
                                        </div>
                                        <div className="mt-5">
                                            {href ? (
                                                <Link className={primaryBtn} href={href}>{action}</Link>
                                            ) : row.can_start && period ? (
                                                <button type="button" className={primaryBtn} onClick={() => router.post(route('service-monitoring.submissions.start', [period.id, row.participant_id]))}>{action}</button>
                                            ) : (
                                                <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.notOpen')}</p>
                                            )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>

                {hasManagementActions && (
                    <section className="border-y border-(--app-border) py-4" aria-labelledby="sm-tools">
                        <h2 id="sm-tools" className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.manage')}</h2>
                        <div className="flex flex-wrap gap-2">
                            {can.review && <Link className={secondaryBtn} href={route('service-monitoring.review.queue')}>{sm('dashboard.reviewQueue')}</Link>}
                            {can.verify && <Link className={secondaryBtn} href={route('service-monitoring.verification.index')}>{sm('dashboard.verification')}</Link>}
                            {can.reports && <Link className={secondaryBtn} href={route('service-monitoring.reports.index')}>{sm('nav.reports')}</Link>}
                            {can.manage_forms && <Link className={secondaryBtn} href={route('service-monitoring.forms.index')}>{sm('nav.forms')}</Link>}
                            {can.manage_periods && <Link className={secondaryBtn} href={route('service-monitoring.periods.index')}>{sm('nav.periods')}</Link>}
                        </div>
                    </section>
                )}

                <Deferred data={['compliance', 'overdue', 'clarifications']} fallback={() => <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('common.loading')}</p>}>
                    <section aria-labelledby="sm-overview">
                        <div className="mb-3">
                            <h2 id="sm-overview" className="text-lg font-semibold text-(--app-foreground)">{sm('dashboard.operationalOverview')}</h2>
                            <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.operationalOverviewHelp')}</p>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-[1.4fr_1fr_1fr]">
                            <div className={`${panelCls} p-4`}>
                                <div className="mb-3 flex items-baseline justify-between gap-3">
                                    <h3 className="text-sm font-semibold text-(--app-foreground)">{sm('dashboard.compliance')}</h3>
                                    {compliance && <span className="text-xs text-(--app-muted-foreground)">{sm('dashboard.participants', { count: compliance.participants })}</span>}
                                </div>
                                {compliance ? (
                                    <dl className="grid grid-cols-2 gap-x-5 gap-y-3 sm:grid-cols-3">
                                        {ORDER.map((status) => (
                                            <div key={status} className="border-s-2 border-(--app-border-strong) ps-3">
                                                <dt className="text-xs text-(--app-muted-foreground)">{sm(`compliance.${status}`)}</dt>
                                                <dd className="mt-1 text-xl font-semibold tabular-nums text-(--app-foreground)">{compliance.tally[status]}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                ) : <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.noPeriod')}</p>}
                            </div>
                            <div className={`${panelCls} p-4`}>
                                <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.overdue')} ({overdue?.count ?? 0})</h3>
                                {(overdue?.items ?? []).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.none')}</p> : (
                                    <ul className="space-y-3 text-sm">{(overdue?.items ?? []).map((item) => <li key={item.id}><p className="font-medium text-(--app-foreground)">{item.title}</p><p className="text-xs text-(--app-muted-foreground)">{name(item.owner)} · <LocalizedDateDisplay value={item.due_on} /></p></li>)}</ul>
                                )}
                                <Link className="mt-3 inline-block text-sm underline" href={route('service-monitoring.action-items.index', { overdue: 1 })}>{sm('nav.actionItems')}</Link>
                            </div>
                            <div className={`${panelCls} p-4`}>
                                <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.clarifications')} ({clarifications?.count ?? 0})</h3>
                                {(clarifications?.items ?? []).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.none')}</p> : (
                                    <ul className="space-y-3 text-sm">{(clarifications?.items ?? []).map((c) => <li key={c.id}><Link className="font-medium underline" href={route('service-monitoring.clarifications.show', c.id)}>{c.subject}</Link><p className="text-xs text-(--app-muted-foreground)">{name(c.organization)}</p></li>)}</ul>
                                )}
                            </div>
                        </div>
                    </section>
                </Deferred>
            </div>
        </AuthenticatedLayout>
    );
}
