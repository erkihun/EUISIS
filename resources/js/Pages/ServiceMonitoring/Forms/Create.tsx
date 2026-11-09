import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import TemplateEditor, { type EditorProps } from '@/Components/serviceMonitoring/builder/TemplateEditor';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function FormTemplateCreate(props: EditorProps): JSX.Element {
    const { t } = useLocale();
    const title = t('serviceMonitoring.templates.createTitle');

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={t('serviceMonitoring.templates.createDescription')} backHref={route('service-monitoring.forms.index')} />}>
            <Head title={title} />
            <TemplateEditor {...props} />
        </AuthenticatedLayout>
    );
}
