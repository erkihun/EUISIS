import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import HierarchyTreePreview from '@/Components/organizations/HierarchyTreePreview';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-1.5 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                            >
                                {t('hierarchyVersions.viewTree')}
                            </Link>
                            {can.edit && (
                                <Link
                                    href={versionId ? route('hierarchy-versions.edit', { hierarchyVersion: versionId }) : '#'}
                                    className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-1.5 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
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
                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
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
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.versionName')}
                            </p>
                            <h3 className="mt-1 text-2xl font-semibold text-[color:var(--app-foreground)]">
                                {version.version_name}
                            </h3>
                        </div>
                        <StatusBadge status={version.status} />
                    </div>

                    <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.effectiveFrom')}
                            </dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                <LocalizedDateDisplay value={version.effective_from} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.effectiveTo')}
                            </dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                <LocalizedDateDisplay value={version.effective_to} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.edgeCount')}
                            </dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                {version.edges_count}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.publishedBy')}
                            </dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                {version.approver?.name ?? '—'}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('hierarchyVersions.sourceDocument')}
                            </dt>
                            <dd className="mt-1 text-sm text-[color:var(--app-foreground)]">
                                {version.source_document ?? '—'}
                            </dd>
                        </div>
                    </dl>

                    <div className="mt-6">
                        <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                            {t('hierarchyVersions.notes')}
                        </p>
                        <p className="mt-1 text-sm leading-6 text-[color:var(--app-foreground)]">
                            {version.notes ?? '—'}
                        </p>
                    </div>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h3 className="font-semibold text-[color:var(--app-foreground)]">
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

            <section className="mt-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                <h3 className="font-semibold text-[color:var(--app-foreground)]">
                    {t('hierarchyVersions.edgeCount')}
                </h3>
                <div className="mt-4 overflow-hidden rounded-card border border-[color:var(--app-border)]">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('hierarchyVersions.parentOrganization'), t('hierarchyVersions.childOrganization'), t('organizations.relationshipType')].map((heading) => (
                                    <th
                                        key={heading}
                                        className={cx(tableHeaderCellClassName, 'text-left')}
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {edges.map((edge) => (
                                <tr
                                    key={edge.id}
                                    className={tableRowClassName}
                                >
                                    <td className={tableCellClassName}>{edge.parent_organization?.name_en ?? '—'}</td>
                                    <td className={tableCellClassName}>{edge.child_organization?.name_en ?? '—'}</td>
                                    <td className={tableCellClassName}>{edge.relationship_type}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
