import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { ReportView, TransportFilters } from '@/Components/transport/TransportData';
import { useLocale } from '@/hooks/useLocale';
import type { ReportFilters, TransportReport } from '@/types/transport';

export default function Index({ report, filters }: { report: TransportReport; filters: ReportFilters }) {
    const { t } = useLocale();
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value));

    return (
        <TransportProviderLayout title={t('transport.reports')}>
            <div className="space-y-6">
                <TransportFilters routeName="provider.portal.transport.reports.index" filters={filters} />
                <ReportView report={report} transactionsHref={route('provider.portal.transport.transactions.index', query)} />
            </div>
        </TransportProviderLayout>
    );
}
