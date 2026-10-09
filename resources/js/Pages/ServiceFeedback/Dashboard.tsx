import FeedbackFilterBar, { type FeedbackFilterOptions, type FeedbackFilters } from '@/Components/ServiceFeedback/FeedbackFilterBar';
import RatingStars from '@/Components/ServiceFeedback/RatingStars';
import {
    FeedbackPageHeader,
    FeedbackStatusBadge,
    RatingBar,
    RatingPill,
    percent,
    ratingTone,
    toneClasses,
    useNameLabel,
    type FeedbackSummary,
} from '@/Components/ServiceFeedback/feedbackUi';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AlertTriangle, ClockIcon, MessageSquareIcon, StarIcon } from '@/Components/Icons';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName, cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

type GroupRow = {
    id: string | null;
    name: string | null;
    total: number;
    average: number;
    employee_number?: string | null;
};

type Props = {
    summary: { total: number; average: number; low_rated: number; pending: number };
    ratingDistribution: { rating: number; count: number }[];
    byOrganization: GroupRow[];
    byEmployee: GroupRow[];
    byServiceType: GroupRow[];
    recentComments: FeedbackSummary[];
    attention: FeedbackSummary[];
    oldestPendingAt: string | null;
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
};

const STAR_BAR: Record<number, string> = {
    5: 'bg-emerald-600 dark:bg-emerald-500',
    4: 'bg-emerald-400 dark:bg-emerald-600',
    3: 'bg-amber-500',
    2: 'bg-orange-500',
    1: 'bg-red-600 dark:bg-red-500',
};

