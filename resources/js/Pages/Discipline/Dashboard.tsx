import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AlertTriangle, Briefcase, ClockIcon, ScaleIcon } from '@/Components/Icons';
import { CaseStatusBadge, employeeLabel, useDates, type CaseRow } from '@/Components/discipline/shared';
import { useLocale } from '@/hooks/useLocale';
import { cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

type Stage = 'draft' | 'charged' | 'under_investigation' | 'report_submitted' | 'decided' | 'decision_delivered';

type Props = {
    figures: { open: number; due_soon: number; overdue: number; suspended: number; appeals: number; decided_30: number };
    stages: Record<Stage, number>;
    attention: CaseRow[];
    queue: CaseRow[];
    dueSoonDays: number;
    can: { create: boolean; committees: boolean };
};

const panel = 'rounded-panel border border-(--app-border) bg-(--app-surface)';
const heading = 'text-[15px] font-semibold text-(--app-foreground)';
const muted = 'text-(--app-muted-foreground)';
const textLink = 'text-[13px] font-semibold text-primary hover:underline';
const primaryBtn = 'inline-flex items-center gap-2 rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90';

// The order a case moves through; amber marks the stages waiting on the head of the institution.
const STAGES: { key: Stage; bar: string }[] = [
    { key: 'draft', bar: 'bg-primary-300' },
    { key: 'charged', bar: 'bg-primary-400' },
    { key: 'under_investigation', bar: 'bg-primary' },
    { key: 'report_submitted', bar: 'bg-amber-600 dark:bg-amber-500' },
    { key: 'decided', bar: 'bg-amber-600 dark:bg-amber-500' },
    { key: 'decision_delivered', bar: 'bg-primary-400' },
];

/** Discipline Management's home page: open cases, statutory deadlines, suspensions and appeals. */
export default function Dashboard({ figures, stages, attention, queue, dueSoonDays, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const d = (key: string) => t(`discipline.dashboard.${key}`);
    const { date } = useDates();
    const maxBar = Math.max(...STAGES.map((s) => stages[s.key]), 1);
    const cases = route('discipline.cases.index');

    const areas = [
        { title: t('discipline.title'), hint: d('casesHint'), href: cases },
        can.committees && { title: t('nav.disciplineCommittees'), hint: d('committeesHint'), href: route('discipline.committees.index') },
        can.create && { title: t('discipline.newCase'), hint: d('newCaseHint'), href: route('discipline.cases.create') },
    ].filter((a): a is Exclude<typeof a, false> => Boolean(a));

    return (
        <AuthenticatedLayout header={<PageHeader title={d('title')} description={d('subtitle')}
            actions={can.create ? <Link href={route('discipline.cases.create')} className={primaryBtn}>{t('discipline.newCase')}</Link> : undefined} />}>
            <Head title={d('title')} />
            <div className="space-y-6 p-4 sm:p-6">
                <section aria-label={d('title')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi href={cases} icon={<Briefcase className="h-[17px] w-[17px]" />} iconClass="bg-primary-50 text-primary dark:bg-primary-950 dark:text-primary-200"
                        label={d('open')} value={figures.open} footer={d('openHint')} />
                    <Kpi tone={figures.due_soon > 0 ? 'warning' : undefined} icon={<ClockIcon className="h-[17px] w-[17px]" />} iconClass="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                        label={d('dueSoon').replace('{n}', String(dueSoonDays))} value={figures.due_soon} footer={d('dueSoonHint')} />
                    <Kpi tone={figures.overdue > 0 ? 'danger' : undefined} icon={<AlertTriangle className="h-[17px] w-[17px]" />} iconClass="bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300"
                        label={d('overdue')} value={figures.overdue} footer={figures.overdue > 0 ? d('overdueHint') : d('nothingOverdue')} />
                    <Kpi icon={<ScaleIcon className="h-[17px] w-[17px]" />} iconClass="bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300"
                        label={d('suspended')} value={figures.suspended} footer={d('suspendedHint')} />
                </section>

                <div className="grid gap-4 lg:grid-cols-12">
                    <section aria-labelledby="stages-heading" className={cx(panel, 'space-y-5 p-5 lg:col-span-7')}>
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="stages-heading" className={heading}>{d('where')}</h2>
                            <span className={cx('text-[13px]', muted)}>{figures.open.toLocaleString()}</span>
                        </div>
                        <ul className="space-y-3">
                            {STAGES.map(({ key, bar }) => (
                                <li key={key}>
                                    <Link href={route('discipline.cases.index', { status: key })} className="flex items-center gap-3 rounded-control hover:bg-(--app-surface-muted)">
                                        <span className="w-44 shrink-0 text-[13px] font-medium text-(--app-foreground) sm:w-56">{t(`discipline.statuses.${key}`)}</span>
                                        <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                                            <span className={cx('block h-full rounded-full', bar)} style={{ width: `${(stages[key] / maxBar) * 100}%` }} />
                                        </span>
                                        <span className="w-10 shrink-0 text-right text-[13px] font-semibold tabular-nums text-(--app-foreground)">{stages[key].toLocaleString()}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <dl className={cx('grid grid-cols-2 gap-3 border-t border-(--app-border) pt-3.5 text-xs', muted)}>
                            <div><dt>{d('appeals')}</dt><dd className="mt-0.5 text-base font-semibold tabular-nums text-(--app-foreground)">{figures.appeals.toLocaleString()}</dd><dd>{d('appealsHint')}</dd></div>
                            <div><dt>{d('decided30')}</dt><dd className="mt-0.5 text-base font-semibold tabular-nums text-(--app-foreground)">{figures.decided_30.toLocaleString()}</dd></div>
                        </dl>
                    </section>

                    <section aria-labelledby="attention-heading" className={cx(panel, 'flex flex-col overflow-hidden lg:col-span-5')}>
                        <div className="flex items-center gap-2 border-b border-(--app-border) px-5 py-4">
                            <AlertTriangle aria-hidden="true" className="h-4 w-4 text-red-600 dark:text-red-400" />
                            <h2 id="attention-heading" className={heading}>{d('attention')}</h2>
                        </div>
                        {attention.length === 0
                            ? <p className={cx('flex-1 px-5 py-10 text-center text-sm', muted)}>{d('allClear')}</p>
                            : <ul className="flex-1 divide-y divide-(--app-border)">
                                {attention.map((c) => (
                                    <li key={c.id}>
                                        <Link href={route('discipline.cases.show', c.id)} className="flex flex-col gap-1 px-5 py-3.5 transition-colors hover:bg-(--app-surface-muted)">
                                            <span className="flex items-center justify-between gap-3">
                                                <span className="font-mono text-[13px] font-semibold text-(--app-foreground)">{c.case_number ?? '—'}</span>
                                                <DeadlineBadge row={c} date={date} />
                                            </span>
                                            <span className="line-clamp-1 text-[13px] text-(--app-foreground)">{employeeLabel(c.accused, locale)}</span>
                                            <span className={cx('text-xs', muted)}>{c.next_deadline?.label}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>}
                    </section>
                </div>

                <section aria-labelledby="queue-heading" className="space-y-3">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h2 id="queue-heading" className={heading}>{d('queue')}</h2>
                            <p className={cx('mt-0.5 text-xs', muted)}>{d('queueHelp')}</p>
                        </div>
                        <Link href={cases} className={textLink}>{d('viewAll')} →</Link>
                    </div>
                    {queue.length === 0
                        ? <p className={cx(panel, 'p-4 text-sm', muted)}>{t('discipline.empty')}</p>
                        : <div className={cx(panel, 'overflow-x-auto')}>
                            <table className="min-w-full text-sm">
                                <thead className={cx('text-left text-xs', muted)}>
                                    <tr>
                                        <th className="px-4 py-2 font-medium">{t('discipline.caseNumber')}</th>
                                        <th className="px-4 py-2 font-medium">{t('discipline.accused')}</th>
                                        <th className="px-4 py-2 font-medium">{t('discipline.organization')}</th>
                                        <th className="px-4 py-2 font-medium">{t('discipline.nextDeadline')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-(--app-border)">
                                    {queue.map((c) => (
                                        <tr key={c.id}>
                                            <td className="px-4 py-2">
                                                <Link href={route('discipline.cases.show', c.id)} className="font-medium text-(--app-foreground) hover:underline">{c.case_number ?? '—'}</Link>
                                                <div className="mt-1"><CaseStatusBadge status={c.status} /></div>
                                            </td>
                                            <td className="px-4 py-2">{employeeLabel(c.accused, locale)}</td>
                                            <td className="px-4 py-2">{c.organization?.name ?? '—'}</td>
                                            <td className={cx('px-4 py-2', c.next_deadline?.overdue && 'text-red-700 dark:text-red-400')}>
                                                {c.next_deadline ? `${c.next_deadline.label} — ${date(c.next_deadline.due_at)}` : d('noDeadline')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>}
                </section>

                <section aria-labelledby="areas-heading" className="space-y-3">
                    <h2 id="areas-heading" className={heading}>{d('workAreas')}</h2>
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {areas.map((a) => (
                            <li key={a.href}>
                                <Link href={a.href} className="flex h-full flex-col gap-1 rounded-card border border-(--app-border) bg-(--app-surface) p-4 transition-colors hover:border-(--app-border-strong) hover:bg-(--app-surface-muted)">
                                    <span className="text-sm font-semibold text-(--app-foreground)">{a.title}</span>
                                    <span className={cx('text-xs', muted)}>{a.hint}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

/** Deadline state as text plus colour, never colour alone. */
function DeadlineBadge({ row, date }: { row: CaseRow; date: (value: string | null | undefined) => string }): JSX.Element | null {
    const { t } = useLocale();
    if (!row.next_deadline) return null;
    const overdue = row.next_deadline.overdue;
    return (
        <span className={cx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1',
            overdue ? 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/30 dark:text-red-300 dark:ring-red-900'
                : 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900')}>
            {overdue ? t('discipline.dashboard.overdue') : date(row.next_deadline.due_at)}
        </span>
    );
}

function Kpi({ icon, iconClass, label, value, footer, href, tone }: {
    icon: ReactNode; iconClass: string; label: string; value: number; footer: ReactNode; href?: string; tone?: 'warning' | 'danger';
}): JSX.Element {
    const toneCls = {
        warning: { box: 'border-amber-300 bg-amber-50 dark:border-amber-900/70 dark:bg-amber-950/30', label: 'text-amber-900 dark:text-amber-200', value: 'text-amber-800 dark:text-amber-300', foot: 'text-amber-900/80 dark:text-amber-200/80' },
        danger: { box: 'border-red-300 bg-red-50 dark:border-red-900/70 dark:bg-red-950/30', label: 'text-red-900 dark:text-red-200', value: 'text-red-700 dark:text-red-300', foot: 'text-red-900/80 dark:text-red-200/80' },
    }[tone ?? 'warning'];
    const body = <>
        <span className="flex items-center gap-2.5">
            <span aria-hidden="true" className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', iconClass)}>{icon}</span>
            <span className={cx('text-[13px] font-medium', tone ? toneCls.label : muted)}>{label}</span>
        </span>
        <span className={cx('text-3xl font-bold leading-none tabular-nums', tone ? toneCls.value : 'text-(--app-foreground)')}>{value.toLocaleString()}</span>
        <span className={cx('text-xs', tone ? toneCls.foot : muted)}>{footer}</span>
    </>;
    const className = cx('flex flex-col gap-2.5 rounded-panel border p-5 transition-colors', tone ? toneCls.box : 'border-(--app-border) bg-(--app-surface)', href && 'hover:border-(--app-border-strong)');
    return href ? <Link href={href} className={className}>{body}</Link> : <div className={className}>{body}</div>;
}
