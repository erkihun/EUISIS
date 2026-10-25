import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { FilterField, FilterPanel, Input, ListPagination, Select, TableCard, buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
type Version = {
    id: string;
    version_name: string;
    status: string;
    approval_date: string | null;
    effective_from: string | null;
    effective_to: string | null;
    created_at: string;
    edges_count: number;
    approver?: {
        name: string;
    } | null;
    can: {
        view: boolean;
        update: boolean;
        archive: boolean;
        publish: boolean;
        manageTree: boolean;
    };
};

export default function HierarchyVersionsIndex({
    versions,
    filters,
    can,
}: {
    versions: {
        data: Version[];
        meta: LaravelPageMeta;
    };
    filters: {
        search: string | null;
        status: string | null;
        effective_from: string | null;
    };
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const filterForm = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        effective_from: filters.effective_from ?? '',
    });

    const hasActiveFilters = Boolean(filters.search || filters.status || filters.effective_from);

    function submitFilters() {
        router.get(route('hierarchy-versions.index'), filterForm.data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('hierarchyVersions.hierarchyVersions')}
                    description={t('hierarchyVersions.description')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('hierarchy-versions.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('hierarchyVersions.createHierarchyVersion')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('hierarchyVersions.hierarchyVersions')} />

            <FilterPanel onSubmit={submitFilters} onReset={() => router.get(route('hierarchy-versions.index'))} hasActiveFilters={hasActiveFilters} className="mb-4">
                <FilterField label={t('common.search')} wide>
                    <Input type="search" placeholder={t('hierarchyVersions.searchHierarchyVersions')} value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">{t('common.allStatuses')}</option>
                        <option value="draft">{t('hierarchyVersions.draft')}</option>
                        <option value="published">{t('hierarchyVersions.published')}</option>
                        <option value="archived">{t('hierarchyVersions.archived')}</option>
                    </Select>
                </FilterField>
                <FilterField label={t('common.effectiveFrom')} htmlFor="versions-effective-from">
                    <LocalizedDatePicker id="versions-effective-from" className={cx(inputClassName, 'w-full')} value={filterForm.data.effective_from} onChange={(iso) => filterForm.setData('effective_from', iso)} />
                </FilterField>
            </FilterPanel>

            <TableCard title={t('hierarchyVersions.hierarchyVersions')}>
                {versions.data.length === 0 ? (
                    <EmptyState
                        title={t('hierarchyVersions.noHierarchyVersionsFound')}
                        description={t('hierarchyVersions.description')}
                        action={can.create ? (
                            <Link
                                href={route('hierarchy-versions.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('hierarchyVersions.createHierarchyVersion')}
                            </Link>
                        ) : undefined}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('hierarchyVersions.versionName'),
                                        t('common.status'),
                                        t('hierarchyVersions.edgeCount'),
                                        t('hierarchyVersions.publishedBy'),
                                        t('hierarchyVersions.publishedAt'),
                                        t('hierarchyVersions.effectiveFrom'),
                                        '',
                                    ].map((heading) => (
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
                                {versions.data.map((version) => (
                                    <tr
                                        key={version.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <div className="font-medium text-(--app-foreground)">
                                                {version.version_name}
                                            </div>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={version.status} />
                                        </td>
                                        <td className={tableCellClassName}>{version.edges_count}</td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            {version.approver?.name ?? '-'}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={version.approval_date} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                            <LocalizedDateDisplay value={version.effective_from} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Link
                                                    href={route('hierarchy-versions.show', { hierarchyVersion: version.id })}
                                                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                >
                                                    {t('common.view')}
                                                </Link>
                                                {version.can.update && (
                                                    <Link
                                                        href={route('hierarchy-versions.edit', { hierarchyVersion: version.id })}
                                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {version.can.manageTree && (
                                                    <Link
                                                        href={route('hierarchy-versions.tree.edit', { hierarchyVersion: version.id })}
                                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                    >
                                                        {t('hierarchyVersions.editTree')}
                                                    </Link>
                                                )}
                                                {version.can.publish && (
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
                                                            if (confirmed) router.post(route('hierarchy-versions.publish', { hierarchyVersion: version.id }));
                                                        }}
                                                        className={buttonClassName({ variant: 'primary', size: 'sm' })}
                                                    >
                                                        {t('hierarchyVersions.publishVersion')}
                                                    </button>
                                                )}
                                                {version.can.archive && (
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
                                                            if (confirmed) router.post(route('hierarchy-versions.archive', { hierarchyVersion: version.id }));
                                                        }}
                                                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                                                    >
                                                        {t('hierarchyVersions.archiveVersion')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <ListPagination meta={versions.meta} />
            </TableCard>
        </AuthenticatedLayout>
    );
}
