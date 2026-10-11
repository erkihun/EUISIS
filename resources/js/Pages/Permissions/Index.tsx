import { useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatCard from '@/Components/StatCard';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronDown, ChevronRight, Layers, Plus, ShieldCheck, TagsIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

import { badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type Permission = {
    id: number;
    name: string;
    guard_name: string;
    label_en: string | null;
    label_am: string | null;
    description_en: string | null;
    description_am: string | null;
    group: string | null;
    sort_order: number;
    is_system: boolean;
    roles_count: number | null;
    created_at: string | null;
    can: { view: boolean; update: boolean; delete: boolean };
};

type Meta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Filters = { search: string; group: string; guard: string };

const CRITICAL = new Set([
    'users.assignRoles',
    'users.assignOrganizationScopes',
    'user-organization-scopes.create',
    'user-organization-scopes.update',
    'user-organization-scopes.delete',
    'roles.assignPermissions',
    'permissions.delete',
    'system-settings.manageSecurity',
    'recycle-bin.forceDelete',
]);

const inputCls =
    'rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-3 py-2 text-sm text-[color:var(--app-foreground)] placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:placeholder-slate-500';

export default function PermissionsIndex({
    permissions,
    groups,
    groupCounts,
    guards,
    stats,
    filters,
    can,
}: {
    permissions: { data: Permission[]; meta: Meta };
    groups: string[];
    groupCounts: Record<string, number>;
    guards: string[];
    stats: { total: number; groups: number; system: number };
    filters: Filters;
    can: { create: boolean };
}) {
    const { t, locale } = useLocale();
    const [view, setView] = useState<'table' | 'grouped'>('grouped');
    const searchTimer = useRef<ReturnType<typeof setTimeout>>();
    const permissionRows = permissions.data;
    const meta = permissions.meta;

    function label(p: Permission): string {
        return (locale === 'am' ? p.label_am : null) ?? p.label_en ?? p.name;
    }

    function description(p: Permission): string | null {
        return (locale === 'am' ? p.description_am : null) ?? p.description_en ?? null;
    }

    function navigate(params: Record<string, string | number>) {
        router.get(route('permissions.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['permissions', 'filters'],
        });
    }

    function applyFilter(key: keyof Filters, value: string) {
        const params: Record<string, string> = { ...filters, [key]: value };
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        navigate(params);
    }

    function onSearchChange(value: string) {
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => applyFilter('search', value), 300);
    }

    function goToPage(page: number) {
        const params: Record<string, string | number> = { ...filters, page };
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        navigate(params);
    }

    function destroy(p: Permission) {
        if (!confirm(`${t('permissions.deletePermission')} "${p.name}"? ${t('common.cannotUndo')}`)) return;
        router.delete(route('permissions.destroy', p.id), { preserveScroll: true });
    }

    const grouped = useMemo(() => permissionRows.reduce<Record<string, Permission[]>>((acc, p) => {
        const key = p.group ?? t('permissions.otherGroup');
        (acc[key] ??= []).push(p);
        return acc;
    }, {}), [permissionRows, t]);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('permissions.title')}
                    description={t('permissions.subtitle')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('permissions.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('permissions.createPermission')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('permissions.title')} />

            {/* Summary cards */}
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label={t('permissions.totalPermissions')}
                    value={stats.total}
                    tone="primary"
                    icon={<TagsIcon className="h-4 w-4" aria-hidden="true" />}
                />
                <StatCard
                    label={t('permissions.permissionGroups')}
                    value={stats.groups}
                    tone="neutral"
                    icon={<Layers className="h-4 w-4" aria-hidden="true" />}
                />
                <StatCard
                    label={t('permissions.systemPermissions')}
                    value={stats.system}
                    tone="warning"
                    icon={<ShieldCheck className="h-4 w-4" aria-hidden="true" />}
                />
            </div>

            {/* Toolbar */}
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <input
                    type="search"
                    placeholder={t('permissions.searchPermissions')}
                    defaultValue={filters.search}
                    onChange={(e) => onSearchChange(e.target.value)}
                    className={`${inputCls} w-64`}
                    aria-label={t('permissions.searchPermissions')}
                />
                <select
                    value={filters.group}
                    onChange={(e) => applyFilter('group', e.target.value)}
                    className={`${inputCls} w-48`}
                    aria-label={t('permissions.filterByGroup')}
                >
                    <option value="">{t('permissions.allGroups')}</option>
                    {groups.map((g) => (
                        <option key={g} value={g}>{g}</option>
                    ))}
                </select>
                {guards.length > 1 && (
                    <select
                        value={filters.guard}
                        onChange={(e) => applyFilter('guard', e.target.value)}
                        className={`${inputCls} w-36`}
                        aria-label={t('permissions.guardName')}
                    >
                        <option value="">{t('permissions.allGuards')}</option>
                        {guards.map((g) => (
                            <option key={g} value={g}>{g}</option>
                        ))}
                    </select>
                )}
                <div className="ml-auto flex items-center rounded-lg border border-[color:var(--app-border)]">
                    <button
                        type="button"
                        onClick={() => setView('grouped')}
                        className={`rounded-l-lg px-3 py-1.5 text-xs font-medium transition-colors ${view === 'grouped' ? 'bg-[color:var(--color-primary)] text-white' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}
                    >
                        {t('permissions.groupedView')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setView('table')}
                        className={`rounded-r-lg px-3 py-1.5 text-xs font-medium transition-colors ${view === 'table' ? 'bg-[color:var(--color-primary)] text-white' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}
                    >
                        {t('permissions.tableView')}
                    </button>
                </div>
            </div>

            {permissionRows.length === 0 ? (
                <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <EmptyState title={t('permissions.noPermissionsFound')} description="" />
                </div>
            ) : view === 'table' ? (
                <TableView
                    permissions={permissionRows}
                    label={label}
                    description={description}
                    onDelete={destroy}
                    t={t}
                />
            ) : (
                <GroupedView
                    grouped={grouped}
                    groupCounts={groupCounts}
                    label={label}
                    description={description}
                    onDelete={destroy}
                    t={t}
                />
            )}

            {/* Pagination */}
            {meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-[color:var(--app-muted-foreground)]">
                    <span>
                        {t('permissions.pageOf')
                            .replace(':current', String(meta.current_page))
                            .replace(':last', String(meta.last_page))
                            .replace(':total', String(meta.total))}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={meta.current_page <= 1}
                            onClick={() => goToPage(meta.current_page - 1)}
                            className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        >
                            {t('common.previous')}
                        </button>
                        <button
                            type="button"
                            disabled={meta.current_page >= meta.last_page}
                            onClick={() => goToPage(meta.current_page + 1)}
                            className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        >
                            {t('common.next')}
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function SystemBadge({ t }: { t: (key: string) => string }) {
    return (
        <span
            title={t('permissions.protectedCannotDelete')}
            className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-300"
        >
            {t('permissions.systemPermission')}
        </span>
    );
}

function CriticalBadge({ t }: { t: (key: string) => string }) {
    return (
        <span
            title={t('permissions.criticalPermissionWarning')}
            className={badgeClassName('danger')}
        >
            {t('permissions.criticalPermission')}
        </span>
    );
}

function GroupBadge({ group }: { group: string }) {
    return (
        <span className="inline-flex rounded-full bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-xs font-medium text-[color:var(--app-muted-foreground)]">
            {group}
        </span>
    );
}

function RowActions({
    permission,
    onDelete,
    t,
}: {
    permission: Permission;
    onDelete: (p: Permission) => void;
    t: (key: string) => string;
}) {
    return (
        <div className="flex items-center justify-end gap-3">
            {permission.can.view && (
                <Link
                    href={route('permissions.show', permission.id)}
                    className="text-xs font-medium text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                >
                    {t('common.view')}
                </Link>
            )}
            {permission.can.update && (
                <Link
                    href={route('permissions.edit', permission.id)}
                    className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                >
                    {t('common.edit')}
                </Link>
            )}
            {permission.can.delete ? (
                <button
                    type="button"
                    onClick={() => onDelete(permission)}
                    className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                >
                    {t('common.delete')}
                </button>
            ) : permission.is_system ? (
                <span
                    title={t('permissions.protectedCannotDelete')}
                    className="cursor-not-allowed text-xs font-medium text-gray-300 dark:text-slate-600"
                >
                    {t('permissions.protectedPermission')}
                </span>
            ) : null}
        </div>
    );
}

function TableView({
    permissions,
    label,
    description,
    onDelete,
    t,
}: {
    permissions: Permission[];
    label: (p: Permission) => string;
    description: (p: Permission) => string | null;
    onDelete: (p: Permission) => void;
    t: (key: string) => string;
}) {
    return (
        <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            <div className="overflow-x-auto">
                <table className={cx(tableClassName, 'min-w-full text-left')}>
                    <thead className={tableHeadClassName}>
                        <tr>
                            {[
                                t('permissions.permissionLabel'),
                                t('permissions.permissionKey'),
                                t('permissions.permissionDescription'),
                                t('permissions.permissionGroup'),
                                t('permissions.guardName'),
                                t('permissions.rolesCount'),
                                t('common.createdAt'),
                                t('common.actions'),
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
                        {permissions.map((p) => (
                            <tr
                                key={p.id}
                                className={tableRowClassName}
                            >
                                <td className={tableCellClassName}>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="font-medium text-[color:var(--app-foreground)]">{label(p)}</span>
                                        {p.is_system && <SystemBadge t={t} />}
                                        {CRITICAL.has(p.name) && <CriticalBadge t={t} />}
                                    </div>
                                </td>
                                <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>{p.name}</td>
                                <td className={cx(tableCellClassName, 'max-w-xs text-xs text-[color:var(--app-muted-foreground)]')}>
                                    <span className="line-clamp-2">{description(p) ?? '—'}</span>
                                </td>
                                <td className={tableCellClassName}>
                                    {p.group && <GroupBadge group={p.group} />}
                                </td>
                                <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>{p.guard_name}</td>
                                <td className={cx(tableCellClassName, 'tabular-nums text-[color:var(--app-muted-foreground)]')}>{p.roles_count ?? 0}</td>
                                <td className={cx(tableCellClassName, 'whitespace-nowrap text-xs text-[color:var(--app-muted-foreground)]')}>
                                    <LocalizedDateDisplay value={p.created_at} />
                                </td>
                                <td className={tableCellClassName}>
                                    <RowActions permission={p} onDelete={onDelete} t={t} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function GroupedView({
    grouped,
    groupCounts,
    label,
    description,
    onDelete,
    t,
}: {
    grouped: Record<string, Permission[]>;
    groupCounts: Record<string, number>;
    label: (p: Permission) => string;
    description: (p: Permission) => string | null;
    onDelete: (p: Permission) => void;
    t: (key: string) => string;
}) {
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

    function toggle(group: string) {
        setCollapsed((prev) => ({ ...prev, [group]: !prev[group] }));
    }

    return (
        <div className="space-y-3">
            {Object.entries(grouped).map(([group, perms]) => {
                const isCollapsed = collapsed[group] ?? false;
                const totalInGroup = groupCounts[group] ?? perms.length;

                return (
                    <div
                        key={group}
                        className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]"
                    >
                        <button
                            type="button"
                            onClick={() => toggle(group)}
                            aria-expanded={!isCollapsed}
                            className="flex w-full items-center justify-between gap-3 border-b border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-4 py-3 text-left hover:bg-[color:var(--app-surface-muted)]"
                        >
                            <span className="flex min-w-0 items-center gap-2">
                                {isCollapsed
                                    ? <ChevronRight className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                    : <ChevronDown className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />}
                                <span className="min-w-0 break-words text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                                    {group}
                                </span>
                            </span>
                            <span className={badgeClassName('info', 'shrink-0 tabular-nums')}>
                                {perms.length === totalInGroup ? perms.length : `${perms.length} / ${totalInGroup}`}
                            </span>
                        </button>
                        {!isCollapsed && (
                            <ul className="divide-y divide-[color:var(--app-border)]">
                                {perms.map((p) => (
                                    <li key={p.id} className="px-4 py-3.5 transition-colors hover:bg-[color:var(--app-surface-muted)]">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <p className="text-sm font-semibold leading-5 text-[color:var(--app-foreground)]">
                                                        {label(p)}
                                                    </p>
                                                    {p.is_system && <SystemBadge t={t} />}
                                                    {CRITICAL.has(p.name) && <CriticalBadge t={t} />}
                                                </div>
                                                <p className="mt-1 break-all font-mono text-xs text-[color:var(--app-muted-foreground)]">{p.name}</p>
                                                {description(p) && (
                                                    <p className="mt-1.5 line-clamp-2 text-xs leading-5 text-[color:var(--app-muted-foreground)]">
                                                        {description(p)}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="shrink-0 pt-0.5">
                                                <RowActions permission={p} onDelete={onDelete} t={t} />
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
