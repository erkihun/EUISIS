import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import PaginatorLinks, { type PaginatorLink } from '@/Components/PaginatorLinks';
import { FeedbackStatusBadge, statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { useLocale } from '@/hooks/useLocale';
import { Card, EmptyState, cx } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';

type Entry = {
    id: string; reference: string | null; type: string; status: string;
    target_type: 'employee' | 'organization'; target_name: string | null;
    message: string; created_at: string | null; submitted_at: string | null; employee: string | null;
    employee_number: string | null; organization: string | null; handler: string | null;
};

type Props = {
    type: 'suggestion' | 'complaint';
    entries: { data: Entry[]; links: PaginatorLink[]; total: number; from: number | null; to: number | null };
    counts: Record<string, number>;
    statuses: string[];
    filters: { status: string | null; mine: boolean };
    canManageRouting: boolean;
};

/**
 * Inbox of one routed submission type. Status tabs follow the type's own
 * workflow (brief §48–51); "My queue" narrows to what is handed to this
 * user, one of their roles or their unit (§53).
 */
export default function SubmissionInbox({ type, entries, counts, statuses, filters, canManageRouting }: Props) {
    const { t } = useLocale();
    const title = t(type === 'complaint' ? 'serviceFeedback.typeComplaint' : 'serviceFeedback.typeSuggestion');
    const listRoute = type === 'complaint' ? 'service-submissions.complaints' : 'service-submissions.suggestions';
    const total = Object.values(counts).reduce((sum, value) => sum + Number(value), 0);
    const needsAttention = Number(counts.unrouted ?? 0);

    const tabs = [{ id: '', label: t('serviceFeedback.statusAll'), count: total }].concat(
        statuses.filter((status) => status === 'pending' || status === 'unrouted' || (counts[status] ?? 0) > 0 || ['assigned', 'under_review', 'closed'].includes(status))
            .map((status) => ({ id: status, label: t(statusLabelKey(status)), count: Number(counts[status] ?? 0) })),
    );
    const href = (status: string, mine: boolean) => route(listRoute, { ...(status ? { status } : {}), ...(mine ? { mine: 1 } : {}) });

    return <AuthenticatedLayout
        header={<PageHeader
            title={title}
            description={t('serviceFeedback.submissionInboxIntro')}
            actions={canManageRouting && <Link href={route('service-feedback.routing-rules.index')} className="inline-flex min-h-11 items-center text-sm font-semibold text-[color:var(--color-primary)] underline underline-offset-4">{t('serviceFeedback.routingRulesTitle')}</Link>}
        />}
    >
        <Head title={title} />
        <div className="space-y-5">
            {needsAttention > 0 && !filters.status && <div role="status" className="rounded-[var(--radius-card)] border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                {t('serviceFeedback.unroutedNotice').replace(':count', needsAttention.toLocaleString())}{' '}
                <Link href={href('unrouted', false)} className="font-semibold underline underline-offset-4">{t('serviceFeedback.statusUnrouted')}</Link>
            </div>}

            <nav aria-label={t('serviceFeedback.submissionCounts')} className="-mx-4 overflow-x-auto border-b border-[color:var(--app-border)] px-4 sm:mx-0 sm:px-0">
                <ul className="flex min-w-max gap-1">
                    {tabs.map((tab) => {
                        const active = tab.id === (filters.status ?? '');
                        const attention = tab.id === 'unrouted' && tab.count > 0;
                        return <li key={tab.id || 'all'}>
                            <Link href={href(tab.id, filters.mine)} preserveScroll aria-current={active ? 'page' : undefined}
                                className={cx('-mb-px inline-flex h-11 items-center gap-2 border-b-2 px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                    active ? 'border-[color:var(--color-primary)] font-semibold text-[color:var(--color-primary)]' : 'border-transparent font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]')}>
                                {tab.label}
                                <span className={cx('inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                    attention ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
                                        : active ? 'bg-[color:var(--color-primary-100)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-900)] dark:text-[color:var(--color-primary-200)]'
                                            : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]')}>{tab.count.toLocaleString()}</span>
                            </Link>
                        </li>;
                    })}
                </ul>
            </nav>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.showingRange').replace(':total', entries.total.toLocaleString()).replace(':from', String(entries.from ?? 0)).replace(':to', String(entries.to ?? 0))}</p>
                <div role="group" aria-label={t('serviceFeedback.queueFilter')} className="inline-flex rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-0.5 text-sm">
                    {[{ mine: false, key: 'queueAll' }, { mine: true, key: 'queueMine' }].map((option) => <Link key={option.key} href={href(filters.status ?? '', option.mine)} preserveScroll aria-current={filters.mine === option.mine ? 'true' : undefined}
                        className={cx('inline-flex min-h-9 items-center rounded-[calc(var(--radius-control)-2px)] px-3 font-medium', filters.mine === option.mine ? 'bg-[color:var(--color-primary)] text-white' : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]')}>{t(`serviceFeedback.${option.key}`)}</Link>)}
                </div>
            </div>

            <section className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <h2 className="sr-only">{title}</h2>
                {entries.data.length === 0 ? <Card className="border-0"><EmptyState title={t('serviceFeedback.noSubmissions')} /></Card>
                    : <ul className="divide-y divide-[color:var(--app-border)]">{entries.data.map((entry) => <li key={entry.id}>
                        <Link href={route('service-submissions.show', { type, submission: entry.id })} className="block p-4 hover:bg-[color:var(--app-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[color:var(--color-primary)]">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold">
                                    <span className="mr-2 rounded-full bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-xs font-medium text-[color:var(--app-muted-foreground)]">{t(entry.target_type === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}</span>{' '}
                                    {entry.target_name ?? entry.employee ?? entry.employee_number ?? '—'}
                                </span>
                                <FeedbackStatusBadge status={entry.status} />
                            </div>
                            <p className="mt-1 line-clamp-2 text-sm text-[color:var(--app-muted-foreground)]">{entry.message}</p>
                            <p className="mt-2 flex flex-wrap gap-x-3 text-xs text-[color:var(--app-muted-foreground)]">
                                {entry.reference && <span className="font-mono">{entry.reference}</span>}
                                {entry.organization && <span>{entry.organization}</span>}
                                <LocalizedDateDisplay value={entry.submitted_at ?? entry.created_at} withTime />
                                <span>{t('serviceFeedback.currentHandler')}: {entry.handler ?? t('serviceFeedback.noHandler')}</span>
                            </p>
                        </Link>
                    </li>)}</ul>}
            </section>
            <PaginatorLinks links={entries.links} label={t('serviceFeedback.pagination')} />
        </div>
    </AuthenticatedLayout>;
}
