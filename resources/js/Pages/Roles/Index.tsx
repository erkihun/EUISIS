import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import { permissionModuleLabel, type PermissionModule } from '@/lib/permissionGroups';

import { FilterField, FilterPanel, Input, ListPagination, TableCard, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type RoleRow = {
    id: number;
    name: string;
    name_am?: string | null;
    scope_type: 'scoped' | 'global';
    users_count: number;
    permissions: string[];
    /** Module key → how many of the role's permissions fall in it, largest first. */
    modules: Record<string, number>;
    is_super_admin: boolean;
    can: { update: boolean; delete: boolean; assignPermissions: boolean };
};

type Filters = { search: string; scope: string; module: string };

const SCOPE_DOT: Record<'scoped' | 'global', string> = {
    scoped: 'bg-sky-500',
    global: 'bg-purple-500',
};

const MAX_MODULE_CHIPS = 3;

export default function RolesIndex({
    roles,
    filters,
    modules,
    scopeCounts,
    can,
}: {
    roles: { data: RoleRow[] } & LaravelPageMeta;
    filters: Filters;
    modules: PermissionModule[];
    scopeCounts: { global: number; scoped: number };
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [search, setSearch] = useState(filters.search ?? '');
    const filtersActive = Object.values(filters).some(Boolean);
    const moduleName = (key: string) => permissionModuleLabel(t, key, modules.find((m) => m.key === key)?.name ?? key);

    function go(next: Partial<Filters>) {
        const params = { ...filters, search, ...next };
        router.get(
            route('roles.index'),
            Object.fromEntries(Object.entries(params).filter(([, v]) => v)),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function resetFilters() {
        setSearch('');
        router.get(route('roles.index'), {}, { preserveScroll: true, replace: true });
    }

    async function destroy(id: number, name: string) {
        if (!(await confirm({ title: `${t('roles.deleteRole')} "${name}"`, description: t('common.cannotUndo'), variant: 'danger' })).confirmed) return;
        router.delete(route('roles.destroy', id), { preserveScroll: true });
    }

    const scopeTabs: { value: string; label: string; count: number }[] = [
        { value: '', label: t('roles.allScopes'), count: scopeCounts.global + scopeCounts.scoped },
        { value: 'scoped', label: t('roles.scopedRole'), count: scopeCounts.scoped },
        { value: 'global', label: t('roles.globalRole'), count: scopeCounts.global },
    ];

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

            <div className="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <nav aria-label={t('permissions.modules')} className="self-start rounded-card border border-(--app-border) bg-(--app-surface) lg:sticky lg:top-4">
                    <p className="border-b border-(--app-border) px-4 py-3 text-xs font-semibold text-(--app-muted-foreground)">{t('permissions.modules')}</p>
                    <ul className="flex gap-1 overflow-x-auto p-2 lg:max-h-[calc(100vh-8rem)] lg:flex-col lg:overflow-y-auto">
                        {[{ key: '', label: t('permissions.allModules') }, ...modules.map((m) => ({ key: m.key, label: moduleName(m.key) }))].map((item) => {
                            const isActive = (filters.module ?? '') === item.key;
                            return (
                                <li key={item.key || 'all'} className="shrink-0">
                                    <button
                                        type="button"
                                        aria-current={isActive ? 'true' : undefined}
                                        onClick={() => go({ module: item.key })}
                                        className={cx(
                                            'w-full rounded-control px-3 py-2 text-left text-sm transition-colors',
                                            isActive ? 'bg-primary/10 font-semibold text-primary' : 'text-(--app-foreground) hover:bg-(--app-surface-muted)',
                                        )}
                                    >
                                        <span className="whitespace-nowrap lg:whitespace-normal">{item.label}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="min-w-0 space-y-4">
                    <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('roles.scopeType')}>
                        <span className="mr-1 text-xs font-semibold text-(--app-muted-foreground)">{t('roles.scopeType')}</span>
                        {scopeTabs.map((tab) => {
                            const active = (filters.scope ?? '') === tab.value;
                            return (
                                <button
                                    key={tab.value || 'all'}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => go({ scope: tab.value })}
                                    className={cx(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                        active
                                            ? 'border-primary bg-primary text-(--color-on-primary,#fff)'
                                            : 'border-(--app-border) text-(--app-muted-foreground) hover:bg-(--app-surface-muted)',
                                    )}
                                >
                                    {tab.value !== '' && <span className={cx('h-2 w-2 rounded-full', SCOPE_DOT[tab.value as 'scoped' | 'global'])} aria-hidden="true" />}
                                    {tab.label}
                                    <span className="tabular-nums opacity-75">{tab.count}</span>
                                </button>
                            );
                        })}
                    </div>

                    <FilterPanel onSubmit={() => go({})} onReset={resetFilters} hasActiveFilters={filtersActive}>
                        <FilterField label={t('common.search')} wide>
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={t('common.search')}
                            />
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
                                                t('roles.roleNameAm'),
                                                t('roles.scopeType'),
                                                t('roles.modulesCovered'),
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
                                        {roles.data.map((r) => {
                                            const roleModules = Object.entries(r.modules ?? {});
                                            const hidden = roleModules.slice(MAX_MODULE_CHIPS);
                                            return (
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
                                                        {r.name_am ? r.name_am : <span className="text-(--app-muted-foreground)">—</span>}
                                                    </td>
                                                    <td className={tableCellClassName}>
                                                        <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ${r.scope_type === 'global'
                                                            ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                                                            : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'}`}>
                                                            {r.scope_type === 'global' ? t('roles.globalRole') : t('roles.scopedRole')}
                                                        </span>
                                                    </td>
                                                    <td className={cx(tableCellClassName, 'max-w-md')}>
                                                        {r.is_super_admin ? (
                                                            <span className="text-xs text-(--app-muted-foreground)">{t('permissions.allModules')}</span>
                                                        ) : roleModules.length === 0 ? (
                                                            <span className="text-xs text-(--app-muted-foreground)">—</span>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-1">
                                                                {roleModules.slice(0, MAX_MODULE_CHIPS).map(([key, count]) => (
                                                                    <span key={key} className="inline-flex items-center gap-1 rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs text-(--app-foreground)">
                                                                        {moduleName(key)}
                                                                        <span className="tabular-nums text-(--app-muted-foreground)">{count}</span>
                                                                    </span>
                                                                ))}
                                                                {hidden.length > 0 && (
                                                                    <span
                                                                        title={hidden.map(([key, count]) => `${moduleName(key)} (${count})`).join(', ')}
                                                                        className="inline-flex items-center rounded-full px-2 py-0.5 text-xs text-(--app-muted-foreground)"
                                                                    >
                                                                        {t('roles.moreModules').replace(':count', String(hidden.length))}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        )}
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
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </TableCard>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
