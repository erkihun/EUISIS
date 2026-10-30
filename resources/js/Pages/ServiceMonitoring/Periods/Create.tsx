import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PeriodForm from '@/Components/serviceMonitoring/PeriodForm';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function ServiceMonitoringPeriodCreate(): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceMonitoring.periods.createTitle')} description={t('serviceMonitoring.periods.createDescription')} backHref={route('service-monitoring.periods.index')} />}>
            <Head title={t('serviceMonitoring.periods.createTitle')} />
            <PeriodForm />
        </AuthenticatedLayout>
    );
}
