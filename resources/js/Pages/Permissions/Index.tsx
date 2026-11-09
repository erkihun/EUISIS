import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatCard from '@/Components/StatCard';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { BoxesIcon, ChevronDown, ChevronRight, Layers, Plus, ShieldCheck, TagsIcon } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useLocale } from '@/hooks/useLocale';
import { permissionGroupLabel, permissionModuleLabel } from '@/lib/permissionGroups';

import { FilterField, FilterPanel, Input, ListPagination, Select, type Tone, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
    scope?: Scope;
    created_at: string | null;
    can: { view: boolean; update: boolean; delete: boolean };
};

type Meta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

/** Which kind of role holds the permission (PermissionController::SCOPES). */
type Scope = 'scoped' | 'global' | 'both' | 'unassigned';

type ModuleSummary = {
    key: string;
    name: string;
    groups: string[];
    total: number;
    scopes: Record<Scope, number>;
};

type Filters = { search: string; group: string; guard: string; module: string; scope: string };

const SCOPES: Scope[] = ['scoped', 'global', 'both', 'unassigned'];

const SCOPE_TONE: Record<Scope, Tone> = {
    scoped: 'info',
    global: 'warning',
    both: 'success',
    unassigned: 'neutral',
};

const SCOPE_BAR: Record<Scope, string> = {
    scoped: 'bg-sky-500',
    global: 'bg-amber-500',
    both: 'bg-emerald-500',
    unassigned: 'bg-gray-300 dark:bg-slate-600',
};

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
    modules,
    scopeCounts,
    stats,
    filters,
    can,
}: {
    permissions: { data: Permission[]; meta: Meta };
    groups: string[];
    groupCounts: Record<string, number>;
    guards: string[];
    modules: ModuleSummary[];
    scopeCounts: Record<Scope, number>;
    stats: { total: number; groups: number; modules: number; system: number };
    filters: Filters;
    can: { create: boolean };
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [view, setView] = useState<'table' | 'grouped'>('grouped');
    const [draft, setDraft] = useState({ search: filters.search ?? '', group: filters.group ?? '', guard: filters.guard ?? '' });
    const permissionRows = permissions.data;
    const meta = permissions.meta;
    const activeModule = modules.find((m) => m.key === filters.module) ?? null;
    const moduleGroups = activeModule && activeModule.groups.length > 0 ? groups.filter((g) => activeModule.groups.includes(g)) : groups;
    const scopeTotal = SCOPES.reduce((sum, s) => sum + (scopeCounts[s] ?? 0), 0);
    const showOverview = view === 'grouped' && !filters.module && !filters.search && !filters.group && !filters.guard && !filters.scope;

    const moduleLabel = (key: string, fallback: string) => permissionModuleLabel(t, key, fallback);

    function selectModule(key: string) {
        setDraft({ ...draft, group: '' });
        navigate(clean({ search: filters.search, guard: filters.guard, scope: filters.scope, module: key }));
    }

    function selectScope(scope: string) {
        navigate(clean({ ...filters, scope, page: '' }));
    }

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
            only: ['permissions', 'filters', 'scopeCounts'],
        });
    }

    function clean(params: Record<string, string | number>) {
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        return params;
    }

    const hasActiveFilters = Boolean(filters.search || filters.group || filters.guard || filters.module || filters.scope);

    async function destroy(p: Permission) {
        if (!(await confirm({ title: `${t('permissions.deletePermission')} "${p.name}"`, description: t('common.cannotUndo'), variant: 'danger' })).confirmed) return;
        router.delete(route('permissions.destroy', p.id), { preserveScroll: true });
    }

    // Module → group → permissions, in catalogue module order.
    const grouped = useMemo(() => {
        const moduleOf = new Map<string, string>();
        modules.forEach((m) => m.groups.forEach((g) => moduleOf.set(g, m.key)));
        const byModule = new Map<string, Record<string, Permission[]>>(modules.map((m) => [m.key, {}]));
        permissionRows.forEach((p) => {
            const moduleKey = (p.group && moduleOf.get(p.group)) || 'other';
            const groupKey = p.group ?? t('permissions.otherGroup');
            const bucket = byModule.get(moduleKey) ?? {};
            (bucket[groupKey] ??= []).push(p);
            byModule.set(moduleKey, bucket);
        });
        return [...byModule.entries()].filter(([, g]) => Object.keys(g).length > 0);
    }, [modules, permissionRows, t]);

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
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={t('permissions.totalPermissions')}
                    value={stats.total}
                    tone="primary"
                    icon={<TagsIcon className="h-4 w-4" aria-hidden="true" />}
                />
                <StatCard
                    label={t('permissions.modules')}
                    value={stats.modules}
                    tone="success"
                    icon={<BoxesIcon className="h-4 w-4" aria-hidden="true" />}
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

            <div className="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <ModuleNav
                    modules={modules}
                    active={filters.module}
                    total={stats.total}
                    moduleLabel={moduleLabel}
                    onSelect={selectModule}
                    t={t}
                />

                <div className="min-w-0">
                    <div className="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label={t('permissions.scope')}>
                        <span className="mr-1 text-xs font-semibold text-(--app-muted-foreground)" title={t('permissions.scopeHint')}>
                            {t('permissions.scope')}
                        </span>
                        {(['', ...SCOPES] as const).map((s) => {
                            const active = (filters.scope ?? '') === s;
                            const count = s === '' ? scopeTotal : scopeCounts[s] ?? 0;
                            return (
                                <button
                                    key={s || 'all'}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => selectScope(s)}
                                    className={cx(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                        active
                                            ? 'border-primary bg-primary text-(--color-on-primary,#fff)'
                                            : 'border-(--app-border) text-(--app-muted-foreground) hover:bg-(--app-surface-muted)',
                                    )}
                                >
                                    {s !== '' && <span className={cx('h-2 w-2 rounded-full', SCOPE_BAR[s])} aria-hidden="true" />}
                                    {t(`permissions.scopes.${s || 'all'}`)}
                                    <span className="tabular-nums opacity-75">{count}</span>
                                </button>
                            );
                        })}
                    </div>

                    <FilterPanel
                        onSubmit={() => navigate(clean({ ...draft, module: filters.module, scope: filters.scope }))}
                        onReset={() => { setDraft({ search: '', group: '', guard: '' }); navigate({}); }}
                        hasActiveFilters={hasActiveFilters}
                        className="mb-4"
                        actions={(
                            <div className="flex items-center rounded-control border border-(--app-border)">
                                {(['grouped', 'table'] as const).map((mode) => (
                                    <button
                                        key={mode}
                                        type="button"
                                        aria-pressed={view === mode}
                                        onClick={() => setView(mode)}
                                        className={cx('px-3 py-1.5 text-xs font-medium transition-colors first:rounded-l-control last:rounded-r-control', view === mode ? 'bg-primary text-(--color-on-primary,#fff)' : 'text-(--app-muted-foreground) hover:bg-(--app-surface-muted)')}
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
                                {moduleGroups.map((g) => (
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

                    {showOverview ? (
                        <ModuleOverview modules={modules} moduleLabel={moduleLabel} onSelect={selectModule} t={t} />
                    ) : permissionRows.length === 0 ? (
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
                            showModuleHeadings={!activeModule}
                            moduleLabel={(key) => moduleLabel(key, modules.find((m) => m.key === key)?.name ?? key)}
                            label={label}
                            description={description}
                            onDelete={destroy}
                            t={t}
                        />
                    )}

                    {!showOverview && (
                        <ListPagination meta={meta} bare className="mt-4" onPageChange={(page) => navigate(clean({ ...filters, page }))} />
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function ModuleNav({
    modules,
    active,
    total,
    moduleLabel,
    onSelect,
    t,
}: {
    modules: ModuleSummary[];
    active: string;
    total: number;
    moduleLabel: (key: string, fallback: string) => string;
    onSelect: (key: string) => void;
    t: (key: string) => string;
}) {
    const items = [{ key: '', label: t('permissions.allModules'), total }, ...modules.map((m) => ({ key: m.key, label: moduleLabel(m.key, m.name), total: m.total }))];

    return (
        <nav aria-label={t('permissions.modules')} className="self-start rounded-card border border-(--app-border) bg-(--app-surface) lg:sticky lg:top-4">
            <p className="border-b border-(--app-border) px-4 py-3 text-xs font-semibold text-(--app-muted-foreground)">{t('permissions.modules')}</p>
            <ul className="flex gap-1 overflow-x-auto p-2 lg:max-h-[calc(100vh-8rem)] lg:flex-col lg:overflow-y-auto">
                {items.map((item) => {
                    const isActive = (active ?? '') === item.key;
                    return (
                        <li key={item.key || 'all'} className="shrink-0">
                            <button
                                type="button"
                                aria-current={isActive ? 'true' : undefined}
                                onClick={() => onSelect(item.key)}
                                className={cx(
                                    'flex w-full items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm transition-colors',
                                    isActive
                                        ? 'bg-primary/10 font-semibold text-primary'
                                        : 'text-(--app-foreground) hover:bg-(--app-surface-muted)',
                                )}
                            >
                                <span className="min-w-0 whitespace-nowrap lg:whitespace-normal">{item.label}</span>
                                <span className="shrink-0 text-xs tabular-nums text-(--app-muted-foreground)">{item.total}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function ScopeBar({ scopes, total }: { scopes: Record<Scope, number>; total: number }) {
    if (total === 0) return null;
    return (
        <div className="flex h-1.5 w-full overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
            {SCOPES.map((s) => (scopes[s] ?? 0) > 0 && (
                <span key={s} className={SCOPE_BAR[s]} style={{ width: `${((scopes[s] ?? 0) / total) * 100}%` }} />
            ))}
        </div>
    );
}

function ModuleOverview({
    modules,
    moduleLabel,
    onSelect,
    t,
}: {
    modules: ModuleSummary[];
    moduleLabel: (key: string, fallback: string) => string;
    onSelect: (key: string) => void;
    t: (key: string) => string;
}) {
    return (
        <div>
            <p className="mb-3 text-sm text-(--app-muted-foreground)">{t('permissions.moduleOverviewHint')}</p>
            <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                {modules.map((m) => (
                    <button
                        key={m.key}
                        type="button"
                        onClick={() => onSelect(m.key)}
                        className="group flex flex-col gap-3 rounded-card border border-(--app-border) bg-(--app-surface) p-4 text-left transition-colors hover:border-primary/50 hover:bg-(--app-surface-muted)"
                    >
                        <span className="flex items-start justify-between gap-3">
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-(--app-foreground)">{moduleLabel(m.key, m.name)}</span>
                                {m.groups.length > 0 && (
                                    <span className="mt-0.5 block text-xs text-(--app-muted-foreground)">
                                        {t('permissions.groupsInModule').replace(':count', String(m.groups.length))}
                                    </span>
                                )}
                            </span>
                            <span className={badgeClassName('info', 'shrink-0 tabular-nums')}>{m.total}</span>
                        </span>
                        <ScopeBar scopes={m.scopes} total={m.total} />
                        <span className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                            {SCOPES.filter((s) => (m.scopes[s] ?? 0) > 0).map((s) => (
                                <span key={s} className="inline-flex items-center gap-1">
                                    <span className={cx('h-2 w-2 rounded-full', SCOPE_BAR[s])} aria-hidden="true" />
                                    {t(`permissions.scopes.${s}`)} <span className="tabular-nums">{m.scopes[s]}</span>
                                </span>
                            ))}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function ScopeBadge({ scope, t }: { scope?: Scope; t: (key: string) => string }) {
    if (!scope) return null;
    return (
        <span title={t('permissions.scopeHint')} className={badgeClassName(SCOPE_TONE[scope])}>
            {t(`permissions.scopes.${scope}`)}
        </span>
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
        <span className="inline-flex rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs font-medium text-(--app-muted-foreground)">
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
        <div className="rounded-card border border-(--app-border) bg-(--app-surface)">
            <div className="overflow-x-auto">
                <table className={cx(tableClassName, 'min-w-full text-left')}>
                    <thead className={tableHeadClassName}>
                        <tr>
                            {[
                                t('permissions.permissionLabel'),
                                t('permissions.permissionKey'),
                                t('permissions.permissionDescription'),
                                t('permissions.permissionGroup'),
                                t('permissions.scope'),
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
                                        <span className="font-medium text-(--app-foreground)">{label(p)}</span>
                                        {p.is_system && <SystemBadge t={t} />}
                                        {CRITICAL.has(p.name) && <CriticalBadge t={t} />}
                                    </div>
                                </td>
                                <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-muted-foreground)')}>{p.name}</td>
                                <td className={cx(tableCellClassName, 'max-w-xs text-xs text-(--app-muted-foreground)')}>
                                    <span className="line-clamp-2">{description(p) ?? '—'}</span>
                                </td>
                                <td className={tableCellClassName}>
                                    {p.group && <GroupBadge group={permissionGroupLabel(t, p.group)} />}
                                </td>
                                <td className={tableCellClassName}>
                                    <ScopeBadge scope={p.scope} t={t} />
                                </td>
                                <td className={cx(tableCellClassName, 'font-mono text-xs text-(--app-muted-foreground)')}>{p.guard_name}</td>
                                <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{p.roles_count ?? 0}</td>
                                <td className={cx(tableCellClassName, 'whitespace-nowrap text-xs text-(--app-muted-foreground)')}>
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
    showModuleHeadings,
    moduleLabel,
    label,
    description,
    onDelete,
    t,
}: {
    grouped: [string, Record<string, Permission[]>][];
    groupCounts: Record<string, number>;
    showModuleHeadings: boolean;
    moduleLabel: (key: string) => string;
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
        <div className="space-y-6">
            {grouped.map(([moduleKey, groups]) => (
                <section key={moduleKey} aria-label={moduleLabel(moduleKey)} className="space-y-3">
                    {showModuleHeadings && (
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                            {moduleLabel(moduleKey)}
                            <span className="text-xs font-normal tabular-nums text-(--app-muted-foreground)">
                                {Object.values(groups).reduce((sum, perms) => sum + perms.length, 0)}
                            </span>
                        </h2>
                    )}
                    {Object.entries(groups).map(([group, perms]) => {
                        const isCollapsed = collapsed[group] ?? false;
                        const totalInGroup = groupCounts[group] ?? perms.length;

                        return (
                            <div
                                key={group}
                                className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface)"
                            >
                                <button
                                    type="button"
                                    onClick={() => toggle(group)}
                                    aria-expanded={!isCollapsed}
                                    className="flex w-full items-center justify-between gap-3 border-b border-(--app-border) bg-(--app-surface-muted) px-4 py-3 text-left hover:bg-(--app-surface-muted)"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        {isCollapsed
                                            ? <ChevronRight className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
                                            : <ChevronDown className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />}
                                        <span className="min-w-0 wrap-break-word text-xs font-semibold text-(--app-muted-foreground)">
                                            {permissionGroupLabel(t, group)}
                                        </span>
                                    </span>
                                    <span className={badgeClassName('info', 'shrink-0 tabular-nums')}>
                                        {perms.length === totalInGroup ? perms.length : `${perms.length} / ${totalInGroup}`}
                                    </span>
                                </button>
                                {!isCollapsed && (
                                    <ul className="divide-y divide-(--app-border)">
                                        {perms.map((p) => (
                                            <li key={p.id} className="px-4 py-3.5 transition-colors hover:bg-(--app-surface-muted)">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <p className="text-sm font-semibold leading-5 text-(--app-foreground)">
                                                                {label(p)}
                                                            </p>
                                                            <ScopeBadge scope={p.scope} t={t} />
                                                            {p.is_system && <SystemBadge t={t} />}
                                                            {CRITICAL.has(p.name) && <CriticalBadge t={t} />}
                                                        </div>
                                                        <p className="mt-1 break-all font-mono text-xs text-(--app-muted-foreground)">{p.name}</p>
                                                        {description(p) && (
                                                            <p className="mt-1.5 line-clamp-2 text-xs leading-5 text-(--app-muted-foreground)">
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
                </section>
            ))}
        </div>
    );
}
