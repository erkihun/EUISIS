import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { ReportView, TransportFilters } from '@/Components/transport/TransportData';
import { useLocale } from '@/hooks/useLocale';
import type { ProviderOption, ReportFilters, TransportReport } from '@/types/transport';

export default function Index({ report, filters, providers, can }: { report: TransportReport; filters: ReportFilters; providers: ProviderOption[]; can: { viewTransactions: boolean } }) {
    const { t } = useLocale();
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value));

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.reports')} description={t('transport.reportsIntro')} />}>
            <Head title={t('transport.reports')} />
            <div className="space-y-6">
                <TransportFilters routeName="transport.reports.index" filters={filters} providers={providers} />
                <ReportView report={report} transactionsHref={can.viewTransactions ? route('transport.transactions.index', query) : undefined} />
            </div>
        </AuthenticatedLayout>
    );
}
