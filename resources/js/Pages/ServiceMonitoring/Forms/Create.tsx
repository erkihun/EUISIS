import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import type { BuilderLimits } from '@/Components/serviceMonitoring/FormBuilder';
import FormEditor from '@/Components/serviceMonitoring/FormEditor';
import type { Named } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function ServiceMonitoringFormCreate({ organizationTypes, limits }: { organizationTypes: Named[]; limits: BuilderLimits }): JSX.Element {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceMonitoring.forms.createTitle')} description={t('serviceMonitoring.forms.createDescription')} backHref={route('service-monitoring.forms.index')} />}>
            <Head title={t('serviceMonitoring.forms.createTitle')} />
            <FormEditor organizationTypes={organizationTypes} limits={limits} />
        </AuthenticatedLayout>
    );
}
