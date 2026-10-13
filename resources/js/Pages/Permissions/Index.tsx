import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatCard from '@/Components/StatCard';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronDown, ChevronRight, Layers, Plus, ShieldCheck, TagsIcon } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import { permissionGroupLabel } from '@/lib/permissionGroups';

import { FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
    const { confirm } = useConfirm();
    const [view, setView] = useState<'table' | 'grouped'>('grouped');
    const [draft, setDraft] = useState({ search: filters.search ?? '', group: filters.group ?? '', guard: filters.guard ?? '' });
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

    function clean(params: Record<string, string | number>) {
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        return params;
    }

    const hasActiveFilters = Boolean(filters.search || filters.group || filters.guard);

    async function destroy(p: Permission) {
        if (!(await confirm({ title: `${t('permissions.deletePermission')} "${p.name}"`, description: t('common.cannotUndo'), variant: 'danger' })).confirmed) return;
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

            <FilterPanel
                onSubmit={() => navigate(clean({ ...draft }))}
                onReset={() => { setDraft({ search: '', group: '', guard: '' }); navigate({}); }}
                hasActiveFilters={hasActiveFilters}
                className="mb-4"
                actions={(
                    <div className="flex items-center rounded-[var(--radius-control)] border border-[color:var(--app-border)]">
                        {(['grouped', 'table'] as const).map((mode) => (
                            <button
                                key={mode}
                                type="button"
                                aria-pressed={view === mode}
                                onClick={() => setView(mode)}
                                className={cx('px-3 py-1.5 text-xs font-medium transition-colors first:rounded-l-[var(--radius-control)] last:rounded-r-[var(--radius-control)]', view === mode ? 'bg-[color:var(--color-primary)] text-[color:var(--color-on-primary,#fff)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]')}
                            >
                                {mode === 'grouped' ? t('permissions.groupedView') : t('permissions.tableView')}
                            </button>
                        ))}
                    </div>
                )}
            >
                <FilterField label={t('common.search')} wide>
                    <Input type="search" placeholder={t('permissions.searchPermissions')} value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                </FilterField>
                <FilterField label={t('permissions.permissionGroup')}>
                    <Select value={draft.group} onChange={(e) => setDraft({ ...draft, group: e.target.value })}>
                        <option value="">{t('permissions.allGroups')}</option>
                        {groups.map((g) => (
                            <option key={g} value={g}>{permissionGroupLabel(t, g)}</option>
                        ))}
                    </Select>
                </FilterField>
                {guards.length > 1 && (
                    <FilterField label={t('permissions.guardName')}>
                        <Select value={draft.guard} onChange={(e) => setDraft({ ...draft, guard: e.target.value })}>
                            <option value="">{t('permissions.allGuards')}</option>
                            {guards.map((g) => (
                                <option key={g} value={g}>{permissionGroupLabel(t, g)}</option>
                            ))}
                        </Select>
                    </FilterField>
                )}
            </FilterPanel>

            {permissionRows.length === 0 ? (
                <div className={tableContainerClassName}>
                    <EmptyState title={t('permissions.noPermissionsFound')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
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

            <ListPagination meta={meta} bare className="mt-4" onPageChange={(page) => navigate(clean({ ...filters, page }))} />
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
        <div className="flex justify-end">
            <AppActionMenu
                items={[
                    { label: t('common.view'), href: route('permissions.show', permission.id), show: permission.can.view },
                    { label: t('common.edit'), href: route('permissions.edit', permission.id), show: permission.can.update },
                    { label: t('common.delete'), onClick: () => onDelete(permission), variant: 'danger', show: permission.can.delete },
                    // A system permission cannot be deleted; the menu says so instead of hiding the fact.
                    { label: t('permissions.protectedPermission'), title: t('permissions.protectedCannotDelete'), disabled: true, show: !permission.can.delete && permission.is_system },
                ]}
            />
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
                                    {p.group && <GroupBadge group={permissionGroupLabel(t, p.group)} />}
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
                                    {permissionGroupLabel(t, group)}
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
