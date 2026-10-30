import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PeriodForm, { type Period } from '@/Components/serviceMonitoring/PeriodForm';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function ServiceMonitoringPeriodEdit({ period }: { period: Period }): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceMonitoring.periods.editTitle')} description={t('serviceMonitoring.periods.editDescription')} backHref={route('service-monitoring.periods.index')} />}>
            <Head title={t('serviceMonitoring.periods.editTitle')} />
            <PeriodForm period={period} />
        </AuthenticatedLayout>
    );
}
