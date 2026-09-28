import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight, Layers, Plus, SearchIcon } from '@/Components/Icons';
import PaginatorLinks, { type PaginatorLink } from '@/Components/PaginatorLinks';
import {
    DemoTag,
    PROVIDER_STATUSES,
    ProviderAvatar,
    ProviderStatusBadge,
    StatusDot,
    TRANSACTION_DOTS,
    formatEtb,
    providerStatusKey,
    transactionStatusKey,
    useLocalName,
    type NamedRef,
} from '@/Components/ServiceProviders/providerUi';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Input, Select, buttonClassName, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState, type JSX, type ReactNode } from 'react';

type ProviderRow = {
    id: string;
    name: string;
    code: string;
    status: string;
    is_demo: boolean;
    service_type?: NamedRef;
    organization?: NamedRef;
    transactions_recent: number;
    last_transaction_at: string | null;
};

type TransactionRow = {
    id: string;
    status: string;
    reference?: string | null;
    amount?: string | number | null;
    occurred_at?: string | null;
    service_type?: NamedRef;
    service_provider?: { id: string; name: string; code: string } | null;
};

type Filters = { search: string; service_type_id: string | null; status: string | null };

type Props = {
    providers: { data: ProviderRow[]; links: PaginatorLink[]; from: number | null; to: number | null; total: number };
    filters: Filters;
    statusCounts: Record<string, number>;
    stats: {
        providers: Record<string, number>;
        service_types_in_use: number;
        transactions_recent: number;
        denied_recent: number;
        days: number;
    };
    serviceTypes: { id: string; code: string; name_en: string; name_am: string | null; providers_count: number }[];
    transactions: TransactionRow[];
    can: { create: boolean; viewServiceTypes: boolean };
};

const SEARCH_DELAY_MS = 350;

