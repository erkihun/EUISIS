import { useMemo, useState } from 'react';
import { permissionGroupLabel, permissionModuleLabel, type PermissionModule } from '@/lib/permissionGroups';
import { actionLinkClassName, badgeClassName, cx, inputClassName } from '@/Components/ui';

export type PermissionEntry = {
    name: string;
    label_en: string | null;
    label_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_system: boolean;
};

type RoleScope = 'scoped' | 'global';

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

/** Scoped vs global role, as two cards so the difference is read before it is chosen. */
export function RoleScopeField({
    value,
    onChange,
    canManageGlobal,
    error,
    t,
}: {
    value: RoleScope;
    onChange: (scope: RoleScope) => void;
    canManageGlobal: boolean;
    error?: string;
    t: (key: string) => string;
}) {
    const options: { value: RoleScope; label: string; help: string; disabled: boolean }[] = [
        { value: 'scoped', label: t('roles.scopedRole'), help: t('roles.scopedRoleHelp'), disabled: false },
        { value: 'global', label: t('roles.globalRole'), help: t('roles.globalRoleHelp'), disabled: !canManageGlobal },
    ];

    return (
        <fieldset>
            <legend className="block text-xs font-medium text-(--app-muted-foreground)">{t('roles.scopeType')}</legend>
            <div className="mt-1 grid gap-2 sm:grid-cols-2">
                {options.map((o) => (
                    <label
                        key={o.value}
                        aria-disabled={o.disabled}
                        className={cx(
                            'flex cursor-pointer items-start gap-2.5 rounded-card border p-3 transition-colors aria-disabled:cursor-not-allowed aria-disabled:opacity-60',
                            value === o.value ? 'border-primary bg-primary/5' : 'border-(--app-border) hover:bg-(--app-surface-muted)',
                        )}
                    >
                        <input
                            type="radio"
                            name="scope_type"
                            value={o.value}
                            checked={value === o.value}
                            disabled={o.disabled}
                            onChange={() => onChange(o.value)}
                            className="mt-0.5 h-4 w-4 shrink-0 border-(--app-border-strong) text-primary focus:ring-primary"
                        />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold text-(--app-foreground)">{o.label}</span>
                            <span className="mt-0.5 block text-xs text-(--app-muted-foreground)">{o.help}</span>
                        </span>
                    </label>
                ))}
            </div>
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </fieldset>
    );
}

function PermissionGroup({
    group,
    permissions,
    selected,
    onSet,
    locale,
    t,
    disabled,
}: {
    group: string;
    permissions: PermissionEntry[];
    selected: Set<string>;
    onSet: (names: string[], on: boolean) => void;
    locale: string;
    t: (key: string) => string;
    disabled: boolean;
}) {
    const selectedCount = permissions.filter((p) => selected.has(p.name)).length;
    const allSelected = selectedCount === permissions.length;

    function label(p: PermissionEntry): string {
        return (locale === 'am' ? p.label_am : null) ?? p.label_en ?? p.name;
    }

    function description(p: PermissionEntry): string | null {
        return (locale === 'am' ? p.description_am : null) ?? p.description_en ?? null;
    }

    return (
        <div className="rounded-card border border-(--app-border) p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold text-(--app-muted-foreground)">
                    {permissionGroupLabel(t, group)}
                    <span className={badgeClassName(selectedCount > 0 ? 'success' : 'neutral', 'ml-2 tabular-nums')}>
                        {selectedCount}/{permissions.length}
                    </span>
                </h3>
                <button
                    type="button"
                    onClick={() => onSet(permissions.map((p) => p.name), !allSelected)}
                    disabled={disabled}
                    className={actionLinkClassName()}
                >
                    {allSelected ? t('permissions.clearGroup') : t('permissions.selectAllInGroup')}
                </button>
            </div>
            <div className="grid gap-1.5 sm:grid-cols-2">
                {permissions.map((perm) => (
                    <label
                        key={perm.name}
                        className="flex items-start gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-(--app-surface-muted) aria-disabled:cursor-not-allowed aria-disabled:opacity-60 aria-disabled:hover:bg-transparent"
                        aria-disabled={disabled}
                    >
                        <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary disabled:cursor-not-allowed"
                            checked={selected.has(perm.name)}
                            disabled={disabled}
                            onChange={() => onSet([perm.name], !selected.has(perm.name))}
                        />
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1">
                                <span className="break-all font-mono text-xs text-(--app-foreground)">{perm.name}</span>
                                {CRITICAL.has(perm.name) && (
                                    <span title={t('permissions.criticalPermissionWarning')} className={badgeClassName('danger')}>
                                        {t('permissions.criticalPermission')}
                                    </span>
                                )}
                            </div>
                            {label(perm) !== perm.name && <p className="text-xs font-medium text-(--app-foreground)">{label(perm)}</p>}
                            {description(perm) && <p className="line-clamp-2 text-xs text-(--app-muted-foreground)">{description(perm)}</p>}
                        </div>
                    </label>
                ))}
            </div>
        </div>
    );
}

/**
 * The role editor's permission checklist, categorised by module: a module list
 * with selected/total counts on the left, that module's groups on the right.
 * Searching looks across every module.
 */
