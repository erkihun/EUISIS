import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { AlertTriangle, Building2, ClockIcon, Inbox, SearchIcon, UserIcon, X } from '@/Components/Icons';
import { InboxInIcon, PinListIcon, SignpostIcon } from '@/Components/NavIcons';
import { FeedbackStatusBadge, statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { CASE_MODULE, caseRoute } from '@/Components/ServiceFeedback/caseRoutes';
import { ListPagination, type LaravelPageMeta } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX, type ReactNode } from 'react';
import type { TipTypeBucket } from '@/types/publicSubmissions';

type Entry = {
    id: string; reference: string | null; type: string; status: string; tip_type: TipTypeBucket | null;
    target_type: 'employee' | 'organization'; target_name: string | null;
    message: string; created_at: string | null; submitted_at: string | null; employee: string | null;
    employee_number: string | null; organization: string | null; organization_unit: string | null; unit_selection_mode: 'SELECTED' | 'GENERAL' | null; handler: string | null;
    due_at?: string | null; overdue?: boolean; level?: number | null; stage?: 1 | 2 | 3 | null;
};

type Filters = { status: string | null; mine: boolean; tip_type: TipTypeBucket | null; overdue?: boolean; q?: string };

type Props = {
    type: 'suggestion' | 'complaint';
    entries: { data: Entry[] } & LaravelPageMeta;
    counts: Record<string, number>;
    overdueCount?: number;
    mineCount?: number;
    statuses: string[];
    filters: Filters;
    canManageRouting: boolean;
};

const TIP_FILTER_LABEL: Record<TipTypeBucket | 'ALL', string> = {
    ALL: 'serviceFeedback.tipTypeAll',
    CORRUPTION: 'serviceFeedback.tipTypeCorruption',
    IMPROPER_PRACTICE: 'serviceFeedback.tipTypeImproperPractice',
    UNCLASSIFIED_LEGACY: 'serviceFeedback.tipTypeUnclassified',
};

/** The colour that marks each row's state at a glance. */
const STATE_BAR: Record<string, string> = {
    unrouted: 'bg-amber-500', pending: 'bg-amber-400', assigned: 'bg-sky-500', under_review: 'bg-indigo-500',
    resolved: 'bg-emerald-500', accepted: 'bg-emerald-500', not_adopted: 'bg-(--app-muted-foreground)', reviewed: 'bg-emerald-400', closed: 'bg-(--app-border-strong)',
};

/**
 * The case list of one module — Tip-offs (/tip-off-admin/cases) or public
 * complaints (/complaint-admin/cases); nothing is shared between them.
 * Figures on top open the matching slice; the toolbar searches and filters
 * by status, Tip-off type, "mine" and overdue; each row shows what a
 * handler needs to decide what to open next.
 */
