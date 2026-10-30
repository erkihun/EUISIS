import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import type { BuilderLimits } from '@/Components/serviceMonitoring/FormBuilder';
import FormEditor, { type EditableForm } from '@/Components/serviceMonitoring/FormEditor';
import type { Named } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function ServiceMonitoringFormEdit({ form, organizationTypes, limits }: { form: EditableForm & { version: number }; organizationTypes: Named[]; limits: BuilderLimits }): JSX.Element {
    const { t } = useLocale();
    const title = `${t('serviceMonitoring.forms.editTitle')} — ${form.code} v${form.version}`;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={t('serviceMonitoring.forms.editDescription')} backHref={route('service-monitoring.forms.show', form.id)} />}>
            <Head title={title} />
            <FormEditor form={form} organizationTypes={organizationTypes} limits={limits} />
        </AuthenticatedLayout>
    );
}