export default function ServiceFeedbackDashboard({
    summary,
    ratingDistribution,
    byOrganization,
    byEmployee,
    byServiceType,
    recentComments,
    attention,
    oldestPendingAt,
    filters,
    filterOptions,
}: Props): JSX.Element {
    const { t } = useLocale();
    const label = useNameLabel();

    /*
     * Drill-down links carry the scope already chosen here (period,
     * organization, service type), so the inbox opens on the same slice.
     */
    const withScope = (routeName: string, extra: Record<string, string> = {}) => {
        const params = Object.fromEntries(
            Object.entries({ ...filters, ...extra }).filter(([, value]) => value !== undefined && value !== ''),
        );
        return route(routeName, params);
    };

    const count = (star: number) => ratingDistribution.find((row) => row.rating === star)?.count ?? 0;
    const satisfied = count(4) + count(5);
    const neutral = count(3);
    const dissatisfied = count(1) + count(2);

    // The widest bar sets the scale, so a low-volume period still reads clearly.
    const maxCount = Math.max(...ratingDistribution.map((row) => row.count), 1);

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.dashboard')} />

            <div className="space-y-6">
                <FeedbackPageHeader
                    current="overview"
                    title={t('serviceFeedback.overview')}
                    description={t('serviceFeedback.moduleSubtitle')}
                    pendingCount={summary.pending}
                    actions={summary.pending > 0 ? (
                        <Link href={withScope('service-feedback.admin.index', { status: 'pending' })} className={buttonClassName({ variant: 'primary' })}>
                            {t('serviceFeedback.reviewPending')}
                            <span className="inline-flex h-5 min-w-[1.375rem] items-center justify-center rounded-full bg-white/20 px-1.5 text-xs font-bold tabular-nums">
                                {summary.pending.toLocaleString()}
                            </span>
                        </Link>
                    ) : undefined}
                />

                <FeedbackFilterBar
                    routeName="service-feedback.admin.dashboard"
                    filters={filters}
                    filterOptions={filterOptions}
                    presets
                />

                {/* Headline figures */}
                <section aria-label={t('serviceFeedback.dashboard')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi
                        icon={<MessageSquareIcon className="h-[17px] w-[17px]" />}
                        iconClass="bg-[color:var(--color-primary-50)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-950)] dark:text-[color:var(--color-primary-200)]"
                        label={t('serviceFeedback.totalFeedback')}
                        value={summary.total.toLocaleString()}
                        footer={t('serviceFeedback.inSelectedScope')}
                    />
                    <Kpi
                        icon={<StarIcon className="h-[17px] w-[17px] fill-current stroke-none" />}
                        iconClass="bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400"
                        label={t('serviceFeedback.averageRating')}
                        value={(
                            <span className="flex items-baseline gap-1.5">
                                {summary.total > 0 ? summary.average.toFixed(2) : '—'}
                                <span className="text-sm font-normal text-[color:var(--app-muted-foreground)]">/ 5</span>
                            </span>
                        )}
                        footer={summary.total > 0 ? <RatingStars rating={Math.round(summary.average)} /> : t('serviceFeedback.noFeedbackYet')}
                    />
                    <Kpi
                        href={withScope('service-feedback.admin.reports')}
                        icon={<AlertTriangle className="h-[17px] w-[17px]" />}
                        iconClass="bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400"
                        label={t('serviceFeedback.lowRatings')}
                        value={<span className={summary.low_rated > 0 ? toneClasses.low.text : undefined}>{summary.low_rated.toLocaleString()}</span>}
                        footer={t('serviceFeedback.shareOfResponses').replace(':percent', String(percent(summary.low_rated, summary.total)))}
                        cta={t('serviceFeedback.watchlist')}
                    />
                    <Kpi
                        href={withScope('service-feedback.admin.index', { status: 'pending' })}
                        highlight={summary.pending > 0}
                        icon={<ClockIcon className="h-[17px] w-[17px]" />}
                        iconClass="bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                        label={t('serviceFeedback.awaitingReview')}
                        value={summary.pending.toLocaleString()}
                        footer={oldestPendingAt ? (
                            <>{t('serviceFeedback.oldestWaitingSince')} <LocalizedDateDisplay value={oldestPendingAt} /></>
                        ) : t('serviceFeedback.allCaughtUp')}
                        cta={t('serviceFeedback.openInbox')}
                    />
                </section>

                <div className="grid gap-4 lg:grid-cols-12">
                    {/* Rating distribution */}
                    <section aria-labelledby="distribution-heading" className="space-y-5 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 lg:col-span-7">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="distribution-heading" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.ratingDistribution')}</h2>
                            {summary.total > 0 && (
                                <span className="text-[13px] text-[color:var(--app-muted-foreground)]">
                                    {t('serviceFeedback.satisfiedShare').replace(':percent', String(Math.round(percent(satisfied, summary.total))))}
                                </span>
                            )}
                        </div>
                        <ul className="space-y-3">
                            {[...ratingDistribution].sort((a, b) => b.rating - a.rating).map((row) => (
                                <li key={row.rating}>
                                    <Link
                                        href={withScope('service-feedback.admin.index', { rating: String(row.rating) })}
                                        className="group flex items-center gap-3 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                                    >
                                        <span className="inline-flex w-9 shrink-0 items-center gap-1 text-[13px] font-semibold text-[color:var(--app-foreground)]">
                                            {row.rating}
                                            <StarIcon aria-hidden="true" className="h-3.5 w-3.5 fill-amber-400 stroke-none" />
                                            <span className="sr-only">{t(`serviceFeedback.rating${row.rating}`)}</span>
                                        </span>
                                        <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]">
                                            <span
                                                className={cx('block h-full rounded-full transition-opacity group-hover:opacity-80', STAR_BAR[row.rating])}
                                                style={{ width: `${(row.count / maxCount) * 100}%` }}
                                            />
                                        </span>
                                        <span className="w-12 shrink-0 text-right text-[13px] font-semibold tabular-nums text-[color:var(--app-foreground)]">{row.count.toLocaleString()}</span>
                                        <span className="w-12 shrink-0 text-right text-xs tabular-nums text-[color:var(--app-muted-foreground)]">{percent(row.count, summary.total)}%</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <div className="flex flex-wrap gap-x-5 gap-y-2 border-t border-[color:var(--app-border)] pt-3.5 text-xs text-[color:var(--app-muted-foreground)]">
                            <Legend dot="bg-emerald-600" label={t('serviceFeedback.satisfied')} value={satisfied} />
                            <Legend dot="bg-amber-500" label={t('serviceFeedback.neutral')} value={neutral} />
                            <Legend dot="bg-red-600" label={t('serviceFeedback.dissatisfied')} value={dissatisfied} />
                        </div>
                    </section>

                    {/* Needs attention */}
                    <section aria-labelledby="attention-heading" className="flex flex-col overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] lg:col-span-5">
                        <div className="flex items-center justify-between gap-3 border-b border-[color:var(--app-border)] px-5 py-4">
                            <h2 id="attention-heading" className="inline-flex items-center gap-2 text-[15px] font-semibold text-[color:var(--app-foreground)]">
                                <AlertTriangle aria-hidden="true" className="h-4 w-4 text-red-600 dark:text-red-400" />
                                {t('serviceFeedback.needsAttention')}
                            </h2>
                            <Link href={withScope('service-feedback.admin.reports')} className="text-[13px] font-semibold text-[color:var(--color-primary)] hover:underline">
                                {t('serviceFeedback.watchlist')}
                            </Link>
                        </div>
                        {attention.length === 0 ? (
                            <p className="flex-1 px-5 py-10 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.nothingNeedsAttention')}</p>
                        ) : (
                            <ul className="flex-1 divide-y divide-[color:var(--app-border)]">
                                {attention.map((item) => (
                                    <li key={item.id}>
                                        <Link href={route('service-feedback.admin.show', item.id)} className="flex gap-3 px-5 py-3.5 transition-colors hover:bg-[color:var(--app-surface-muted)]">
                                            <RatingPill rating={item.rating} />
                                            <span className="min-w-0">
                                                <span className={cx('line-clamp-2 text-[13px] leading-relaxed', item.comment ? 'text-[color:var(--app-foreground)]' : 'italic text-[color:var(--app-muted-foreground)]')}>
                                                    {item.comment || t('serviceFeedback.noCommentRatingOnly')}
                                                </span>
                                                <span className="mt-1 block text-xs text-[color:var(--app-muted-foreground)]">
                                                    {item.employee?.name ?? '—'} · {label(item.service_type)} · <LocalizedDateDisplay value={item.created_at} />
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="flex items-center justify-between gap-3 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-5 py-3 text-[13px] text-[color:var(--app-muted-foreground)]">
                            <span>{t('serviceFeedback.awaitingReviewCount').replace(':count', summary.pending.toLocaleString())}</span>
                            <Link href={withScope('service-feedback.admin.index', { status: 'pending' })} className="font-semibold text-[color:var(--color-primary)] hover:underline">
                                {t('serviceFeedback.goToInbox')} →
                            </Link>
                        </div>
                    </section>
                </div>

                {/* Breakdowns */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Breakdown
                        title={t('serviceFeedback.feedbackByOrganization')}
                        rows={byOrganization}
                        href={(row) => withScope('service-feedback.admin.index', { organization_id: row.id! })}
                    />
                    <Breakdown
                        title={t('serviceFeedback.feedbackByEmployee')}
                        rows={byEmployee}
                        href={(row) => withScope('service-feedback.admin.index', { employee_id: row.id! })}
                    />
                    <Breakdown
                        title={t('serviceFeedback.feedbackByServiceType')}
                        rows={byServiceType}
                        href={(row) => withScope('service-feedback.admin.index', { service_type_id: row.id! })}
                    />
                </div>

                {/* Recent comments */}
                <section aria-labelledby="recent-heading" className="space-y-3.5 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 id="recent-heading" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.recentComments')}</h2>
                        <Link href={withScope('service-feedback.admin.index')} className="text-[13px] font-semibold text-[color:var(--color-primary)] hover:underline">
                            {t('serviceFeedback.viewAllInInbox')}
                        </Link>
                    </div>
                    {recentComments.length === 0 ? (
                        <p className="py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noFeedbackYet')}</p>
                    ) : (
                        <ul className="grid gap-3 md:grid-cols-2">
                            {recentComments.map((item) => (
                                <li key={item.id}>
                                    <Link
                                        href={route('service-feedback.admin.show', item.id)}
                                        className="flex h-full flex-col gap-2.5 rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-4 transition-colors hover:border-[color:var(--app-border-strong)] hover:bg-[color:var(--app-surface-muted)]"
                                    >
                                        <span className="flex items-center justify-between gap-3">
                                            <span className="inline-flex items-center gap-2">
                                                <RatingPill rating={item.rating} />
                                                <span className={cx('text-xs font-medium', toneClasses[ratingTone(item.rating)].text)}>{t(`serviceFeedback.rating${item.rating}`)}</span>
                                            </span>
                                            <FeedbackStatusBadge status={item.status} />
                                        </span>
                                        <span className="line-clamp-4 text-sm leading-relaxed text-[color:var(--app-foreground)]">“{item.comment}”</span>
                                        <span className="mt-auto text-xs text-[color:var(--app-muted-foreground)]">
                                            {item.employee?.name ?? '—'} · {label(item.service_type)} · {label(item.organization)} · <LocalizedDateDisplay value={item.created_at} />
                                        </span>
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

function Kpi({
    icon,
    iconClass,
    label,
    value,
    footer,
    href,
    cta,
    highlight = false,
}: {
    icon: ReactNode;
    iconClass: string;
    label: string;
    value: ReactNode;
    footer: ReactNode;
    href?: string;
    cta?: string;
    highlight?: boolean;
}): JSX.Element {
    const body = (
        <>
            <span className="flex items-center gap-2.5">
                <span aria-hidden="true" className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', iconClass)}>{icon}</span>
                <span className={cx('text-[13px] font-medium', highlight ? 'text-amber-900 dark:text-amber-200' : 'text-[color:var(--app-muted-foreground)]')}>{label}</span>
            </span>
            <span className={cx('text-3xl font-bold leading-none tabular-nums', highlight ? 'text-amber-800 dark:text-amber-300' : 'text-[color:var(--app-foreground)]')}>{value}</span>
            <span className={cx('flex items-center justify-between gap-2 text-xs', highlight ? 'text-amber-900/80 dark:text-amber-200/80' : 'text-[color:var(--app-muted-foreground)]')}>
                <span className="min-w-0">{footer}</span>
                {cta && <span className="shrink-0 font-semibold text-[color:var(--color-primary)] group-hover:underline">{cta} →</span>}
            </span>
        </>
    );

    const className = cx(
        'group flex flex-col gap-2.5 rounded-[var(--radius-panel)] border p-5 transition-colors',
        highlight
            ? 'border-amber-300 bg-amber-50 dark:border-amber-900/70 dark:bg-amber-950/30'
            : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]',
        href && (highlight ? 'hover:border-amber-400' : 'hover:border-[color:var(--app-border-strong)]'),
    );

    return href ? <Link href={href} className={className}>{body}</Link> : <div className={className}>{body}</div>;
}

function Legend({ dot, label, value }: { dot: string; label: string; value: number }): JSX.Element {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span aria-hidden="true" className={cx('h-2 w-2 rounded-full', dot)} />
            {label} <strong className="font-semibold tabular-nums text-[color:var(--app-foreground)]">{value.toLocaleString()}</strong>
        </span>
    );
}

function Breakdown({ title, rows, href }: { title: string; rows: GroupRow[]; href: (row: GroupRow) => string }): JSX.Element {
    const { t } = useLocale();

    return (
        <section className="space-y-3.5 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.topByVolume')}</span>
            </div>

            {rows.length === 0 ? (
                <p className="py-4 text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noFeedbackYet')}</p>
            ) : (
                <>
                    <div className="flex justify-between text-[11px] font-semibold uppercase tracking-[0.05em] text-[color:var(--app-muted-foreground)]">
                        <span>{t('common.name')}</span>
                        <span>{t('serviceFeedback.averageAndCount')}</span>
                    </div>
                    <ul className="-mx-2 space-y-0.5">
                        {rows.map((row) => {
                            const content = (
                                <>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-medium text-[color:var(--app-foreground)]">{row.name ?? '—'}</span>
                                        {row.employee_number && <span className="block text-[11px] text-[color:var(--app-muted-foreground)]">{row.employee_number}</span>}
                                    </span>
                                    <RatingBar value={row.average} className="w-12 shrink-0" />
                                    <span className={cx('w-9 shrink-0 text-right text-[13px] font-bold tabular-nums', toneClasses[ratingTone(row.average)].text)}>{row.average.toFixed(2)}</span>
                                    <span className="w-8 shrink-0 text-right text-xs tabular-nums text-[color:var(--app-muted-foreground)]">{row.total}</span>
                                </>
                            );
                            const rowClass = 'flex items-center gap-2.5 rounded-md px-2 py-1.5';

                            return (
                                <li key={row.id ?? row.name}>
                                    {row.id ? (
                                        <Link href={href(row)} className={cx(rowClass, 'transition-colors hover:bg-[color:var(--app-surface-muted)]')}>{content}</Link>
                                    ) : (
                                        <div className={rowClass}>{content}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </section>
    );
}
