import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AlertTriangle, ClockIcon, Inbox, CheckCircle } from '@/Components/Icons';
import { statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { useLocale } from '@/hooks/useLocale';
import { cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

type Row = { id: string; reference: string | null; status: string; tip_type: string | null; organization?: string | null; target_name?: string | null; created_at?: string | null; submitted_at?: string | null };

type Props = {
    type: 'suggestion' | 'complaint';
    figures: { total: number; open: number; waiting: number; unrouted: number; in_progress: number; outcome: number; closed: number; mine: number; overdue: number; older_than_30_days: number };
    byStatus: Record<string, number>;
    byTipType: { total: number; CORRUPTION: number; IMPROPER_PRACTICE: number; UNCLASSIFIED_LEGACY: number } | null;
    violations: Record<string, number> | null;
    months: Record<string, number>;
    byOrganization: { id: string; name: string; total: number }[];
    recent: Row[];
    canManageRouting: boolean;
};

const panel = 'rounded-panel border border-(--app-border) bg-(--app-surface)';
const heading = 'text-[15px] font-semibold text-(--app-foreground)';
const muted = 'text-(--app-muted-foreground)';

/** A Tip-off's or a complaint's own dashboard (ServiceSubmissionController::dashboard). */
export default function SubmissionDashboard({ type, figures, byStatus, byTipType, violations, months, byOrganization, recent, canManageRouting }: Props): JSX.Element {
    const { t } = useLocale();
    const d = (key: string) => t(`serviceFeedback.typeDashboard.${key}`);
    const tipOff = type === 'suggestion';
    const list = route(tipOff ? 'service-submissions.suggestions' : 'service-submissions.complaints');
    const title = d(tipOff ? 'tipTitle' : 'complaintTitle');
    const maxMonth = Math.max(...Object.values(months), 1);
    const maxStatus = Math.max(...Object.values(byStatus), 1);

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={d(tipOff ? 'tipHelp' : 'complaintHelp')} />}>
            <Head title={title} />
            <div className="space-y-6 p-4 sm:p-6">
                <section aria-label={title} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Kpi href={list} icon={<Inbox className="h-[17px] w-[17px]" />} label={d('open')} value={figures.open} footer={`${d('total')}: ${figures.total.toLocaleString()}`} />
                    <Kpi href={route('service-submissions.unrouted', { submission_type: type })} tone={figures.waiting > 0 ? 'warning' : undefined} icon={<ClockIcon className="h-[17px] w-[17px]" />} label={d('waiting')} value={figures.waiting} footer={`${d('unrouted')}: ${figures.unrouted.toLocaleString()}`} />
                    <Kpi href={`${list}?mine=1`} icon={<CheckCircle className="h-[17px] w-[17px]" />} label={d('mine')} value={figures.mine} footer={`${d('inProgress')}: ${figures.in_progress.toLocaleString()}`} />
                    <Kpi tone={figures.overdue > 0 ? 'danger' : undefined} icon={<AlertTriangle className="h-[17px] w-[17px]" />} label={d('overdue')} value={figures.overdue} footer={d('overdueHelp')} />
                    <Kpi tone={figures.older_than_30_days > 0 ? 'danger' : undefined} icon={<AlertTriangle className="h-[17px] w-[17px]" />} label={d('old')} value={figures.older_than_30_days} footer={`${d('outcome')}: ${figures.outcome.toLocaleString()} · ${d('closed')}: ${figures.closed.toLocaleString()}`} />
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section aria-labelledby="by-status" className={cx(panel, 'space-y-4 p-5')}>
                        <h2 id="by-status" className={heading}>{d('byStatus')}</h2>
                        <Bars rows={Object.entries(byStatus).map(([status, n]) => ({ key: status, label: t(statusLabelKey(status)), value: n, href: `${list}?status=${status}` }))} max={maxStatus} />
                    </section>
                    <section aria-labelledby="by-month" className={cx(panel, 'space-y-4 p-5')}>
                        <h2 id="by-month" className={heading}>{d('byMonth')}</h2>
                        <Bars rows={Object.entries(months).map(([month, n]) => ({ key: month, label: month, value: n }))} max={maxMonth} />
                    </section>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {tipOff && (
                        <section aria-labelledby="by-kind" className={cx(panel, 'space-y-4 p-5')}>
                            <h2 id="by-kind" className={heading}>{d('byKind')}</h2>
                            {byTipType && <Bars rows={(['CORRUPTION', 'IMPROPER_PRACTICE', 'UNCLASSIFIED_LEGACY'] as const).map((key) => ({ key, label: t(`serviceFeedback.typeDashboard.tipTypes.${key}`), value: byTipType[key], href: `${list}?tip_type=${key}` }))} max={Math.max(byTipType.CORRUPTION, byTipType.IMPROPER_PRACTICE, byTipType.UNCLASSIFIED_LEGACY, 1)} />}
                            {violations && <>
                                <h3 className={cx('pt-2 text-sm font-semibold', muted)}>{d('violations')}</h3>
                                <Bars rows={Object.entries(violations).map(([key, n]) => ({ key, label: t(`serviceFeedback.tipForm.violations.${key}`), value: n }))} max={Math.max(...Object.values(violations), 1)} />
                            </>}
                        </section>
                    )}
                    <section aria-labelledby="by-org" className={cx(panel, 'space-y-4 p-5', !tipOff && 'lg:col-span-2')}>
                        <h2 id="by-org" className={heading}>{d('byOrganization')}</h2>
                        {byOrganization.length === 0 ? <p className={cx('text-sm', muted)}>{d('none')}</p>
                            : <Bars rows={byOrganization.map((row) => ({ key: row.id, label: row.name, value: row.total }))} max={Math.max(...byOrganization.map((row) => row.total), 1)} />}
                    </section>
                </div>

                <section aria-labelledby="recent" className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <h2 id="recent" className={heading}>{d('recent')}</h2>
                        <div className="flex gap-4">
                            {canManageRouting && <Link href={route(type === 'complaint' ? 'public-complaints.routing-rules.index' : 'tip-offs.routing-rules.index')} className="text-[13px] font-semibold text-primary hover:underline">{t('nav.serviceFeedbackRoutingRules')}</Link>}
                            <Link href={list} className="text-[13px] font-semibold text-primary hover:underline">{d('viewAll')} →</Link>
                        </div>
                    </div>
                    {recent.length === 0 ? <p className={cx(panel, 'p-4 text-sm', muted)}>{d('none')}</p> : (
                        <ul className={cx(panel, 'divide-y divide-(--app-border)')}>
                            {recent.map((row) => (
                                <li key={row.id}>
                                    <Link href={route('service-submissions.show', { type, submission: row.id })} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-(--app-surface-muted)">
                                        <span className="font-mono text-[13px] font-semibold">{row.reference ?? '—'}</span>
                                        <span className={cx('text-xs', muted)}>{row.organization ?? row.target_name ?? ''}</span>
                                        <span className="text-xs">{t(statusLabelKey(row.status))}</span>
                                        <span className={cx('text-xs', muted)}><LocalizedDateDisplay value={row.submitted_at ?? row.created_at ?? null} /></span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function Bars({ rows, max }: { rows: { key: string; label: string; value: number; href?: string }[]; max: number }): JSX.Element {
    return (
        <ul className="space-y-2.5">
            {rows.map((row) => {
                const body = <>
                    <span className="w-40 shrink-0 truncate text-[13px] text-(--app-foreground) sm:w-52" title={row.label}>{row.label}</span>
                    <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true"><span className="block h-full rounded-full bg-primary" style={{ width: `${(row.value / max) * 100}%` }} /></span>
                    <span className="w-10 shrink-0 text-right text-[13px] font-semibold tabular-nums">{row.value.toLocaleString()}</span>
                </>;
                return <li key={row.key}>{row.href ? <Link href={row.href} className="flex items-center gap-3 rounded-control hover:bg-(--app-surface-muted)">{body}</Link> : <div className="flex items-center gap-3">{body}</div>}</li>;
            })}
        </ul>
    );
}

function Kpi({ icon, label, value, footer, href, tone }: { icon: ReactNode; label: string; value: number; footer: ReactNode; href?: string; tone?: 'warning' | 'danger' }): JSX.Element {
    const box = tone === 'danger' ? 'border-red-300 bg-red-50 dark:border-red-900/70 dark:bg-red-950/30' : tone === 'warning' ? 'border-amber-300 bg-amber-50 dark:border-amber-900/70 dark:bg-amber-950/30' : 'border-(--app-border) bg-(--app-surface)';
    const body = <>
        <span className="flex items-center gap-2.5"><span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary dark:bg-primary-950 dark:text-primary-200">{icon}</span><span className={cx('text-[13px] font-medium', muted)}>{label}</span></span>
        <span className="text-3xl font-bold leading-none tabular-nums text-(--app-foreground)">{value.toLocaleString()}</span>
        <span className={cx('text-xs', muted)}>{footer}</span>
    </>;
    const className = cx('flex flex-col gap-2.5 rounded-panel border p-5', box, href && 'hover:border-(--app-border-strong)');
    return href ? <Link href={href} className={className}>{body}</Link> : <div className={className}>{body}</div>;
}
