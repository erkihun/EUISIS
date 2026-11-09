import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { TransactionsTable, TransportFilters } from '@/Components/transport/TransportData';
import { useLocale } from '@/hooks/useLocale';
import type { Paginated, ReportFilters, TransportTransaction } from '@/types/transport';

type PortalFilters = { date_from: string | null; date_to: string | null; status: ReportFilters['status'] };

export default function Index({ transactions, filters }: { transactions: Paginated<TransportTransaction>; filters: PortalFilters }) {
    const { t } = useLocale();
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value));

    return (
        <TransportProviderLayout title={t('transport.transactionsAdmin')}>
            <div className="space-y-4">
                <TransportFilters
                    routeName="provider.portal.transport.transactions.index"
                    filters={{ date_from: filters.date_from ?? '', date_to: filters.date_to ?? '', status: filters.status }}
                    exportHref={route('provider.portal.transport.transactions.export.csv', query)}
                />
                <TransactionsTable
                    transactions={transactions}
                    routeName="provider.portal.transport.transactions.index"
                    showHref={(row) => route('provider.portal.transport.transactions.show', row.id)}
                />
            </div>
        </TransportProviderLayout>
    );
}
