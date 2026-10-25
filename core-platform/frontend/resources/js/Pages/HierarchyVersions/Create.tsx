import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HierarchyVersionForm from '@/Components/organizations/HierarchyVersionForm';
import { useLocale } from '@/hooks/useLocale';

export default function CreateHierarchyVersion() {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form"
            header={
                <PageHeader
                    title={t('hierarchyVersions.createHierarchyVersion')}
                    description={t('hierarchyVersions.createDescription')}
                />
            }
        >
            <Head title={t('hierarchyVersions.createHierarchyVersion')} />

            <div>
                <HierarchyVersionForm
                    mode="create"
                    submitRoute={route('hierarchy-versions.store')}
                    initialValues={{
                        version_name: '',
                        effective_from: '',
                        effective_to: '',
                        source_document: '',
                        notes: '',
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}
