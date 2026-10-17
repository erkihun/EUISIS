import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight, Inbox, SearchIcon, X } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { FeedbackStatusBadge, statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { ListPagination, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, type LaravelPageMeta } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Input, Select, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type JSX } from 'react';

/**
 * Every public submission the viewer may read — Feedback, Tip-offs and
 * Complaints, about employees and about institutions — filtered, sorted and
 * paginated on the server (PublicSubmissionListController). Each row opens
 * its type's own page, where the workflow actions live. No submitter identity
 * and no narrative are sent to this list.
 */

type Entry = {
    id: string;
    reference: string | null;
    type: 'feedback' | 'suggestion' | 'complaint';
    tip_type: string | null;
    target_type: 'employee' | 'organization';
    target_name: string | null;
    target_detail: string | null;
    organization: string | null;
    organization_unit: string | null;
    category: string | null;
    service: string | null;
    status: string;
    submitted_at: string | null;
    handler: string | null;
    identity_mode: string | null;
    url: string;
};

type Filters = {
    submission_type?: string;
    target_type?: string;
    organization_id?: string;
    organization_unit_id?: string;
    category_id?: string;
    status?: string;
    identity_mode?: string;
    date_from?: string;
    date_to?: string;
    q?: string;
    mine?: string;
    sort?: string;
};

type Named = { id: string; name_en: string; name_am: string | null };

type Props = {
    queue: 'all' | 'unrouted';
    entries: LaravelPageMeta & { data: Entry[] };
    overview: {
        total: number;
        by_type: Record<'feedback' | 'suggestion' | 'complaint', number>;
        by_target: Record<'employee' | 'organization', number>;
        by_status: Record<'pending' | 'unrouted' | 'assigned' | 'under_review' | 'resolved' | 'closed' | 'hidden', number>;
    };
    filters: Filters;
    filterOptions: {
        organizations: Named[];
        categories: Named[];
        statuses: string[];
        types: string[];
        targets: string[];
        identityModes: string[];
        sorts: string[];
    };
    hasAny: boolean;
};

const TYPE_LABEL: Record<string, string> = {
    feedback: 'serviceFeedback.adminTypeFeedback',
    suggestion: 'serviceFeedback.adminTypeSuggestion',
    complaint: 'serviceFeedback.adminTypeComplaint',
};

const filled = (value: string | undefined): value is string => value !== undefined && value !== '';

