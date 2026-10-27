import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';

import { FilterField, FilterPanel, Input, ListPagination, Select, TableCard, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type RoleRow = {
    id: number;
    name: string;
    scope_type: 'scoped' | 'global';
    users_count: number;
    permissions: string[];
    is_super_admin: boolean;
    can: { update: boolean; delete: boolean; assignPermissions: boolean };
};

type Filters = { search: string; scope: string };

export default function RolesIndex({
    roles,
    filters,
    can,
}: {
    roles: { data: RoleRow[] } & LaravelPageMeta;
    filters: Filters;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [values, setValues] = useState<Filters>(filters);
    const filtersActive = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('roles.index'), { ...values, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        const empty: Filters = { search: '', scope: '' };
        setValues(empty);
        router.get(route('roles.index'), empty, { preserveScroll: true, replace: true });
    }

    async function destroy(id: number, name: string) {
        if (!(await confirm({ title: `${t('roles.deleteRole')} "${name}"`, description: t('common.cannotUndo'), variant: 'danger' })).confirmed) return;
        router.delete(route('roles.destroy', id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('roles.title')}
                    description=""
                    actions={
                        can.create ? (
                            <Link
                                href={route('roles.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('roles.createRole')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('roles.title')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={resetFilters} hasActiveFilters={filtersActive}>
                    <FilterField label={t('common.search')} wide>
                        <Input
                            type="search"
                            value={values.search}
                            onChange={(event) => setValues({ ...values, search: event.target.value })}
                            placeholder={t('common.search')}
                        />
                    </FilterField>
                    <FilterField label={t('roles.scopeType')}>
                        <Select value={values.scope} onChange={(event) => setValues({ ...values, scope: event.target.value })}>
                            <option value="">{t('common.all')}</option>
                            <option value="global">{t('roles.globalRole')}</option>
                            <option value="scoped">{t('roles.scopedRole')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard footer={<ListPagination meta={roles} />}>
                    {roles.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={filtersActive ? t('common.noResults') : t('roles.noRoles')} description="" />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('roles.role'),
                                            t('roles.scopeType'),
                                            t('roles.permissions'),
                                            t('roles.usersCount'),
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
                                    {roles.data.map((r) => (
                                        <tr
                                            key={r.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={tableCellClassName}>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium text-(--app-foreground)">
                                                        {r.name}
                                                    </span>
                                                    {r.is_super_admin && (
                                                        <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                                            {t('roles.fullAccess')}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className={tableCellClassName}>
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${r.scope_type === 'global'
                                                    ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                                                    : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'}`}>
                                                    {r.scope_type === 'global' ? t('roles.globalRole') : t('roles.scopedRole')}
                                                </span>
                                            </td>
                                            <td className={tableCellClassName}>
                                                {r.is_super_admin ? (
                                                    <span className="text-xs text-(--app-muted-foreground)">
                                                        {t('roles.fullAccess')}
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-full bg-(--app-surface-muted) px-2.5 py-0.5 text-xs font-medium text-(--app-foreground)">
                                                        {r.permissions.length}
                                                    </span>
                                                )}
                                            </td>
                                            <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>
                                                {r.users_count}
                                            </td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('roles.edit', r.id), show: r.can.update },
                                                            { label: t('common.delete'), onClick: () => destroy(r.id, r.name), variant: 'danger', show: r.can.delete },
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
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
