import { Link } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';
import { cx } from '@euisis/ui';
import { AlertTriangle, Briefcase, ClockIcon, Inbox } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Workspace, CaseTable } from '@/Components/grievances/Workspace';
import { CaseNumber, HandlerName, SlaBadge, fmt, primaryBtn, secondaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import type { DashboardProps, GrievanceRow } from '@/types/grievances';

const panel = 'rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]';
const heading = 'text-[15px] font-semibold text-[color:var(--app-foreground)]';
const muted = 'text-[color:var(--app-muted-foreground)]';
const textLink = 'text-[13px] font-semibold text-[color:var(--color-primary)] hover:underline';

// Stage groups shown as bars; amber marks the ones waiting on someone else's sign-off.
const WORKLOAD: { key: keyof DashboardProps['assigned']; bar: string }[] = [
    { key: 'new', bar: 'bg-[color:var(--color-primary-400)]' },
    { key: 'awaiting_hearing', bar: 'bg-[color:var(--color-primary-400)]' },
    { key: 'awaiting_decision', bar: 'bg-[color:var(--color-primary)]' },
    { key: 'pending_approval', bar: 'bg-amber-600 dark:bg-amber-500' },
    { key: 'returned', bar: 'bg-amber-600 dark:bg-amber-500' },
    { key: 'escalated_in', bar: 'bg-[color:var(--color-primary-400)]' },
];

const URGENT = ['overdue', 'due_today', 'due_soon'];

export default function Dashboard({ assigned, queue, intakePending, approvalsPending, can }: DashboardProps): JSX.Element {
    const { t } = useLocale();
    const label = useEnumLabel();
    const casesUrl = (params: Record<string, string> = {}) => route('grievances.cases.index', { tab: 'assigned', ...params });

    // The queue is sorted by deadline, so its urgent rows are the most urgent overall.
    const attention = queue.filter((g) => URGENT.includes(g.sla?.state ?? ''));
    const urgentCount = assigned.due_soon + assigned.overdue;
    const maxBar = Math.max(...WORKLOAD.map((w) => assigned[w.key]), 1);

    const areas = [
        can.intake && { title: t('grievanceCases.intake'), hint: fmt(t('grievanceCases.dash.intake_hint'), { n: intakePending ?? 0 }), href: route('grievances.cases.index', { tab: 'intake' }) },
        can.approvals && { title: t('grievanceWork.approvals'), hint: fmt(t('grievanceCases.dash.approvals_hint'), { n: approvalsPending ?? 0 }), href: route('grievances.approvals.index') },
        can.correspondence && { title: t('grievanceWork.correspondence'), hint: t('grievanceCases.dash.correspondence_hint'), href: route('grievances.correspondence.index') },
        can.committees && { title: t('grievanceAdmin.committees'), hint: t('grievanceCases.dash.committees_hint'), href: route('grievances.committees.index') },
        can.reports && { title: t('grievanceWork.reports'), hint: t('grievanceCases.dash.reports_hint'), href: route('grievances.reports.index') },
    ].filter((a): a is { title: string; hint: string; href: string } => Boolean(a));

    const actions = (can.approvals || can.intake) ? <>
        {can.approvals && <Link className={secondaryBtn} href={route('grievances.approvals.index')}>{t('grievanceWork.approvals')}<Count value={approvalsPending ?? 0} /></Link>}
        {can.intake && <Link className={primaryBtn} href={route('grievances.cases.index', { tab: 'intake' })}>{t('grievanceCases.dash.review_intake')}<Count value={intakePending ?? 0} inverted /></Link>}
    </> : undefined;

    return (
        <Workspace title={t('grievanceCases.dashboard')} description={t('grievanceCases.dash.subtitle')} actions={actions}>
            {/* Headline figures */}
            <section aria-label={t('grievanceCases.dashboard')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi
                    href={casesUrl()}
                    icon={<Briefcase className="h-[17px] w-[17px]" />}
                    iconClass="bg-[color:var(--color-primary-50)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-950)] dark:text-[color:var(--color-primary-200)]"
                    label={t('grievanceCases.metrics.total')}
                    value={assigned.total}
                    footer={t('grievanceCases.dash.total_hint')}
                    cta={t('grievanceCases.dash.open_list')}
                />
                <Kpi
                    icon={<Inbox className="h-[17px] w-[17px]" />}
                    iconClass="bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300"
                    label={t('grievanceCases.metrics.new')}
                    value={assigned.new}
                    footer={t('grievanceCases.dash.new_hint')}
                />
                <Kpi
                    href={casesUrl({ sla: 'due_soon' })}
                    tone={assigned.due_soon > 0 ? 'warning' : undefined}
                    icon={<ClockIcon className="h-[17px] w-[17px]" />}
                    iconClass="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                    label={t('grievanceCases.metrics.due_soon')}
                    value={assigned.due_soon}
                    footer={t('grievanceCases.dash.due_soon_hint')}
                    cta={t('grievanceCases.dash.review')}
                />
                <Kpi
                    href={casesUrl({ sla: 'overdue' })}
                    tone={assigned.overdue > 0 ? 'danger' : undefined}
                    icon={<AlertTriangle className="h-[17px] w-[17px]" />}
                    iconClass="bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300"
                    label={t('grievanceCases.metrics.overdue')}
                    value={assigned.overdue}
                    footer={assigned.overdue > 0 ? t('grievanceCases.dash.overdue_hint') : t('grievanceCases.dash.nothing_overdue')}
                    cta={assigned.overdue > 0 ? t('grievanceCases.dash.act_now') : undefined}
                />
            </section>

            <div className="grid gap-4 lg:grid-cols-12">
                {/* Where the assigned cases sit */}
                <section aria-labelledby="workload-heading" className={cx(panel, 'space-y-5 p-5 lg:col-span-7')}>
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="workload-heading" className={heading}>{t('grievanceCases.dash.where')}</h2>
                        <span className={cx('text-[13px]', muted)}>{fmt(t('grievanceCases.dash.open_count'), { n: assigned.total })}</span>
                    </div>
                    <ul className="space-y-3">
                        {WORKLOAD.map(({ key, bar }) => (
                            <li key={key} className="flex items-center gap-3">
                                <span className="w-44 shrink-0 text-[13px] font-medium text-[color:var(--app-foreground)] sm:w-56">{t(`grievanceCases.metrics.${key}`)}</span>
                                <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]" aria-hidden="true">
                                    <span className={cx('block h-full rounded-full', bar)} style={{ width: `${(assigned[key] / maxBar) * 100}%` }} />
                                </span>
                                <span className="w-10 shrink-0 text-right text-[13px] font-semibold tabular-nums text-[color:var(--app-foreground)]">{assigned[key].toLocaleString()}</span>
                            </li>
                        ))}
                    </ul>
                    <p className={cx('border-t border-[color:var(--app-border)] pt-3.5 text-xs', muted)}>{t('grievanceCases.dash.where_note')}</p>
                </section>

                {/* Needs attention */}
                <section aria-labelledby="attention-heading" className={cx(panel, 'flex flex-col overflow-hidden lg:col-span-5')}>
                    <div className="flex items-center justify-between gap-3 border-b border-[color:var(--app-border)] px-5 py-4">
                        <h2 id="attention-heading" className={cx(heading, 'inline-flex items-center gap-2')}>
                            <AlertTriangle aria-hidden="true" className="h-4 w-4 text-red-600 dark:text-red-400" />
                            {t('grievanceCases.dash.attention')}
                        </h2>
                        {assigned.overdue > 0 && <Link href={casesUrl({ sla: 'overdue' })} className={textLink}>{t('grievanceCases.dash.overdue_list')}</Link>}
                    </div>
                    {attention.length === 0 ? (
                        <p className={cx('flex-1 px-5 py-10 text-center text-sm', muted)}>{t('grievanceCases.dash.all_clear')}</p>
                    ) : (
                        <ul className="flex-1 divide-y divide-[color:var(--app-border)]">
                            {attention.map((g) => <AttentionItem key={g.id} grievance={g} stage={label('stage_status', g.stage_status)} />)}
                        </ul>
                    )}
                    <div className={cx('flex items-center justify-between gap-3 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-3 text-[13px]', muted)}>
                        <span>{fmt(t('grievanceCases.dash.attention_count'), { n: urgentCount })}</span>
                        <Link href={casesUrl({ sort: 'due' })} className="font-semibold text-[color:var(--color-primary)] hover:underline">{t('grievanceCases.dash.open_cases')} →</Link>
                    </div>
                </section>
            </div>

            {/* Queue */}
            <section aria-labelledby="queue-heading" className="space-y-3">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 id="queue-heading" className={heading}>{t('grievanceCases.dash.queue')}</h2>
                        <p className={cx('mt-0.5 text-xs', muted)}>{t('grievanceCases.dash.queue_help')}</p>
                    </div>
                    <Link href={casesUrl({ sort: 'due' })} className={textLink}>{t('grievanceCases.dash.view_all')} →</Link>
                </div>
                <CaseTable rows={queue} />
            </section>

            {/* Other work areas the user can reach */}
            {areas.length > 0 && (
                <section aria-labelledby="areas-heading" className="space-y-3">
                    <h2 id="areas-heading" className={heading}>{t('grievanceCases.dash.work_areas')}</h2>
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {areas.map((a) => (
                            <li key={a.href}>
                                <Link href={a.href} className="flex h-full flex-col gap-1 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4 transition-colors hover:border-[color:var(--app-border-strong)] hover:bg-[color:var(--app-surface-muted)]">
                                    <span className="text-sm font-semibold text-[color:var(--app-foreground)]">{a.title}</span>
                                    <span className={cx('text-xs', muted)}>{a.hint}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </Workspace>
    );
}

function Count({ value, inverted = false }: { value: number; inverted?: boolean }): JSX.Element {
    return (
        <span className={cx('inline-flex h-5 min-w-[1.375rem] items-center justify-center rounded-full px-1.5 text-xs font-bold tabular-nums', inverted ? 'bg-white/20' : 'bg-[color:var(--app-surface-muted)]')}>
            {value.toLocaleString()}
        </span>
    );
}

function AttentionItem({ grievance: g, stage }: { grievance: GrievanceRow; stage: string }): JSX.Element {
    const { t } = useLocale();
    return (
        <li>
            <Link href={route('grievances.cases.show', g.id)} className="flex flex-col gap-1 px-5 py-3.5 transition-colors hover:bg-[color:var(--app-surface-muted)]">
                <span className="flex items-center justify-between gap-3">
                    <CaseNumber value={g.reference_number} />
                    <SlaBadge sla={g.sla} />
                </span>
                <span className="line-clamp-1 text-[13px] text-[color:var(--app-foreground)]">{g.subject ?? t('grievances.common.no_access_details')}</span>
                <span className={cx('flex flex-wrap items-baseline gap-x-1.5 text-xs', muted)}>
                    <span>{stage}</span>
                    <span aria-hidden="true">·</span>
                    <HandlerName handler={g.handler} />
                </span>
            </Link>
        </li>
    );
}

function Kpi({ icon, iconClass, label, value, footer, href, cta, tone }: {
    icon: ReactNode; iconClass: string; label: string; value: number; footer: ReactNode; href?: string; cta?: string; tone?: 'warning' | 'danger';
}): JSX.Element {
    const toneCls = {
        warning: { box: 'border-amber-300 bg-amber-50 dark:border-amber-900/70 dark:bg-amber-950/30', hover: 'hover:border-amber-400', label: 'text-amber-900 dark:text-amber-200', value: 'text-amber-800 dark:text-amber-300', foot: 'text-amber-900/80 dark:text-amber-200/80' },
        danger: { box: 'border-red-300 bg-red-50 dark:border-red-900/70 dark:bg-red-950/30', hover: 'hover:border-red-400', label: 'text-red-900 dark:text-red-200', value: 'text-red-700 dark:text-red-300', foot: 'text-red-900/80 dark:text-red-200/80' },
    }[tone ?? 'warning'];

    const body = (
        <>
            <span className="flex items-center gap-2.5">
                <span aria-hidden="true" className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', iconClass)}>{icon}</span>
                <span className={cx('text-[13px] font-medium', tone ? toneCls.label : muted)}>{label}</span>
            </span>
            <span className={cx('text-3xl font-bold leading-none tabular-nums', tone ? toneCls.value : 'text-[color:var(--app-foreground)]')}>{value.toLocaleString()}</span>
            <span className={cx('flex items-center justify-between gap-2 text-xs', tone ? toneCls.foot : muted)}>
                <span className="min-w-0">{footer}</span>
                {cta && href && <span className="shrink-0 font-semibold text-[color:var(--color-primary)] group-hover:underline">{cta} →</span>}
            </span>
        </>
    );

    const className = cx(
        'group flex flex-col gap-2.5 rounded-[var(--radius-panel)] border p-5 transition-colors',
        tone ? toneCls.box : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]',
        href && (tone ? toneCls.hover : 'hover:border-[color:var(--app-border-strong)]'),
    );

    return href ? <Link href={href} className={className}>{body}</Link> : <div className={className}>{body}</div>;
}
