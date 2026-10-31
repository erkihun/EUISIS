import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import TemplateEditor, { type EditableTemplate, type EditorProps } from '@/Components/serviceMonitoring/builder/TemplateEditor';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

export default function FormTemplateEdit({ template, ...props }: EditorProps & { template: EditableTemplate }): JSX.Element {
    const { t } = useLocale();
    const title = `${t('serviceMonitoring.templates.editTitle')} — ${template.code} v${template.version}`;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={t('serviceMonitoring.templates.editDescription')} backHref={route('service-monitoring.forms.show', template.id)} />}>
            <Head title={title} />
            <TemplateEditor template={template} {...props} />
        </AuthenticatedLayout>
    );
}