export default function SubmissionInbox({ type, entries, counts, overdueCount = 0, mineCount = 0, statuses, filters, canManageRouting }: Props): JSX.Element {
    const { t } = useLocale();
    const i = (key: string) => t(`serviceFeedback.caseList.${key}`);
    const module = CASE_MODULE[type];
    const title = i(`titles.${type}`);
    const listRoute = `${module}.cases.index`;
    const total = Object.values(counts).reduce((sum, value) => sum + Number(value), 0);
    const unrouted = Number(counts.unrouted ?? 0);
    const inHand = Number(counts.assigned ?? 0) + Number(counts.under_review ?? 0);
    const [search, setSearch] = useState(filters.q ?? '');
    const filtered = Boolean(filters.status || filters.mine || filters.tip_type || filters.overdue || filters.q);

    const query = (change: Partial<Filters>): Record<string, string | number> => {
        const next = { ...filters, ...change };
        return {
            ...(next.status ? { status: next.status } : {}),
            ...(next.mine ? { mine: 1 } : {}),
            ...(next.tip_type ? { tip_type: next.tip_type } : {}),
            ...(next.overdue ? { overdue: 1 } : {}),
            ...(next.q ? { q: next.q } : {}),
        };
    };
    const href = (change: Partial<Filters>) => route(listRoute, query(change) as never);

    const tabs = [{ id: '', label: t('serviceFeedback.statusAll'), count: total }].concat(
        statuses.filter((status) => ['pending', 'unrouted', 'assigned', 'under_review', 'closed'].includes(status) || (counts[status] ?? 0) > 0)
            .map((status) => ({ id: status, label: t(statusLabelKey(status)), count: Number(counts[status] ?? 0) })),
    );

    function submitSearch(event: FormEvent) {
        event.preventDefault();
        router.get(route(listRoute), query({ q: search.trim() }), { preserveState: true, preserveScroll: true, replace: true });
    }

    return <AuthenticatedLayout
        header={<PageHeader
            title={title}
            description={i(`intros.${type}`)}
            actions={<div className="flex flex-wrap gap-2">
                <Link href={route(`${module}.dashboard`)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{i('dashboard')}</Link>
                <Link href={route(`${module}.unrouted`)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                    {t('serviceFeedback.statusUnrouted')}{unrouted > 0 && <span className="ml-1.5 rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">{unrouted}</span>}
                </Link>
                {canManageRouting && <Link href={route(`${module}.routing-rules.index`)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('serviceFeedback.routingRulesTitle')}</Link>}
            </div>}
        />}
    >
        <Head title={title} />
        <div className="space-y-5">
            {/* Where the work stands: each figure opens its slice of the list. */}
            <section aria-label={t('serviceFeedback.submissionCounts')} className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Figure href={href({ status: null, mine: false, overdue: false, tip_type: null, q: '' })} active={!filtered} label={i('figures.all')} value={total} icon={<Inbox className="h-[18px] w-[18px]" />} />
                <Figure href={href({ status: 'unrouted', mine: false, overdue: false })} active={filters.status === 'unrouted' && !filters.mine && !filters.overdue} label={i('figures.unrouted')} hint={i('figures.unroutedHint')} value={unrouted} tone={unrouted > 0 ? 'warning' : undefined} icon={<InboxInIcon className="h-[18px] w-[18px]" />} />
                <Figure href={href({ overdue: true, status: null, mine: false })} active={Boolean(filters.overdue)} label={i('figures.overdue')} hint={i('figures.overdueHint')} value={overdueCount} tone={overdueCount > 0 ? 'danger' : undefined} icon={<ClockIcon className="h-[18px] w-[18px]" />} />
                <Figure href={href({ mine: true, status: null, overdue: false })} active={filters.mine && !filters.status && !filters.overdue} label={i('figures.mine')} hint={i('figures.mineHint').replace(':count', inHand.toLocaleString())} value={mineCount} icon={<PinListIcon className="h-[18px] w-[18px]" />} />
            </section>

            <section aria-label={i('toolbar')} className="space-y-3 rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <form role="search" onSubmit={submitSearch} className="relative flex-1">
                        <label htmlFor="case-search" className="sr-only">{i('search')}</label>
                        <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden />
                        <input id="case-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={i('searchPlaceholder')} maxLength={100}
                            className="h-11 w-full rounded-control border border-(--app-border) bg-(--app-surface) pl-9 pr-24 text-sm text-(--app-foreground) placeholder:text-(--app-muted-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/30" />
                        <button type="submit" className={cx(buttonClassName({ variant: 'primary', size: 'sm' }), 'absolute right-1.5 top-1/2 -translate-y-1/2')}>{i('searchButton')}</button>
                    </form>
                    <div role="group" aria-label={t('serviceFeedback.queueFilter')} className="inline-flex shrink-0 rounded-control border border-(--app-border) p-0.5 text-sm">
                        {[{ mine: false, key: 'queueAll' }, { mine: true, key: 'queueMine' }].map((option) => (
                            <Link key={option.key} href={href({ mine: option.mine })} preserveScroll aria-current={filters.mine === option.mine ? 'true' : undefined}
                                className={cx('inline-flex min-h-9 items-center rounded-[calc(var(--radius-control)-2px)] px-3 font-medium', filters.mine === option.mine ? 'bg-primary text-white' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>{t(`serviceFeedback.${option.key}`)}</Link>
                        ))}
                    </div>
                </div>

                <nav aria-label={i('statusTabs')} className="-mx-4 overflow-x-auto overflow-y-hidden border-b border-(--app-border) px-4">
                    <ul className="flex min-w-max gap-1">
                        {tabs.map((tab) => {
                            const active = tab.id === (filters.status ?? '');
                            const attention = tab.id === 'unrouted' && tab.count > 0;
                            return <li key={tab.id || 'all'}>
                                <Link href={href({ status: tab.id || null })} preserveScroll aria-current={active ? 'page' : undefined}
                                    className={cx('-mb-px inline-flex h-10 items-center gap-2 border-b-2 px-3 text-sm transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                        active ? 'border-primary font-semibold text-primary' : 'border-transparent font-medium text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                                    {tab.label}
                                    <span className={cx('inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                        attention ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
                                            : active ? 'bg-primary-100 text-primary dark:bg-primary-900 dark:text-primary-200'
                                                : 'bg-(--app-surface-muted) text-(--app-muted-foreground)')}>{tab.count.toLocaleString()}</span>
                                </Link>
                            </li>;
                        })}
                    </ul>
                </nav>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    {type === 'suggestion' ? (
                        <div role="group" aria-label={t('serviceFeedback.tipTypeLabel')} className="flex flex-wrap gap-2 text-sm">
                            {([null, 'CORRUPTION', 'IMPROPER_PRACTICE', 'UNCLASSIFIED_LEGACY'] as const).map((tipType) => (
                                <Link key={tipType ?? 'all'} href={href({ tip_type: tipType })} preserveScroll aria-current={filters.tip_type === tipType ? 'true' : undefined}
                                    className={cx('inline-flex min-h-8 items-center rounded-full border px-3 font-medium', filters.tip_type === tipType ? 'border-primary bg-primary text-white' : 'border-(--app-border) text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                                    {t(TIP_FILTER_LABEL[tipType ?? 'ALL'])}
                                </Link>
                            ))}
                        </div>
                    ) : <span />}
                    <div className="flex flex-wrap items-center gap-3 text-sm text-(--app-muted-foreground)">
                        <span>{t('serviceFeedback.showingRange').replace(':total', entries.total.toLocaleString()).replace(':from', String(entries.from ?? 0)).replace(':to', String(entries.to ?? 0))}</span>
                        {filtered && <Link href={route(listRoute)} className="inline-flex items-center gap-1 font-semibold text-primary hover:underline"><X className="h-3.5 w-3.5" aria-hidden />{i('clearFilters')}</Link>}
                    </div>
                </div>
            </section>

            <section aria-labelledby="case-list-heading" className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
                <h2 id="case-list-heading" className="sr-only">{title}</h2>
                {entries.data.length === 0
                    ? <div className="flex flex-col items-center px-6 py-14 text-center">
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-(--app-surface-muted) text-(--app-muted-foreground)" aria-hidden><Inbox className="h-6 w-6" /></span>
                        <p className="mt-4 text-base font-semibold text-(--app-foreground)">{filtered ? i('emptyFiltered') : i(`empty.${type}`)}</p>
                        <p className="mt-1 max-w-md text-sm text-(--app-muted-foreground)">{filtered ? i('emptyFilteredHint') : i('emptyHint')}</p>
                        {filtered && <Link href={route(listRoute)} className={cx(buttonClassName({ variant: 'outline', size: 'sm' }), 'mt-4')}>{i('clearFilters')}</Link>}
                    </div>
                    : <ul className="divide-y divide-(--app-border)">{entries.data.map((entry) => <CaseRow key={entry.id} type={type} entry={entry} />)}</ul>}
            </section>
            <ListPagination meta={entries} label={t('serviceFeedback.pagination')} />
        </div>
    </AuthenticatedLayout>;
}

function Figure({ href, label, hint, value, icon, tone, active }: { href: string; label: string; hint?: string; value: number; icon: ReactNode; tone?: 'warning' | 'danger'; active: boolean }) {
    return <Link href={href} preserveScroll aria-current={active ? 'true' : undefined}
        className={cx('flex items-start gap-3 rounded-card border bg-(--app-surface) p-4 transition-colors hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:outline-primary',
            active ? 'border-primary ring-1 ring-primary' : 'border-(--app-border)')}>
        <span className={cx('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
            tone === 'danger' ? 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300' : tone === 'warning' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' : 'bg-primary/10 text-primary')} aria-hidden>{icon}</span>
        <span className="min-w-0">
            <span className="block text-[13px] text-(--app-muted-foreground)">{label}</span>
            <span className={cx('block text-2xl font-semibold tabular-nums', tone === 'danger' ? 'text-red-700 dark:text-red-300' : tone === 'warning' ? 'text-amber-700 dark:text-amber-300' : 'text-(--app-foreground)')}>{value.toLocaleString()}</span>
            {hint && <span className="block text-xs text-(--app-muted-foreground)">{hint}</span>}
        </span>
    </Link>;
}

function CaseRow({ type, entry }: { type: 'suggestion' | 'complaint'; entry: Entry }) {
    const { t } = useLocale();
    const i = (key: string) => t(`serviceFeedback.caseList.${key}`);
    const target = entry.target_name ?? entry.employee ?? entry.employee_number ?? '—';
    const unit = entry.target_type === 'organization' && (entry.organization_unit || entry.unit_selection_mode === 'GENERAL') ? entry.organization_unit ?? t('serviceFeedback.generalNotSure') : null;

    return <li>
        <Link href={caseRoute(type, 'cases.show', { submission: entry.id })} className="relative flex gap-4 py-4 pl-5 pr-4 hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
            <span aria-hidden className={cx('absolute inset-y-0 left-0 w-1', STATE_BAR[entry.status] ?? 'bg-(--app-border)')} />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    {entry.reference && <span className="font-mono text-xs font-semibold text-(--app-muted-foreground)">{entry.reference}</span>}
                    <span className="text-[15px] font-semibold text-(--app-foreground) wrap-anywhere">{target}</span>
                    <Pill>{t(entry.target_type === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}</Pill>
                    {type === 'suggestion' && <Pill tone={entry.tip_type === 'CORRUPTION' ? 'danger' : undefined}>{t(TIP_FILTER_LABEL[entry.tip_type ?? 'UNCLASSIFIED_LEGACY'])}</Pill>}
                    {type === 'complaint' && entry.stage != null && entry.stage > 1 && <Pill tone="info">{t(`serviceFeedback.hearing.stageNames.${entry.stage}`)}</Pill>}
                    {entry.overdue && <Pill tone="danger"><AlertTriangle className="mr-1 h-3 w-3" aria-hidden />{i('overdue')}</Pill>}
                </div>
                <p className="mt-1 line-clamp-2 text-sm text-(--app-muted-foreground) wrap-anywhere">{entry.message}</p>
                <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-(--app-muted-foreground)">
                    {entry.organization && <Meta icon={<Building2 className="h-3.5 w-3.5" />} label={i('organization')}>{entry.organization}{unit && <> · {unit}</>}</Meta>}
                    <Meta icon={<UserIcon className="h-3.5 w-3.5" />} label={t('serviceFeedback.currentHandler')}>
                        {entry.handler ?? <span className="font-medium text-amber-700 dark:text-amber-300">{t('serviceFeedback.noHandler')}</span>}
                    </Meta>
                    <Meta icon={<ClockIcon className="h-3.5 w-3.5" />} label={i('received')}><LocalizedDateDisplay value={entry.submitted_at ?? entry.created_at} withTime /></Meta>
                    {entry.due_at && <Meta icon={<SignpostIcon className="h-3.5 w-3.5" />} label={i('due')}>
                        <span className={entry.overdue ? 'font-semibold text-red-700 dark:text-red-300' : undefined}><LocalizedDateDisplay value={entry.due_at} /></span>
                    </Meta>}
                </dl>
            </div>
            <div className="shrink-0 pt-0.5"><FeedbackStatusBadge status={entry.status} /></div>
        </Link>
    </li>;
}

function Pill({ children, tone }: { children: ReactNode; tone?: 'danger' | 'info' }) {
    return <span className={cx('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
        tone === 'danger' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
            : tone === 'info' ? 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300'
                : 'border-(--app-border) bg-(--app-surface-muted) text-(--app-muted-foreground)')}>{children}</span>;
}

function Meta({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }) {
    return <div className="inline-flex items-center gap-1.5">
        <span aria-hidden className="text-(--app-muted-foreground)">{icon}</span>
        <dt className="sr-only">{label}</dt>
        <dd>{children}</dd>
    </div>;
}
