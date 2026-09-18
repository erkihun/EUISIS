import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type ServiceTypeRow = { id: string; name_en: string; code: string };
type ProviderRow = {
    id: string;
    name: string;
    code: string;
    status: string;
    service_type?: { name_en: string } | null;
    organization?: { name_en: string } | null;
};
type TransactionRow = {
    id: string;
    status: string;
    reference?: string | null;
    amount?: string | number | null;
    service_type?: { name_en: string } | null;
    service_provider?: { name: string } | null;
};

export default function ServiceProvidersIndex({
    serviceTypes,
    providers,
    transactions,
    can,
}: {
    serviceTypes: ServiceTypeRow[];
    providers: ProviderRow[];
    transactions: TransactionRow[];
    can: { create: boolean };
}) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between gap-4">
                    <PageHeader title={t('providers.title')} description="" />
                    {can.create && (
                        <Link
                            href={route('service-providers.create')}
                            className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
                        >
                            + {t('providers.addProvider')}
                        </Link>
                    )}
                </div>
            }
        >
            <Head title={t('providers.title')} />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('providers.serviceTypes')}
                    </h3>
                    {serviceTypes.length === 0 ? (
                        <div className="mt-4">
                            <EmptyState title={t('providers.noServiceTypes')} />
                        </div>
                    ) : (
                        <ul className="mt-4 space-y-2 text-sm">
                            {serviceTypes.map((st) => (
                                <li
                                    key={st.id}
                                    className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-slate-800"
                                >
                                    <span className="font-medium text-gray-800 dark:text-slate-200">
                                        {st.name_en}
                                    </span>
                                    <span className="font-mono text-xs text-gray-400 dark:text-slate-500">
                                        {st.code}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('providers.title')}
                    </h3>
                    {providers.length === 0 ? (
                        <div className="mt-4">
                            <EmptyState title={t('providers.noProviders')} />
                        </div>
                    ) : (
                        <ul className="mt-4 space-y-3 text-sm">
                            {providers.map((p) => (
                                <li
                                    key={p.id}
                                    className="rounded-card border border-gray-100 bg-gray-50 p-3 dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <Link
                                            href={route('service-providers.show', p.id)}
                                            className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                        >
                                            {p.name}
                                        </Link>
                                        <StatusBadge status={p.status} />
                                    </div>
                                    <p className="mt-1 font-mono text-xs text-gray-400 dark:text-slate-500">
                                        {p.code}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                        {p.service_type?.name_en ?? t('providers.unknownService')} ·{' '}
                                        {p.organization?.name_en ?? t('providers.noOrg')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('providers.recentTransactions')}
                    </h3>
                    {transactions.length === 0 ? (
                        <div className="mt-4">
                            <EmptyState title={t('providers.noRecentTransactions')} />
                        </div>
                    ) : (
                        <ul className="mt-4 space-y-3 text-sm">
                            {transactions.map((tx) => (
                                <li
                                    key={tx.id}
                                    className="rounded-card border border-gray-100 bg-gray-50 p-3 dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="font-medium text-gray-800 dark:text-slate-200">
                                            {tx.service_provider?.name ?? t('providers.unknownProvider')}
                                        </p>
                                        <StatusBadge status={tx.status} />
                                    </div>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                        {tx.service_type?.name_en ?? t('providers.unknownService')}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                        {tx.reference ?? '—'} ·{' '}
                                        {tx.amount != null ? `ETB ${tx.amount}` : '—'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