export default function AllSubmissions({ queue, entries, overview, filters, filterOptions, hasAny }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const [query, setQuery] = useState(filters.q ?? '');
    const routeName = queue === 'unrouted' ? 'service-submissions.unrouted' : 'service-submissions.index';

    useEffect(() => setQuery(filters.q ?? ''), [filters.q]);

    /** Filters round-trip through the server: the list is scoped and paginated there. Leaving out `page` returns to page 1. */
    function apply(change: Partial<Filters>) {
        const next = { ...filters, ...change };
        if (queue === 'unrouted') delete next.status;
        const params = Object.fromEntries(Object.entries(next).filter(([key, value]) => filled(value as string | undefined) && !(key === 'sort' && value === 'newest')));
        router.get(route(routeName), params, { preserveState: true, preserveScroll: true, replace: true });
    }

    useEffect(() => {
        const term = query.trim();
        if (term === (filters.q ?? '')) return;
        const timer = window.setTimeout(() => apply({ q: term }), 400);
        return () => window.clearTimeout(timer);
        // Typing schedules the search; `filters` changing is its result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const nameOf = (option: Named) => (am ? option.name_am ?? option.name_en : option.name_en);
    const open = overview.by_status.pending + overview.by_status.unrouted + overview.by_status.assigned + overview.by_status.under_review;
    const filterKeys: (keyof Filters)[] = ['submission_type', 'target_type', 'organization_id', 'organization_unit_id', 'category_id', 'status', 'identity_mode', 'date_from', 'date_to', 'q', 'mine'];
    const hasFilters = filterKeys.some((key) => filled(filters[key]) && !(queue === 'unrouted' && key === 'status'));
    const title = t(queue === 'unrouted' ? 'serviceFeedback.unroutedQueueTitle' : 'serviceFeedback.allSubmissionsTitle');

    const cards: { id: string; label: string; value: number; change?: Partial<Filters>; attention?: boolean }[] = [
        { id: 'total', label: t('serviceFeedback.statusAll'), value: overview.total, change: { submission_type: '', status: '' } },
        ...(['feedback', 'suggestion', 'complaint'] as const)
            .filter((type) => filterOptions.types.includes(type))
            .map((type) => ({ id: type, label: t(TYPE_LABEL[type]), value: overview.by_type[type], change: { submission_type: type, status: '' } })),
        { id: 'open', label: t('serviceFeedback.cardOpen'), value: open },
        { id: 'resolved', label: t('serviceFeedback.cardOutcome'), value: overview.by_status.resolved },
        { id: 'unrouted', label: t('serviceFeedback.statusUnrouted'), value: overview.by_status.unrouted, attention: overview.by_status.unrouted > 0 },
    ];

    const selectCls = 'h-[var(--control-h-sm)] py-0 w-full sm:w-auto sm:min-w-[10rem] sm:max-w-[14rem]';

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t(queue === 'unrouted' ? 'serviceFeedback.unroutedQueueIntro' : 'serviceFeedback.allSubmissionsIntro')} />}>
            <Head title={title} />
            <div className="space-y-5">
                <section aria-label={t('serviceFeedback.submissionCounts')} className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                    {cards.map((card) => {
                        const body = (
                            <>
                                <span className="block text-xs font-medium text-[color:var(--app-muted-foreground)]">{card.label}</span>
                                <span className="mt-1 block text-2xl font-bold tabular-nums text-[color:var(--app-foreground)]">{card.value.toLocaleString()}</span>
                            </>
                        );
                        const cls = cx('rounded-[var(--radius-card)] border bg-[color:var(--app-surface)] p-3 text-left',
                            card.attention ? 'border-amber-300 dark:border-amber-700' : 'border-[color:var(--app-border)]');
                        if (card.id === 'unrouted' && queue !== 'unrouted') {
                            return <Link key={card.id} href={route('service-submissions.unrouted')} className={cx(cls, 'hover:bg-[color:var(--app-surface-muted)] focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]')}>{body}</Link>;
                        }
                        return card.change && queue === 'all'
                            ? <button key={card.id} type="button" onClick={() => apply(card.change!)} className={cx(cls, 'hover:bg-[color:var(--app-surface-muted)] focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]')}>{body}</button>
                            : <div key={card.id} className={cls}>{body}</div>;
                    })}
                </section>

                <section aria-label={t('common.filter')} className="space-y-2 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-3">
                    <div className="relative">
                        <SearchIcon aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" />
                        <Input type="search" value={query} maxLength={100} onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('serviceFeedback.allSubmissionsSearch')} aria-label={t('serviceFeedback.searchLabel')} className="h-[var(--control-h-sm)] pl-9" />
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                        <Select className={selectCls} value={filters.submission_type ?? ''} onChange={(event) => apply({ submission_type: event.target.value })} aria-label={t('serviceFeedback.columnType')}>
                            <option value="">{t('serviceFeedback.allTypes')}</option>
                            {filterOptions.types.map((type) => <option key={type} value={type}>{t(TYPE_LABEL[type])}</option>)}
                        </Select>
                        {filterOptions.targets.length > 1 && (
                            <Select className={selectCls} value={filters.target_type ?? ''} onChange={(event) => apply({ target_type: event.target.value })} aria-label={t('serviceFeedback.columnTarget')}>
                                <option value="">{t('serviceFeedback.allTargets')}</option>
                                {filterOptions.targets.map((target) => <option key={target} value={target}>{t(target === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}</option>)}
                            </Select>
                        )}
                        <Select className={selectCls} value={filters.organization_id ?? ''} onChange={(event) => apply({ organization_id: event.target.value, organization_unit_id: '' })} aria-label={t('serviceFeedback.filterOrganization')}>
                            <option value="">{t('serviceFeedback.allOrganizations')}</option>
                            {filterOptions.organizations.map((org) => <option key={org.id} value={org.id}>{nameOf(org)}</option>)}
                        </Select>
                        {filterOptions.categories.length > 0 && (
                            <Select className={selectCls} value={filters.category_id ?? ''} onChange={(event) => apply({ category_id: event.target.value })} aria-label={t('serviceFeedback.category')}>
                                <option value="">{t('serviceFeedback.allCategories')}</option>
                                {filterOptions.categories.map((category) => <option key={category.id} value={category.id}>{nameOf(category)}</option>)}
                            </Select>
                        )}
                        {queue === 'all' && (
                            <Select className={selectCls} value={filters.status ?? ''} onChange={(event) => apply({ status: event.target.value })} aria-label={t('serviceFeedback.filterStatus')}>
                                <option value="">{t('serviceFeedback.allStatuses')}</option>
                                {filterOptions.statuses.map((status) => <option key={status} value={status}>{t(statusLabelKey(status))}</option>)}
                            </Select>
                        )}
                        <Select className={selectCls} value={filters.identity_mode ?? ''} onChange={(event) => apply({ identity_mode: event.target.value })} aria-label={t('serviceFeedback.identityModeLabel')}>
                            <option value="">{t('serviceFeedback.allIdentityModes')}</option>
                            {filterOptions.identityModes.map((mode) => <option key={mode} value={mode}>{t(`serviceFeedback.identityMode_${mode}`)}</option>)}
                        </Select>
                        <div className="w-full sm:w-[11rem]" role="group" aria-label={t('serviceFeedback.dateFrom')}>
                            <LocalizedDatePicker id="all-date-from" value={filters.date_from ?? ''} max={filters.date_to || undefined} placeholder={t('serviceFeedback.dateFrom')} onChange={(iso) => apply({ date_from: iso })} className="h-[var(--control-h-sm)] text-[13px]" />
                        </div>
                        <div className="w-full sm:w-[11rem]" role="group" aria-label={t('serviceFeedback.dateTo')}>
                            <LocalizedDatePicker id="all-date-to" value={filters.date_to ?? ''} min={filters.date_from || undefined} placeholder={t('serviceFeedback.dateTo')} onChange={(iso) => apply({ date_to: iso })} className="h-[var(--control-h-sm)] text-[13px]" />
                        </div>
                        <Select className={selectCls} value={filters.sort ?? 'newest'} onChange={(event) => apply({ sort: event.target.value })} aria-label={t('serviceFeedback.sortLabel')}>
                            {filterOptions.sorts.map((sort) => <option key={sort} value={sort}>{t(`serviceFeedback.sort_${sort}`)}</option>)}
                        </Select>
                        <label className="col-span-2 inline-flex min-h-9 items-center gap-2 text-sm text-[color:var(--app-foreground)] sm:col-span-1">
                            <input type="checkbox" checked={filled(filters.mine)} onChange={(event) => apply({ mine: event.target.checked ? '1' : '' })} className="h-4 w-4" />
                            {t('serviceFeedback.queueMine')}
                        </label>
                        {hasFilters && (
                            <button type="button" onClick={() => { setQuery(''); router.get(route(routeName), {}, { preserveScroll: true }); }}
                                className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-[color:var(--color-primary)] underline underline-offset-4">
                                <X aria-hidden="true" className="h-3.5 w-3.5" />{t('serviceFeedback.clearFilters')}
                            </button>
                        )}
                    </div>
                </section>

                <section className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <h2 className="sr-only">{title}</h2>
                    <p className="border-b border-[color:var(--app-border)] px-4 py-2.5 text-sm text-[color:var(--app-muted-foreground)]" aria-live="polite">
                        {t('serviceFeedback.showingRange').replace(':total', entries.total.toLocaleString()).replace(':from', String(entries.from ?? 0)).replace(':to', String(entries.to ?? 0))}
                    </p>
                    {entries.data.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                            <span className="flex h-11 w-11 items-center justify-center rounded-full bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]"><Inbox aria-hidden="true" className="h-5 w-5" /></span>
                            <p className="text-[15px] font-semibold text-[color:var(--app-foreground)]">
                                {t(hasFilters ? 'serviceFeedback.noMatches' : queue === 'unrouted' ? 'serviceFeedback.unroutedEmpty' : !hasAny ? 'serviceFeedback.noSubmissionsYet' : 'serviceFeedback.noSubmissions')}
                            </p>
                        </div>
                    ) : (
                        <>
                            <table className={cx(tableClassName, 'hidden md:table')}>
                                <thead className={tableHeadClassName}>
                                    <tr className="text-left">
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('serviceFeedback.columnReference')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('serviceFeedback.columnTarget')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden text-left lg:table-cell')}>{t('serviceFeedback.filterOrganization')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden text-left xl:table-cell')}>{t('serviceFeedback.category')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('serviceFeedback.filterStatus')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'text-left')}>{t('serviceFeedback.submittedDate')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'hidden text-left lg:table-cell')}>{t('serviceFeedback.currentHandler')}</th>
                                        <th scope="col" className={cx(tableHeaderCellClassName, 'w-10')}><span className="sr-only">{t('serviceFeedback.openSubmission')}</span></th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id} className="align-top hover:bg-[color:var(--app-surface-muted)]">
                                            <td className={tableCellClassName}>
                                                <Link href={entry.url} className="font-semibold text-[color:var(--app-foreground)] underline decoration-[color:var(--color-primary)] decoration-2 underline-offset-4 hover:decoration-[color:var(--app-foreground)]">
                                                    {entry.reference ?? t(TYPE_LABEL[entry.type])}
                                                </Link>
                                                <span className="mt-0.5 block text-xs text-[color:var(--app-muted-foreground)]">{t(TYPE_LABEL[entry.type])}</span>
                                            </td>
                                            <td className={tableCellClassName}>
                                                <span className="mb-1 inline-flex rounded-full bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--app-muted-foreground)]">{t(entry.target_type === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}</span>
                                                <span className="block font-medium text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{entry.target_name ?? '—'}</span>
                                                {entry.target_detail && <span className="block text-xs text-[color:var(--app-muted-foreground)]">{entry.target_detail}</span>}
                                            </td>
                                            <td className={cx(tableCellClassName, 'hidden lg:table-cell')}>
                                                <span className="block max-w-[14rem] text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{entry.organization ?? '—'}</span>
                                                {entry.organization_unit && <span className="block max-w-[14rem] text-xs text-[color:var(--app-muted-foreground)]">{entry.organization_unit}</span>}
                                            </td>
                                            <td className={cx(tableCellClassName, 'hidden xl:table-cell')}>{entry.category ?? <span className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.notCategorised')}</span>}</td>
                                            <td className={tableCellClassName}><FeedbackStatusBadge status={entry.status} /></td>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={entry.submitted_at} withTime /></td>
                                            <td className={cx(tableCellClassName, 'hidden lg:table-cell')}>{entry.handler ?? <span className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noHandler')}</span>}</td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <Link href={entry.url} aria-label={`${t('serviceFeedback.openSubmission')}: ${entry.reference ?? entry.target_name ?? ''}`} className="inline-flex h-8 w-8 items-center justify-center rounded-full hover:bg-[color:var(--app-surface-muted)]">
                                                    <ChevronRight aria-hidden="true" className="h-4 w-4 text-[color:var(--app-muted-foreground)]" />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <ul className="divide-y divide-[color:var(--app-border)] md:hidden">
                                {entries.data.map((entry) => (
                                    <li key={entry.id}>
                                        <Link href={entry.url} className="flex flex-col gap-1.5 px-4 py-4 active:bg-[color:var(--app-surface-muted)]">
                                            <span className="flex items-center justify-between gap-3">
                                                <span className="text-sm font-semibold text-[color:var(--app-foreground)]">{t(TYPE_LABEL[entry.type])}{entry.reference && <span className="ml-2 font-mono text-xs font-normal text-[color:var(--app-muted-foreground)]">{entry.reference}</span>}</span>
                                                <FeedbackStatusBadge status={entry.status} />
                                            </span>
                                            <span className="text-sm text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">
                                                {t(entry.target_type === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}: {entry.target_name ?? '—'}
                                            </span>
                                            <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {[entry.organization, entry.category].filter(Boolean).join(' · ')}{' · '}<LocalizedDateDisplay value={entry.submitted_at} />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    <ListPagination meta={entries} label={t('serviceFeedback.pagination')} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