export default function PermissionPicker({
    permissions,
    modules,
    selected,
    onChange,
    disabled,
    locale,
    t,
}: {
    permissions: Record<string, PermissionEntry[]>;
    modules: PermissionModule[];
    selected: string[];
    onChange: (names: string[]) => void;
    disabled: boolean;
    locale: string;
    t: (key: string) => string;
}) {
    const [search, setSearch] = useState('');
    const [active, setActive] = useState<string>(modules[0]?.key ?? 'other');
    const selectedSet = useMemo(() => new Set(selected), [selected]);

    // Module key → [group, permissions][], in catalogue order; unknown groups land in "other".
    const byModule = useMemo(() => {
        const moduleOf = new Map<string, string>();
        modules.forEach((m) => m.groups.forEach((g) => moduleOf.set(g, m.key)));
        const map = new Map<string, [string, PermissionEntry[]][]>(modules.map((m) => [m.key, []]));
        Object.entries(permissions).forEach(([group, perms]) => {
            const key = moduleOf.get(group) ?? 'other';
            map.set(key, [...(map.get(key) ?? []), [group, perms]]);
        });
        return [...map.entries()].filter(([, groups]) => groups.length > 0);
    }, [modules, permissions]);

    const moduleName = (key: string) => permissionModuleLabel(t, key, modules.find((m) => m.key === key)?.name ?? key);
    const namesIn = (groups: [string, PermissionEntry[]][]) => groups.flatMap(([, perms]) => perms.map((p) => p.name));

    function set(names: string[], on: boolean) {
        if (disabled) return;
        const next = new Set(selected);
        names.forEach((n) => (on ? next.add(n) : next.delete(n)));
        onChange([...next]);
    }

    const query = search.trim().toLowerCase();
    const visible: [string, [string, PermissionEntry[]][]][] = query
        ? byModule
              .map(([key, groups]): [string, [string, PermissionEntry[]][]] => [
                  key,
                  groups
                      .map(([group, perms]): [string, PermissionEntry[]] => [
                          group,
                          perms.filter((p) => [p.name, p.label_en, p.label_am, p.description_en].some((v) => (v ?? '').toLowerCase().includes(query))),
                      ])
                      .filter(([, perms]) => perms.length > 0),
              ])
              .filter(([, groups]) => groups.length > 0)
        : byModule.filter(([key]) => key === active);

    const activeNames = namesIn(byModule.find(([key]) => key === active)?.[1] ?? []);
    const activeAllSelected = activeNames.length > 0 && activeNames.every((n) => selectedSet.has(n));

    return (
        <div className="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <nav aria-label={t('permissions.modules')} className="self-start rounded-card border border-(--app-border) lg:sticky lg:top-4">
                <p className="border-b border-(--app-border) px-3 py-2.5 text-xs font-semibold text-(--app-muted-foreground)">{t('permissions.modules')}</p>
                <ul className="flex gap-1 overflow-x-auto p-2 lg:max-h-[calc(100vh-8rem)] lg:flex-col lg:overflow-y-auto">
                    {byModule.map(([key, groups]) => {
                        const names = namesIn(groups);
                        const count = names.filter((n) => selectedSet.has(n)).length;
                        const isActive = !query && key === active;
                        return (
                            <li key={key} className="shrink-0">
                                <button
                                    type="button"
                                    aria-current={isActive ? 'true' : undefined}
                                    onClick={() => { setSearch(''); setActive(key); }}
                                    className={cx(
                                        'flex w-full flex-col gap-1.5 rounded-control px-3 py-2 text-left text-sm transition-colors',
                                        isActive ? 'bg-primary/10 font-semibold text-primary' : 'text-(--app-foreground) hover:bg-(--app-surface-muted)',
                                    )}
                                >
                                    <span className="flex w-full items-center justify-between gap-3">
                                        <span className="min-w-0 whitespace-nowrap lg:whitespace-normal">{moduleName(key)}</span>
                                        <span className={cx('shrink-0 text-xs tabular-nums', count > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-(--app-muted-foreground)')}>
                                            {count}/{names.length}
                                        </span>
                                    </span>
                                    <span className="hidden h-1 w-full overflow-hidden rounded-full bg-(--app-surface-muted) lg:block" aria-hidden="true">
                                        <span className="block h-full bg-emerald-500" style={{ width: `${names.length ? (count / names.length) * 100 : 0}%` }} />
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <div className="min-w-0 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">
                        {query ? t('common.search') : moduleName(active)}
                    </h3>
                    <div className="flex flex-wrap items-center gap-3">
                        {!query && (
                            <button type="button" disabled={disabled} onClick={() => set(activeNames, !activeAllSelected)} className={actionLinkClassName()}>
                                {activeAllSelected ? t('roles.clearModule') : t('roles.selectAllInModule')}
                            </button>
                        )}
                        <input
                            type="search"
                            placeholder={t('permissions.searchPermissions')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className={cx(inputClassName, 'w-64')}
                        />
                    </div>
                </div>

                {visible.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{t('permissions.noPermissionsFound')}</p>}

                {visible.map(([key, groups]) => (
                    <section key={key} className="space-y-3">
                        {query && <h4 className="text-xs font-semibold text-(--app-foreground)">{moduleName(key)}</h4>}
                        <div className="grid grid-cols-1 gap-4 2xl:grid-cols-2">
                            {groups.map(([group, perms]) => (
                                <PermissionGroup
                                    key={group}
                                    group={group}
                                    permissions={perms}
                                    selected={selectedSet}
                                    onSet={set}
                                    locale={locale}
                                    t={t}
                                    disabled={disabled}
                                />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </div>
    );
}
