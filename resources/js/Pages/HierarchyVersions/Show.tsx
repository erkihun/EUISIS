import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import HierarchyTreePreview from '@/Components/organizations/HierarchyTreePreview';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type TreeNode = {
    id: string;
    code: string;
    name_en: string;
    children: TreeNode[];
};

type Edge = {
    id: string;
    relationship_type: string;
    effective_from: string | null;
    effective_to: string | null;
    parent_organization: { name_en: string; code: string } | null;
    child_organization: { name_en: string; code: string } | null;
};

type Version = {
    id: string;
    version_name: string;
    notes: string | null;
    source_document: string | null;
    status: string;
    effective_from: string | null;
    effective_to: string | null;
    approval_date: string | null;
    edges_count: number;
    approver?: { name: string } | null;
};

export default function HierarchyVersionShow({
    version,
    edges,
    tree,
    can,
}: {
    version: Version;
    edges: Edge[];
    tree: TreeNode[];
    can: { edit: boolean; archive: boolean; publish: boolean; manageTree: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const publishForm = useForm({});
    const archiveForm = useForm({});
    const versionId = version?.id;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('hierarchy-versions.index')}
                    title={t('hierarchyVersions.hierarchyVersionDetails')}
                    description={version.version_name}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={versionId ? route('hierarchy-versions.tree', { hierarchyVersion: versionId }) : '#'}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                {t('hierarchyVersions.viewTree')}
                            </Link>
                            {can.edit && (
                                <Link
                                    href={versionId ? route('hierarchy-versions.edit', { hierarchyVersion: versionId }) : '#'}
                                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                            {can.manageTree && (
                                <Link
                                    href={versionId ? route('hierarchy-versions.tree.edit', { hierarchyVersion: versionId }) : '#'}
                                    className="rounded-lg border border-blue-200 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300 dark:hover:bg-blue-950/30"
                                >
                                    {t('hierarchyVersions.editTree')}
                                </Link>
                            )}
                            {can.publish && (
                                <button
                                    type="button"
                                    onClick={async () => {
                                        const { confirmed } = await confirm({
                                            title: t('confirmations.confirmPublishTitle'),
                                            description: t('confirmations.publishedVersionsCannotBeEdited'),
                                            confirmLabel: t('confirmations.publish'),
                                            cancelLabel: t('confirmations.cancel'),
                                            variant: 'warning',
                                        });
                                        if (confirmed) publishForm.post(route('hierarchy-versions.publish', { hierarchyVersion: version.id }));
                                    }}
                                    className="rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-amber-600"
                                >
                                    {t('hierarchyVersions.publishVersion')}
                                </button>
                            )}
                            {can.archive && (
                                <button
                                    type="button"
                                    onClick={async () => {
                                        const { confirmed } = await confirm({
                                            title: t('confirmations.confirmArchiveTitle'),
                                            description: t('confirmations.thisActionCannotBeUndone'),
                                            confirmLabel: t('confirmations.archive'),
                                            cancelLabel: t('confirmations.cancel'),
                                            variant: 'danger',
                                        });
                                        if (confirmed) archiveForm.post(route('hierarchy-versions.archive', { hierarchyVersion: version.id }));
                                    }}
                                    className="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950/20"
                                >
                                    {t('hierarchyVersions.archiveVersion')}
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={version.version_name} />

            <div className="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium text-gray-400 dark:text-slate-500">
                                {t('hierarchyVersions.versionName')}
                            </p>
                            <h3 className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                {version.version_name}
                            </h3>
                        </div>
                        <StatusBadge status={version.status} />
                    </div>

                    <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('hierarchyVersions.effectiveFrom')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                <LocalizedDateDisplay value={version.effective_from} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('hierarchyVersions.effectiveTo')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                <LocalizedDateDisplay value={version.effective_to} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('hierarchyVersions.edgeCount')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                {version.edges_count}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('hierarchyVersions.publishedBy')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                {version.approver?.name ?? '—'}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('hierarchyVersions.sourceDocument')}
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800 dark:text-slate-200">
                                {version.source_document ?? '—'}
                            </dd>
                        </div>
                    </dl>

                    <div className="mt-6">
                        <p className="text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('hierarchyVersions.notes')}
                        </p>
                        <p className="mt-1 text-sm leading-6 text-gray-700 dark:text-slate-300">
                            {version.notes ?? '—'}
                        </p>
                    </div>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        {t('hierarchyVersions.viewTree')}
                    </h3>
                    <div className="mt-4">
                        <HierarchyTreePreview
                            nodes={tree}
                            emptyMessage={t('hierarchyVersions.noHierarchyVersionsFound')}
                        />
                    </div>
                </section>
            </div>

            <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                    {t('hierarchyVersions.edgeCount')}
                </h3>
                <div className="mt-4 overflow-hidden rounded-xl border border-gray-100 dark:border-slate-800">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('hierarchyVersions.parentOrganization'), t('hierarchyVersions.childOrganization'), t('organizations.relationshipType')].map((heading) => (
                                    <th
                                        key={heading}
                                        className="px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {edges.map((edge) => (
                                <tr
                                    key={edge.id}
                                    className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                >
                                    <td className="px-4 py-3">{edge.parent_organization?.name_en ?? '—'}</td>
                                    <td className="px-4 py-3">{edge.child_organization?.name_en ?? '—'}</td>
                                    <td className="px-4 py-3">{edge.relationship_type}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