export default function ServiceProvidersIndex({ providers, filters, statusCounts, stats, serviceTypes, transactions, can }: Props): JSX.Element {
    const { t } = useLocale();
    const localName = useLocalName();
    const [search, setSearch] = useState(filters.search);

    useEffect(() => setSearch(filters.search), [filters.search]);

    // Filters round-trip through the server so counts, paging and results always agree.
    function visit(changes: Partial<Filters>) {
        const params = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== null && value !== undefined && value !== ''),
        );
        router.get(route('service-providers.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    }

    useEffect(() => {
        const term = search.trim();
        if (term === filters.search) return;

        const timer = window.setTimeout(() => visit({ search: term }), SEARCH_DELAY_MS);
        return () => window.clearTimeout(timer);
        // Only typing schedules a search; `filters` changing is its result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const tabHref = (status: string | null) => {
        const params = Object.fromEntries(
            Object.entries({ ...filters, status }).filter(([, value]) => value !== null && value !== ''),
        );
        return route('service-providers.index', params);
    };

    const totals = stats.providers;
    const notTransacting = (totals.inactive ?? 0) + (totals.suspended ?? 0);
    const deniedPercent = stats.transactions_recent > 0 ? Math.round((stats.denied_recent / stats.transactions_recent) * 1000) / 10 : 0;
    const hasFilters = filters.search !== '' || filters.service_type_id !== null || filters.status !== null;
    const maxTypeCount = Math.max(...serviceTypes.map((type) => type.providers_count), 1);
    const days = String(stats.days);

    const tabs = [{ id: null as string | null, label: t('providers.all') }].concat(
        PROVIDER_STATUSES.map((status) => ({ id: status, label: t(providerStatusKey(status)) })),
    );

    return (
        <AuthenticatedLayout>
            <Head title={t('providers.title')} />

            <div className="space-y-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('providers.eyebrow')}</p>
                        <h1 className="mt-1 text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{t('providers.title')}</h1>
                        <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('providers.subtitle')}</p>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {can.viewServiceTypes && (
                            <Link href={route('service-types.index')} className={buttonClassName({ variant: 'outline' })}>
                                <Layers aria-hidden="true" className="h-4 w-4" />
                                {t('providers.serviceTypes')}
                            </Link>
                        )}
                        {can.create && (
                            <Link href={route('service-providers.create')} className={buttonClassName({ variant: 'primary' })}>
                                <Plus aria-hidden="true" className="h-4 w-4" />
                                {t('providers.addProvider')}
                            </Link>
                        )}
                    </div>
                </header>

                {/* Headline figures */}
                <section aria-label={t('providers.title')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label={t('providers.statProviders')} value={(totals.all ?? 0).toLocaleString()}>
                        {t('providers.acrossServiceTypes').replace(':count', String(stats.service_types_in_use))}
                    </Stat>
                    <Stat label={<><StatusDot status="active" /> {t('providers.statusActive')}</>} value={(totals.active ?? 0).toLocaleString()}>
                        <span aria-hidden="true" className="block h-1.5 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]">
                            <span className="block h-full rounded-full bg-emerald-600" style={{ width: `${totals.all ? ((totals.active ?? 0) / totals.all) * 100 : 0}%` }} />
                        </span>
                    </Stat>
                    <Stat
                        highlight={notTransacting > 0}
                        label={<><StatusDot status={notTransacting > 0 ? 'suspended' : 'inactive'} /> {t('providers.notTransacting')}</>}
                        value={notTransacting.toLocaleString()}
                    >
                        {t('providers.inactiveSuspendedBreakdown')
                            .replace(':inactive', String(totals.inactive ?? 0))
                            .replace(':suspended', String(totals.suspended ?? 0))}
                    </Stat>
                    <Stat label={t('providers.transactionsRecent').replace(':days', days)} value={stats.transactions_recent.toLocaleString()}>
                        <span className={stats.denied_recent > 0 ? 'font-semibold text-red-700 dark:text-red-400' : undefined}>
                            {t('providers.deniedShare').replace(':count', stats.denied_recent.toLocaleString()).replace(':percent', String(deniedPercent))}
                        </span>
                    </Stat>
                </section>

                <div className="grid items-start gap-5 lg:grid-cols-12">
                    {/* Providers */}
                    <section aria-label={t('providers.title')} className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] lg:col-span-8">
                        <nav aria-label={t('providers.status')} className="overflow-x-auto border-b border-[color:var(--app-border)] px-2 pt-1 [scrollbar-width:none] sm:px-3 [&::-webkit-scrollbar]:hidden">
                            <ul className="flex min-w-max gap-1">
                                {tabs.map((tab) => {
                                    const active = tab.id === filters.status;
                                    return (
                                        <li key={tab.id ?? 'all'}>
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
                                                <span className={cx(
                                                    'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums',
                                                    active
                                                        ? 'bg-[color:var(--color-primary-100)] text-[color:var(--color-primary)] dark:bg-[color:var(--color-primary-900)] dark:text-[color:var(--color-primary-200)]'
                                                        : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
                                                )}>
                                                    {(statusCounts[tab.id ?? 'all'] ?? 0).toLocaleString()}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </nav>

                        <div className="flex flex-col gap-2 border-b border-[color:var(--app-border)] px-4 py-3 sm:flex-row">
                            <div className="relative min-w-0 flex-1">
                                <SearchIcon aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder={t('providers.searchPlaceholder')}
                                    aria-label={t('providers.searchProviders')}
                                    maxLength={100}
                                    className="h-[var(--control-h-sm)] pl-9"
                                />
                            </div>
                            <Select
                                value={filters.service_type_id ?? ''}
                                onChange={(event) => visit({ service_type_id: event.target.value || null })}
                                aria-label={t('providers.filterByType')}
                                className="h-[var(--control-h-sm)] sm:w-56"
                            >
                                <option value="">{t('providers.allServiceTypes')}</option>
                                {serviceTypes.map((type) => (
                                    <option key={type.id} value={type.id}>{localName(type)}</option>
                                ))}
                            </Select>
                        </div>

                        {providers.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                                <p className="text-sm text-[color:var(--app-muted-foreground)]">{hasFilters ? t('providers.noMatches') : t('providers.noProviders')}</p>
                                {hasFilters ? (
                                    <Link href={route('service-providers.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('providers.clearFilters')}</Link>
                                ) : can.create && (
                                    <Link href={route('service-providers.create')} className={buttonClassName({ variant: 'primary', size: 'sm' })}>{t('providers.addProvider')}</Link>
                                )}
                            </div>
                        ) : (
                            <>
                                <table className="hidden w-full text-sm md:table">
                                    <thead className="bg-[color:var(--app-surface-muted)]">
                                        <tr className="text-left text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                                            <th scope="col" className="px-4 py-2.5">{t('providers.columnProvider')}</th>
                                            <th scope="col" className="w-40 px-3 py-2.5">{t('providers.columnService')}</th>
                                            <th scope="col" className="w-28 px-3 py-2.5 text-right">{t('providers.columnRecent').replace(':days', days)}</th>
                                            <th scope="col" className="w-28 px-3 py-2.5">{t('providers.status')}</th>
                                            <th scope="col" className="w-10 py-2.5 pr-4"><span className="sr-only">{t('providers.openProvider')}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[color:var(--app-border)]">
                                        {providers.data.map((provider) => {
                                            const href = route('service-providers.show', provider.id);
                                            return (
                                                <tr
                                                    key={provider.id}
                                                    // The name cell holds a real link for keyboard users; let it handle its own clicks.
                                                    onClick={(event) => {
                                                        if (!(event.target as HTMLElement).closest('a')) router.visit(href);
                                                    }}
                                                    className="cursor-pointer transition-colors hover:bg-[color:var(--app-surface-muted)]"
                                                >
                                                    <td className="px-4 py-3">
                                                        <Link href={href} className="group flex items-center gap-3 focus-visible:outline-none">
                                                            <ProviderAvatar name={provider.name} typeCode={provider.service_type?.code} />
                                                            <span className="min-w-0">
                                                                <span className="flex items-center gap-2">
                                                                    <span className="truncate font-semibold text-[color:var(--app-foreground)] group-hover:underline group-focus-visible:underline">{provider.name}</span>
                                                                    {provider.is_demo && <DemoTag />}
                                                                </span>
                                                                <span className="block truncate text-xs text-[color:var(--app-muted-foreground)]">
                                                                    <span className="font-mono">{provider.code}</span> · {provider.organization ? localName(provider.organization) : t('providers.allOrganizations')}
                                                                </span>
                                                            </span>
                                                        </Link>
                                                    </td>
                                                    <td className="px-3 py-3 text-[13px] text-[color:var(--app-foreground)]">{localName(provider.service_type, t('providers.unknownService'))}</td>
                                                    <td className="px-3 py-3 text-right">
                                                        <div className="font-semibold tabular-nums text-[color:var(--app-foreground)]">{provider.transactions_recent.toLocaleString()}</div>
                                                        <div className="text-xs text-[color:var(--app-muted-foreground)]">
                                                            {provider.last_transaction_at ? <LocalizedDateDisplay value={provider.last_transaction_at} /> : t('providers.noActivity')}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-3"><ProviderStatusBadge status={provider.status} /></td>
                                                    <td className="py-3 pr-4 text-right">
                                                        <ChevronRight aria-hidden="true" className="ml-auto h-4 w-4 text-[color:var(--app-muted-foreground)]" />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>

                                <ul className="divide-y divide-[color:var(--app-border)] md:hidden">
                                    {providers.data.map((provider) => (
                                        <li key={provider.id}>
                                            <Link href={route('service-providers.show', provider.id)} className="flex items-center gap-3 px-4 py-3.5 active:bg-[color:var(--app-surface-muted)]">
                                                <ProviderAvatar name={provider.name} typeCode={provider.service_type?.code} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-semibold text-[color:var(--app-foreground)]">{provider.name}</span>
                                                        {provider.is_demo && <DemoTag />}
                                                    </span>
                                                    <span className="block truncate text-xs text-[color:var(--app-muted-foreground)]">
                                                        <span className="font-mono">{provider.code}</span> · {localName(provider.service_type, t('providers.unknownService'))}
                                                    </span>
                                                </span>
                                                <ProviderStatusBadge status={provider.status} />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}

                        {providers.total > 0 && (
                            <div className="flex flex-col gap-3 border-t border-[color:var(--app-border)] px-4 py-3 text-[13px] text-[color:var(--app-muted-foreground)] sm:flex-row sm:items-center sm:justify-between">
                                <span>
                                    {t('providers.showingRange')
                                        .replace(':from', String(providers.from ?? 0))
                                        .replace(':to', String(providers.to ?? 0))
                                        .replace(':total', providers.total.toLocaleString())}
                                </span>
                                <PaginatorLinks links={providers.links} label={t('providers.pagination')} />
                            </div>
                        )}
                    </section>

                    <aside className="space-y-5 lg:col-span-4">
                        {/* By service type: doubles as a one-click filter. */}
                        <section aria-labelledby="by-type" className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                            <div className="flex items-baseline justify-between gap-2">
                                <h2 id="by-type" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('providers.byServiceType')}</h2>
                                <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('providers.selectToFilter')}</span>
                            </div>
                            {serviceTypes.length === 0 ? (
                                <p className="mt-3 text-sm text-[color:var(--app-muted-foreground)]">{t('providers.noServiceTypes')}</p>
                            ) : (
                                <ul className="-mx-2 mt-3 space-y-0.5">
                                    {serviceTypes.map((type) => {
                                        const selected = filters.service_type_id === type.id;
                                        return (
                                            <li key={type.id}>
                                                <button
                                                    type="button"
                                                    aria-pressed={selected}
                                                    onClick={() => visit({ service_type_id: selected ? null : type.id })}
                                                    className={cx(
                                                        'flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                                        selected ? 'bg-[color:var(--color-primary-50)] dark:bg-[color:var(--color-primary-950)]' : 'hover:bg-[color:var(--app-surface-muted)]',
                                                    )}
                                                >
                                                    <span className={cx('min-w-0 flex-1 truncate text-[13px] text-[color:var(--app-foreground)]', selected ? 'font-semibold' : 'font-medium')}>{localName(type)}</span>
                                                    <span aria-hidden="true" className="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]">
                                                        <span className="block h-full rounded-full bg-[color:var(--color-primary-500)]" style={{ width: `${(type.providers_count / maxTypeCount) * 100}%` }} />
                                                    </span>
                                                    <span className="w-6 shrink-0 text-right text-[13px] font-semibold tabular-nums text-[color:var(--app-foreground)]">{type.providers_count}</span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>

                        <section aria-labelledby="recent-tx" className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                            <div className="flex items-baseline justify-between gap-2 border-b border-[color:var(--app-border)] px-5 py-4">
                                <h2 id="recent-tx" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('providers.recentTransactions')}</h2>
                                <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('providers.allProviders')}</span>
                            </div>
                            {transactions.length === 0 ? (
                                <p className="px-5 py-8 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('providers.noRecentTransactions')}</p>
                            ) : (
                                <ul className="divide-y divide-[color:var(--app-border)]">
                                    {transactions.map((tx) => (
                                        <li key={tx.id} className="flex gap-3 px-5 py-3">
                                            <span aria-hidden="true" className={cx('mt-1.5 h-2 w-2 shrink-0 rounded-full', TRANSACTION_DOTS[tx.status] ?? 'bg-slate-400')} />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex justify-between gap-2">
                                                    {tx.service_provider ? (
                                                        <Link href={route('service-providers.show', tx.service_provider.id)} className="truncate text-[13px] font-semibold text-[color:var(--app-foreground)] hover:underline">
                                                            {tx.service_provider.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="truncate text-[13px] font-semibold text-[color:var(--app-foreground)]">{t('providers.unknownProvider')}</span>
                                                    )}
                                                    <span className="whitespace-nowrap text-[13px] font-semibold tabular-nums text-[color:var(--app-foreground)]">{formatEtb(tx.amount)}</span>
                                                </span>
                                                <span className="flex justify-between gap-2 text-xs text-[color:var(--app-muted-foreground)]">
                                                    <span className="truncate">
                                                        {localName(tx.service_type, t('providers.unknownService'))}
                                                        {tx.occurred_at && <> · <LocalizedDateDisplay value={tx.occurred_at} withTime /></>}
                                                    </span>
                                                    <span className={cx('whitespace-nowrap font-medium', tx.status === 'denied' && 'text-red-700 dark:text-red-400')}>{t(transactionStatusKey(tx.status))}</span>
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, children, highlight = false }: { label: ReactNode; value: ReactNode; children: ReactNode; highlight?: boolean }): JSX.Element {
    return (
        <div className={cx(
            'flex flex-col gap-2.5 rounded-[var(--radius-panel)] border p-5',
            highlight ? 'border-amber-300 bg-amber-50 dark:border-amber-900/70 dark:bg-amber-950/30' : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]',
        )}>
            <span className={cx('flex items-center gap-2 text-[13px] font-medium', highlight ? 'text-amber-900 dark:text-amber-200' : 'text-[color:var(--app-muted-foreground)]')}>{label}</span>
            <span className={cx('text-3xl font-bold leading-none tabular-nums', highlight ? 'text-amber-800 dark:text-amber-300' : 'text-[color:var(--app-foreground)]')}>{value}</span>
            <span className={cx('text-xs', highlight ? 'text-amber-900/80 dark:text-amber-200/80' : 'text-[color:var(--app-muted-foreground)]')}>{children}</span>
        </div>
    );
}
