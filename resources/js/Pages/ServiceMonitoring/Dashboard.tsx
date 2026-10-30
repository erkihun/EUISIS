import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { daysUntil, panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Compliance, ComplianceStatus, ComplianceTally, Named, Period, ReportingLevel } from '@/types/serviceMonitoring';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';

type Mine = { participant_id: string; level: ReportingLevel; organization: Named & { id: string }; compliance: Compliance | null; can_start: boolean };
type Listed<T> = { count: number; items: T[] };

interface Props {
    period: Period | null;
    mine: Mine[];
    compliance?: { tally: ComplianceTally; participants: number } | null;
    overdue?: Listed<{ id: string; title: string; due_on: string; status: string; owner: Named }>;
    clarifications?: Listed<{ id: string; subject: string; status: string; created_at: string | null; organization: Named }>;
    can: { manage_periods: boolean; review: boolean; verify: boolean; reports: boolean };
}

const ORDER: ComplianceStatus[] = ['on_time', 'late_extension', 'in_review', 'returned', 'pending', 'missing', 'not_submitted'];

/** The module's home: the current period, my institution's report, and (loaded after the page) what needs attention in scope. */
export default function Dashboard({ period, mine, compliance, overdue, clarifications, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const days = period ? daysUntil(period.deadline_at) : null;

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('dashboard.title')} description={sm('dashboard.description')} />}>
            <Head title={sm('dashboard.title')} />
            <div className="space-y-4">
                {period === null ? (
                    <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('dashboard.noPeriod')}</p>
                ) : (
                    <section className={`${panelCls} grid gap-3 p-4 sm:grid-cols-3`} aria-label={sm('dashboard.currentPeriod')}>
                        <div>
                            <p className="text-xs text-(--app-muted-foreground)">{sm('dashboard.currentPeriod')}</p>
                            <p className="text-base font-semibold text-(--app-foreground)">{period.label}</p>
                            <SmStatusBadge kind="period" status={period.status} />
                        </div>
                        <div>
                            <p className="text-xs text-(--app-muted-foreground)">{sm('dashboard.deadline')}</p>
                            <p className="text-base font-semibold text-(--app-foreground)"><LocalizedDateDisplay value={period.submission_due_on} /></p>
                            <p className="text-sm text-(--app-muted-foreground)" aria-live="polite">
                                {days === null ? '' : days > 0 ? sm('dashboard.countdown', { days }) : days === 0 ? sm('dashboard.countdownToday') : sm('dashboard.countdownPassed')}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-start gap-2 sm:justify-end">
                            {can.review && <Link className={secondaryBtn} href={route('service-monitoring.review.queue')}>{sm('dashboard.reviewQueue')}</Link>}
                            {can.verify && <Link className={secondaryBtn} href={route('service-monitoring.verification.index')}>{sm('dashboard.verification')}</Link>}
                        </div>
                    </section>
                )}

                <section className={`${panelCls} p-4`} aria-labelledby="sm-mine">
                    <h2 id="sm-mine" className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.myReports')}</h2>
                    {mine.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.noParticipant')}</p>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {mine.map((row) => (
                                <li key={row.participant_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <p className="text-sm font-medium text-(--app-foreground)">{name(row.organization)}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{sm(`level.${row.level}`)}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {row.compliance && <SmStatusBadge kind="compliance" status={row.compliance.status} />}
                                        {row.compliance?.submission_id ? (
                                            <Link className={primaryBtn} href={route(row.compliance.submission_status === 'draft' || row.compliance.submission_status === 'returned' ? 'service-monitoring.submissions.edit' : 'service-monitoring.submissions.show', row.compliance.submission_id)}>
                                                {row.compliance.submission_status === 'draft' || row.compliance.submission_status === 'returned' ? sm('dashboard.continue') : sm('dashboard.view')}
                                            </Link>
                                        ) : row.can_start && period ? (
                                            <button type="button" className={primaryBtn} onClick={() => router.post(route('service-monitoring.submissions.start', [period.id, row.participant_id]))}>{sm('dashboard.start')}</button>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Deferred data={['compliance', 'overdue', 'clarifications']} fallback={() => <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('common.loading')}</p>}>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <section className={`${panelCls} p-4`} aria-labelledby="sm-compliance">
                            <h2 id="sm-compliance" className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.compliance')}</h2>
                            {compliance ? (
                                <>
                                    <p className="mb-2 text-xs text-(--app-muted-foreground)">{sm('dashboard.participants', { count: compliance.participants })}</p>
                                    <dl className="grid grid-cols-2 gap-2 text-sm">
                                        {ORDER.map((status) => (
                                            <div key={status} className="flex justify-between gap-2">
                                                <dt className="text-(--app-muted-foreground)">{sm(`compliance.${status}`)}</dt>
                                                <dd className="font-semibold tabular-nums text-(--app-foreground)">{compliance.tally[status]}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </>
                            ) : <p className="text-sm text-(--app-muted-foreground)">{sm('dashboard.noPeriod')}</p>}
                        </section>
                        <section className={`${panelCls} p-4`} aria-labelledby="sm-overdue">
                            <h2 id="sm-overdue" className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.overdue')} ({overdue?.count ?? 0})</h2>
                            <ul className="space-y-2 text-sm">
                                {(overdue?.items ?? []).map((item) => (
                                    <li key={item.id}>
                                        <p className="font-medium text-(--app-foreground)">{item.title}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{name(item.owner)} · <LocalizedDateDisplay value={item.due_on} /></p>
                                    </li>
                                ))}
                            </ul>
                            <Link className="mt-3 inline-block text-sm underline" href={route('service-monitoring.action-items.index', { overdue: 1 })}>{sm('nav.actionItems')}</Link>
                        </section>
                        <section className={`${panelCls} p-4`} aria-labelledby="sm-clarifications">
                            <h2 id="sm-clarifications" className="mb-3 text-sm font-semibold text-(--app-foreground)">{sm('dashboard.clarifications')} ({clarifications?.count ?? 0})</h2>
                            <ul className="space-y-2 text-sm">
                                {(clarifications?.items ?? []).map((c) => (
                                    <li key={c.id}>
                                        <Link className="font-medium underline" href={route('service-monitoring.clarifications.show', c.id)}>{c.subject}</Link>
                                        <p className="text-xs text-(--app-muted-foreground)">{name(c.organization)} · {sm(`enums.clarification_status.${c.status}`)}</p>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                </Deferred>
            </div>
        </AuthenticatedLayout>
    );
}
