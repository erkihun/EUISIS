import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { TransactionsTable, TransportFilters } from '@/Components/transport/TransportData';
import { useLocale } from '@/hooks/useLocale';
import type { Paginated, ProviderOption, ReportFilters, TransportTransaction } from '@/types/transport';

export default function Index({ transactions, filters, providers, can }: { transactions: Paginated<TransportTransaction>; filters: ReportFilters; providers: ProviderOption[]; can: { export: boolean } }) {
    const { t } = useLocale();
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value));

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.transactionsAdmin')} description={t('transport.transactionsIntro')} />}>
            <Head title={t('transport.transactionsAdmin')} />
            <div className="space-y-4">
                <TransportFilters routeName="transport.transactions.index" filters={filters} providers={providers} exportHref={can.export ? route('transport.transactions.export', query) : undefined} />
                <TransactionsTable transactions={transactions} routeName="transport.transactions.index" showProvider />
            </div>
        </AuthenticatedLayout>
    );
}
