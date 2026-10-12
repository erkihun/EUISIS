import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type UnitType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    is_active: boolean;
    sort_order: number;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};

export default function OrganizationUnitTypesIndex({
    types,
    can,
}: {
    types: UnitType[];
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function archive(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.post(route('organization-unit-types.archive', id), {}, { preserveScroll: true });
    }

    async function restore(id: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmRestoreTitle'),
            description: t('confirmations.thisActionCannotBeUndone'),
            confirmLabel: t('confirmations.restore'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'default',
        });
        if (!confirmed) return;
        router.post(route('organization-unit-types.restore', id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('organizationUnitTypes.organizationUnitTypes')}
                    description=""
                    actions={
                        can.create ? (
                            <Link
                                href={route('organization-unit-types.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('organizationUnitTypes.createOrganizationUnitType')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('organizationUnitTypes.organizationUnitTypes')} />

            <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                {types.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            title={t('organizationUnitTypes.noOrganizationUnitTypesFound')}
                            description=""
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('common.code'),
                                        t('organizationUnitTypes.prefix'),
                                        t('common.name'),
                                        t('organizationUnitTypes.sortOrder'),
                                        t('common.status'),
                                        '',
                                    ].map((h) => (
                                        <th
                                            key={h}
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {types.map((type) => (
                                    <tr
                                        key={type.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={cx(tableCellClassName, 'font-mono text-xs font-medium text-[color:var(--app-muted-foreground)]')}>
                                            {type.code}
                                        </td>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs font-medium text-indigo-600 dark:text-indigo-400')}>
                                            {type.prefix ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <p className="font-medium">{type.name_en}</p>
                                            {type.name_am && (
                                                <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    {type.name_am}
                                                </p>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-[color:var(--app-muted-foreground)]')}>
                                            {type.sort_order}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={type.is_active ? 'active' : 'archived'} />
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.edit'), href: route('organization-unit-types.edit', type.id), show: type.can.update && !type.deleted_at },
                                                        { label: t('common.delete'), onClick: () => archive(type.id), variant: 'danger', show: type.can.archive && type.is_active && !type.deleted_at },
                                                        { label: t('common.restore'), onClick: () => restore(type.id), show: !!(type.can.restore && (type.deleted_at || !type.is_active)) },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
