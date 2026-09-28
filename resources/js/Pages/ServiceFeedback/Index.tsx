import FeedbackFilterBar, { type FeedbackFilterOptions, type FeedbackFilters } from '@/Components/ServiceFeedback/FeedbackFilterBar';
import {
    FeedbackPageHeader,
    FeedbackStatusBadge,
    RatingPill,
    exportHref,
    statusLabelKey,
    useNameLabel,
    type FeedbackSummary,
} from '@/Components/ServiceFeedback/feedbackUi';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight, DownloadIcon, EyeOffIcon, Inbox } from '@/Components/Icons';
import PaginatorLinks, { type PaginatorLink } from '@/Components/PaginatorLinks';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';

type Props = {
    feedback: {
        data: FeedbackSummary[];
        links: PaginatorLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    statusCounts: Record<string, number>;
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    statuses: string[];
    can: { review: boolean; hide: boolean; delete: boolean; export: boolean };
};

export default function ServiceFeedbackIndex({ feedback, statusCounts, filters, filterOptions, statuses, can }: Props): JSX.Element {
    const { t } = useLocale();
    const label = useNameLabel();

    const currentStatus = filters.status ?? '';
    const rows = feedback.data;
    const hasFilters = Object.values(filters).some((value) => value !== undefined && value !== '');

    const tabs = [{ id: '', label: t('serviceFeedback.statusAll'), count: statusCounts.all ?? 0 }].concat(
        statuses.map((status) => ({ id: status, label: t(statusLabelKey(status)), count: statusCounts[status] ?? 0 })),
    );

    /*
     * Tabs keep every other filter: switching from Pending to Resolved should
     * not also throw away the organization the admin narrowed to.
     */
    const tabHref = (status: string) => {
        const params = Object.fromEntries(
            Object.entries({ ...filters, status }).filter(([, value]) => value !== undefined && value !== ''),
        );
        return route('service-feedback.admin.index', params);
    };

    const showHref = (id: string) => route('service-feedback.admin.show', id);

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.inbox')} />

            <div className="space-y-6">
                <FeedbackPageHeader
                    current="inbox"
                    title={t('serviceFeedback.inbox')}
                    description={t('serviceFeedback.inboxSubtitle')}
                    pendingCount={statusCounts.pending}
                    actions={can.export ? (
                        <a href={exportHref(filters)} className={buttonClassName({ variant: 'outline' })}>
                            <DownloadIcon aria-hidden="true" className="h-4 w-4" />
                            {t('serviceFeedback.exportCsv')}
                        </a>
                    ) : undefined}
                />

                <section className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <nav aria-label={t('serviceFeedback.filterStatus')} className="overflow-x-auto border-b border-[color:var(--app-border)] px-2 pt-1 [scrollbar-width:none] sm:px-3 [&::-webkit-scrollbar]:hidden">
                        <ul className="flex min-w-max gap-1">
                            {tabs.map((tab) => {
                                const active = tab.id === currentStatus;
                                const pending = tab.id === 'pending' && tab.count > 0;
                                return (
                                    <li key={tab.id || 'all'}>
                                        <Link
                                            href={tabHref(tab.id)}
                                            preserveScroll
                                            aria-current={active ? 'page' : undefined}
                                            className={cx(
                                                '-mb-px inline-flex h-11 items-center gap-2 border-b-2 px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                                active
                                                    ? 'border-[color:var(--color-primary)] font-semibold text-[color:var(--color-primary)]'
                                                    : 'border-transparent font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]',
                                            )}
                                        >
                                            {tab.label}
                                            <span
                                                className={cx(
                                                    'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                                    pending
                                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
                                                        : active
                                                          ? 'bg-[color:var(--color-primary-100)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-900)] dark:text-[color:var(--color-primary-200)]'
                                                          : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
                                                )}
                                            >
                                                {tab.count.toLocaleString()}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <FeedbackFilterBar
                        routeName="service-feedback.admin.index"
                        filters={filters}
                        filterOptions={filterOptions}
                        search
                        embedded
                        preserve={['status']}
                    />

                    {rows.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-16 text-center">
                            <span className="flex h-11 w-11 items-center justify-center rounded-full bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]">
                                <Inbox aria-hidden="true" className="h-5 w-5" />
                            </span>
                            <p className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.nothingHere')}</p>
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">
                                {hasFilters ? t('serviceFeedback.noMatches') : t('serviceFeedback.noFeedbackYet')}
                            </p>
                        </div>
                    ) : (
                        <>
                            {/* Wide screens: a scannable table. */}
                            <table className="hidden w-full text-sm md:table">
                                <thead className="bg-[color:var(--app-surface-muted)]">
                                    <tr className="text-left text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                                        <th scope="col" className="w-20 px-4 py-2.5">{t('serviceFeedback.filterRating')}</th>
                                        <th scope="col" className="px-3 py-2.5">{t('serviceFeedback.columnFeedback')}</th>
                                        <th scope="col" className="w-44 px-3 py-2.5">{t('serviceFeedback.filterEmployee')}</th>
                                        <th scope="col" className="hidden w-52 px-3 py-2.5 lg:table-cell">{t('serviceFeedback.serviceType')}</th>
                                        <th scope="col" className="w-28 px-3 py-2.5">{t('serviceFeedback.filterStatus')}</th>
                                        <th scope="col" className="w-32 px-3 py-2.5">{t('serviceFeedback.submittedDate')}</th>
                                        <th scope="col" className="w-10 py-2.5 pr-4"><span className="sr-only">{t('serviceFeedback.openFeedback')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[color:var(--app-border)]">
                                    {rows.map((row) => {
                                        const hidden = row.status === 'hidden';
                                        return (
                                            <tr
                                                key={row.id}
                                                // The comment cell holds a real link for keyboard users; let it handle its own clicks.
                                                onClick={(event) => {
                                                    if (!(event.target as HTMLElement).closest('a')) router.visit(showHref(row.id));
                                                }}
                                                className={cx('cursor-pointer align-top transition-colors hover:bg-[color:var(--app-surface-muted)]', hidden && 'opacity-60')}
                                            >
                                                <td className="px-4 py-3.5"><RatingPill rating={row.rating} /></td>
                                                <td className="px-3 py-3.5">
                                                    <Link href={showHref(row.id)} className="group block focus-visible:outline-none">
                                                        <span className={cx(
                                                            'line-clamp-2 leading-relaxed group-hover:underline group-focus-visible:underline',
                                                            row.comment ? 'text-[color:var(--app-foreground)]' : 'italic text-[color:var(--app-muted-foreground)]',
                                                        )}>
                                                            {hidden && <EyeOffIcon aria-hidden="true" className="mr-1.5 inline h-3.5 w-3.5 align-[-2px]" />}
                                                            {row.comment || t('serviceFeedback.noCommentRatingOnly')}
                                                        </span>
                                                        <span className="mt-0.5 block text-xs text-[color:var(--app-muted-foreground)]">
                                                            {row.client_name ?? t('serviceFeedback.anonymousClient')}
                                                        </span>
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-3.5">
                                                    <div className="font-medium text-[color:var(--app-foreground)]">{row.employee?.name ?? '—'}</div>
                                                    <div className="text-xs text-[color:var(--app-muted-foreground)]">{row.employee?.employee_number}</div>
                                                </td>
                                                <td className="hidden px-3 py-3.5 lg:table-cell">
                                                    <div className="text-[color:var(--app-foreground)]">{label(row.service_type)}</div>
                                                    <div className="max-w-[13rem] truncate text-xs text-[color:var(--app-muted-foreground)]">{label(row.organization)}</div>
                                                </td>
                                                <td className="px-3 py-3.5"><FeedbackStatusBadge status={row.status} /></td>
                                                <td className="whitespace-nowrap px-3 py-3.5 text-[13px] text-[color:var(--app-muted-foreground)]">
                                                    <LocalizedDateDisplay value={row.created_at} />
                                                </td>
                                                <td className="py-3.5 pr-4 text-right">
                                                    <ChevronRight aria-hidden="true" className="ml-auto h-4 w-4 text-[color:var(--app-muted-foreground)]" />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>

                            {/* Phones: one card per entry, comment first. */}
                            <ul className="divide-y divide-[color:var(--app-border)] md:hidden">
                                {rows.map((row) => (
                                    <li key={row.id}>
                                        <Link
                                            href={showHref(row.id)}
                                            className={cx('flex flex-col gap-2 px-4 py-4 active:bg-[color:var(--app-surface-muted)]', row.status === 'hidden' && 'opacity-60')}
                                        >
                                            <span className="flex items-center justify-between gap-3">
                                                <RatingPill rating={row.rating} />
                                                <FeedbackStatusBadge status={row.status} />
                                            </span>
                                            <span className={cx('line-clamp-3 text-sm leading-relaxed', row.comment ? 'text-[color:var(--app-foreground)]' : 'italic text-[color:var(--app-muted-foreground)]')}>
                                                {row.comment || t('serviceFeedback.noCommentRatingOnly')}
                                            </span>
                                            <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {row.employee?.name ?? '—'} · {label(row.service_type)} · <LocalizedDateDisplay value={row.created_at} />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    {feedback.total > 0 && (
                        <div className="flex flex-col gap-3 border-t border-[color:var(--app-border)] px-4 py-3 text-[13px] text-[color:var(--app-muted-foreground)] sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                {t('serviceFeedback.showingRange')
                                    .replace(':from', String(feedback.from ?? 0))
                                    .replace(':to', String(feedback.to ?? 0))
                                    .replace(':total', feedback.total.toLocaleString())}
                            </span>
                            <PaginatorLinks links={feedback.links} label={t('serviceFeedback.pagination')} />
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
