import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronRight, PencilIcon, TrashIcon } from '@/Components/Icons';
import {
    DemoTag,
    ProviderAvatar,
    ProviderStatusBadge,
    TransactionStatusBadge,
    formatEtb,
    transactionStatusKey,
    useLocalName,
    type NamedRef,
} from '@/Components/ServiceProviders/providerUi';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Button, buttonClassName, cx } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState, type JSX, type ReactNode } from 'react';

type Provider = {
    id: string;
    name: string;
    code: string;
    status: string;
    is_demo: boolean;
    created_at: string | null;
    updated_at: string | null;
    service_type?: NamedRef;
    organization?: NamedRef;
};

type Transaction = {
    id: string;
    status: string;
    reference?: string | null;
    amount?: string | number | null;
    occurred_at?: string | null;
    service_type?: NamedRef;
};

type Props = {
    provider: Provider;
    transactions: Transaction[];
    stats: {
        transactions_recent: number;
        denied_recent: number;
        processed_recent: number;
        last_activity_at: string | null;
        days: number;
    };
    can: { update: boolean; delete: boolean };
};

const FILTERS = ['all', 'settled', 'authorized', 'denied', 'reversed', 'pending_sync'] as const;

export default function ServiceProvidersShow({ provider, transactions, stats, can }: Props): JSX.Element {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const localName = useLocalName();
    const [filter, setFilter] = useState<(typeof FILTERS)[number]>('all');
    const [deleting, setDeleting] = useState(false);

    // Only offer the statuses this provider actually has, so no filter leads to an empty table.
    const available = useMemo(() => {
        const present = new Set(transactions.map((tx) => tx.status));
        return FILTERS.filter((status) => status === 'all' || present.has(status));
    }, [transactions]);

    const rows = filter === 'all' ? transactions : transactions.filter((tx) => tx.status === filter);
    const days = String(stats.days);
    const deniedPercent = stats.transactions_recent > 0 ? Math.round((stats.denied_recent / stats.transactions_recent) * 1000) / 10 : 0;

    async function handleDelete() {
        const result = await confirm({ title: t('providers.confirmDelete'), variant: 'danger' });
        if (!result.confirmed) return;

        router.delete(route('service-providers.destroy', provider.id), {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title={provider.name} />

            <div className="space-y-6">
                <header className="space-y-3.5">
                    <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1.5 text-[13px] text-[color:var(--app-muted-foreground)]">
                        <Link href={route('service-providers.index')} className="hover:text-[color:var(--app-foreground)]">{t('providers.title')}</Link>
                        <ChevronRight aria-hidden="true" className="h-3.5 w-3.5" />
                        <span aria-current="page" className="font-medium text-[color:var(--app-foreground)]">{provider.name}</span>
                    </nav>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-center gap-4">
                            <ProviderAvatar name={provider.name} typeCode={provider.service_type?.code} size="lg" />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <h1 className="text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{provider.name}</h1>
                                    <ProviderStatusBadge status={provider.status} />
                                    {provider.is_demo && <DemoTag />}
                                </div>
                                <p className="mt-1 text-[13px] text-[color:var(--app-muted-foreground)]">
                                    <span className="font-mono text-[color:var(--app-foreground)]">{provider.code}</span>
                                    {' · '}{localName(provider.service_type, t('providers.unknownService'))}
                                    {' · '}{provider.organization ? localName(provider.organization) : t('providers.allOrganizations')}
                                </p>
                            </div>
                        </div>
                        {can.update && (
                            <Link href={route('service-providers.edit', provider.id)} className={buttonClassName({ variant: 'outline', className: 'self-start sm:self-auto' })}>
                                <PencilIcon aria-hidden="true" className="h-4 w-4" />
                                {t('providers.editProviderAction')}
                            </Link>
                        )}
                    </div>
                </header>

                <section aria-label={t('providers.lastActivity')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label={t('providers.transactionsRecent').replace(':days', days)}>
                        {stats.transactions_recent.toLocaleString()}
                    </Stat>
                    <Stat label={t('providers.valueProcessed').replace(':days', days)}>
                        <span className="text-[15px] font-semibold text-[color:var(--app-muted-foreground)]">ETB </span>
                        {stats.processed_recent.toLocaleString('en-US', { maximumFractionDigits: 0 })}
                    </Stat>
                    <Stat label={t('providers.deniedRecent').replace(':days', days)}>
                        <span className="flex items-baseline gap-2">
                            <span className={stats.denied_recent > 0 ? 'text-red-700 dark:text-red-400' : undefined}>{stats.denied_recent.toLocaleString()}</span>
                            {stats.transactions_recent > 0 && (
                                <span className={cx('text-[13px] font-semibold', stats.denied_recent > 0 ? 'text-red-700 dark:text-red-400' : 'text-[color:var(--app-muted-foreground)]')}>{deniedPercent}%</span>
                            )}
                        </span>
                    </Stat>
                    <Stat label={t('providers.lastActivity')} small>
                        {stats.last_activity_at ? <LocalizedDateDisplay value={stats.last_activity_at} withTime /> : t('providers.noActivity')}
                    </Stat>
                </section>

                <div className="grid items-start gap-5 lg:grid-cols-12">
                    <section aria-labelledby="transactions-heading" className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] lg:col-span-8">
                        <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                            <h2 id="transactions-heading" className="text-[15px] font-semibold text-[color:var(--app-foreground)]">{t('providers.transactions')}</h2>
                            {available.length > 2 && (
                                <div role="group" aria-label={t('providers.filterByStatus')} className="flex max-w-full gap-0.5 self-start overflow-x-auto rounded-lg bg-[color:var(--app-surface-muted)] p-[3px] sm:self-auto">
                                    {available.map((status) => {
                                        const selected = filter === status;
                                        return (
                                            <button
                                                key={status}
                                                type="button"
                                                aria-pressed={selected}
                                                onClick={() => setFilter(status)}
                                                className={cx(
                                                    'h-7 whitespace-nowrap rounded-md px-3 text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                                    selected
                                                        ? 'bg-[color:var(--app-surface)] font-semibold text-[color:var(--app-foreground)] shadow-sm ring-1 ring-[color:var(--app-border)]'
                                                        : 'font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]',
                                                )}
                                            >
                                                {status === 'all' ? t('providers.all') : t(transactionStatusKey(status))}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {rows.length === 0 ? (
                            <p className="px-5 py-12 text-center text-sm text-[color:var(--app-muted-foreground)]">
                                {transactions.length === 0 ? t('providers.noRecentTransactions') : t('providers.noTransactionsWithStatus')}
                            </p>
                        ) : (
                            <div className="max-h-[40rem] overflow-auto">
                                <table className="w-full min-w-[34rem] text-sm">
                                    <thead className="sticky top-0 z-[1] bg-[color:var(--app-surface-muted)]">
                                        <tr className="text-left text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                                            <th scope="col" className="w-40 px-5 py-2.5">{t('providers.columnWhen')}</th>
                                            <th scope="col" className="px-3 py-2.5">{t('providers.reference')}</th>
                                            <th scope="col" className="w-32 px-3 py-2.5 text-right">{t('providers.amount')}</th>
                                            <th scope="col" className="w-36 py-2.5 pl-3 pr-5">{t('providers.status')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[color:var(--app-border)]">
                                        {rows.map((tx) => {
                                            const voided = tx.status === 'denied' || tx.status === 'reversed';
                                            return (
                                                <tr key={tx.id}>
                                                    <td className="whitespace-nowrap px-5 py-3 text-[13px] text-[color:var(--app-foreground)]">
                                                        <LocalizedDateDisplay value={tx.occurred_at} withTime />
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        <div className="font-mono text-[13px] text-[color:var(--app-foreground)]">{tx.reference || '—'}</div>
                                                        <div className="text-xs text-[color:var(--app-muted-foreground)]">{localName(tx.service_type, t('providers.unknownService'))}</div>
                                                    </td>
                                                    <td className={cx('px-3 py-3 text-right font-semibold tabular-nums', voided ? 'text-[color:var(--app-muted-foreground)] line-through decoration-1' : 'text-[color:var(--app-foreground)]')}>
                                                        {formatEtb(tx.amount)}
                                                    </td>
                                                    <td className="py-3 pl-3 pr-5"><TransactionStatusBadge status={tx.status} /></td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {transactions.length > 0 && (
                            <p className="border-t border-[color:var(--app-border)] px-5 py-3 text-xs text-[color:var(--app-muted-foreground)]">
                                {t('providers.latestTransactions').replace(':count', String(transactions.length))}
                            </p>
                        )}
                    </section>

                    <aside className="space-y-5 lg:col-span-4">
                        <section aria-labelledby="details-heading" className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                            <h2 id="details-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{t('providers.details')}</h2>
                            <dl className="mt-3.5 space-y-3.5 text-sm">
                                <Detail label={t('providers.code')}><span className="font-mono">{provider.code}</span></Detail>
                                <Detail label={t('providers.serviceType')}>{localName(provider.service_type, t('providers.unknownService'))}</Detail>
                                <Detail label={t('providers.serves')}>
                                    {provider.organization
                                        ? t('providers.servesOrg').replace(':org', localName(provider.organization))
                                        : t('providers.servesAll')}
                                </Detail>
                                <Detail label={t('providers.data')}>{provider.is_demo ? t('providers.demoData') : t('providers.live')}</Detail>
                                <Detail label={t('providers.added')}><LocalizedDateDisplay value={provider.created_at} /></Detail>
                                <Detail label={t('providers.lastUpdated')}><LocalizedDateDisplay value={provider.updated_at} /></Detail>
                            </dl>
                        </section>

                        {can.delete && (
                            <section aria-labelledby="danger-heading" className="space-y-2.5 rounded-[var(--radius-panel)] border border-red-200 bg-[color:var(--app-surface)] p-5 dark:border-red-900/60">
                                <h2 id="danger-heading" className="text-xs font-semibold uppercase tracking-[0.06em] text-red-700 dark:text-red-400">{t('providers.dangerZone')}</h2>
                                <p className="text-[13px] leading-relaxed text-[color:var(--app-muted-foreground)]">{t('providers.deleteHint')}</p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full border-red-300 text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                    loading={deleting}
                                    onClick={handleDelete}
                                    icon={<TrashIcon className="h-4 w-4" />}
                                >
                                    {t('providers.delete')}
                                </Button>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, children, small = false }: { label: string; children: ReactNode; small?: boolean }): JSX.Element {
    return (
        <div className="flex flex-col gap-2 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-5 py-[18px]">
            <span className="text-[13px] font-medium text-[color:var(--app-muted-foreground)]">{label}</span>
            <span className={cx('font-bold tabular-nums text-[color:var(--app-foreground)]', small ? 'text-lg leading-snug' : 'text-[28px] leading-none')}>{children}</span>
        </div>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 font-medium text-[color:var(--app-foreground)]">{children}</dd>
        </div>
    );
}
