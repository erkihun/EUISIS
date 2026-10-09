import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HierarchyVersionForm from '@/Components/organizations/HierarchyVersionForm';
import { useLocale } from '@/hooks/useLocale';

type Version = {
    id: string;
    version_name: string;
    notes: string | null;
    source_document: string | null;
    status: string;
    effective_from: string | null;
    effective_to: string | null;
};

export default function EditHierarchyVersion({
    version,
    can,
}: {
    version: Version;
    can: { update: boolean };
}) {
    const { t } = useLocale();
    const readonly = !can.update;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('hierarchyVersions.editHierarchyVersion')}
                    description={version.version_name}
                />
            }
        >
            <Head title={t('hierarchyVersions.editHierarchyVersion')} />

            <div className="mx-auto max-w-4xl space-y-4">
                {readonly && (
                    <div className="rounded-card border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200">
                        {version.status === 'published'
                            ? t('hierarchyVersions.publishedReadonly')
                            : t('hierarchyVersions.archivedReadonly')}
                    </div>
                )}

                <HierarchyVersionForm
                    mode="edit"
                    readonly={readonly}
                    submitRoute={route('hierarchy-versions.update', { hierarchyVersion: version.id })}
                    initialValues={{
                        version_name: version.version_name,
                        effective_from: version.effective_from ?? '',
                        effective_to: version.effective_to ?? '',
                        source_document: version.source_document ?? '',
                        notes: version.notes ?? '',
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}
