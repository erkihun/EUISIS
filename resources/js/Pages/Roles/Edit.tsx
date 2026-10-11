import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

import { badgeClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';
type PermissionEntry = {
    name: string;
    label_en: string | null;
    label_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_system: boolean;
};

type RoleData = { id: number; name: string; scope_type: 'scoped' | 'global'; permissions: string[] };

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

const inputCls = cx(inputClassName, 'w-full');

function PermissionGroup({
    group,
    permissions,
    selected,
    onToggle,
    locale,
    t,
    disabled,
}: {
    group: string;
    permissions: PermissionEntry[];
    selected: string[];
    onToggle: (name: string) => void;
    locale: string;
    t: (key: string) => string;
    disabled: boolean;
}) {
    const allSelected = permissions.every((p) => selected.includes(p.name));

    function toggleAll() {
        if (disabled) {
            return;
        }
        if (allSelected) {
            permissions.forEach((p) => selected.includes(p.name) && onToggle(p.name));
        } else {
            permissions.forEach((p) => !selected.includes(p.name) && onToggle(p.name));
        }
    }

    function label(p: PermissionEntry): string {
        return (locale === 'am' ? p.label_am : null) ?? p.label_en ?? p.name;
    }

    function description(p: PermissionEntry): string | null {
        return (locale === 'am' ? p.description_am : null) ?? p.description_en ?? null;
    }

    return (
        <div className="rounded-card border border-[color:var(--app-border)] p-4">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-xs font-semibold text-[color:var(--app-muted-foreground)]">
                    {group}
                    <span className={badgeClassName('info', 'ml-2')}>
                        {permissions.filter((p) => selected.includes(p.name)).length}/{permissions.length}
                    </span>
                </h3>
                <button
                    type="button"
                    onClick={toggleAll}
                    disabled={disabled}
                    className="text-xs text-[color:var(--color-primary)] hover:underline disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline dark:text-[color:var(--color-primary)] dark:disabled:text-slate-600"
                >
                    {allSelected ? t('permissions.clearGroup') : t('permissions.selectAllInGroup')}
                </button>
            </div>
            <div className="grid grid-cols-2 gap-1.5">
                {permissions.map((perm) => (
                    <label
                        key={perm.name}
                        className="flex items-start gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-[color:var(--app-surface-muted)] aria-disabled:cursor-not-allowed aria-disabled:opacity-60 aria-disabled:hover:bg-transparent"
                        aria-disabled={disabled}
                    >
                        <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4 shrink-0 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed"
                            checked={selected.includes(perm.name)}
                            disabled={disabled}
                            onChange={() => onToggle(perm.name)}
                        />
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1">
                                <span className="font-mono text-xs text-[color:var(--app-foreground)]">{perm.name}</span>
                                {CRITICAL.has(perm.name) && (
                                    <span
                                        title={t('permissions.criticalPermissionWarning')}
                                        className={badgeClassName('danger')}
                                    >
                                        {t('permissions.criticalPermission')}
                                    </span>
                                )}
                            </div>
                            {label(perm) !== perm.name && (
                                <p className="text-xs font-medium text-[color:var(--app-foreground)]">{label(perm)}</p>
                            )}
                            {description(perm) && (
                                <p className="line-clamp-2 text-xs text-[color:var(--app-muted-foreground)]">{description(perm)}</p>
                            )}
                        </div>
                    </label>
                ))}
            </div>
        </div>
    );
}

export default function EditRole({
    role,
    permissions,
    can,
}: {
    role: RoleData;
    permissions: Record<string, PermissionEntry[]>;
    can: { assignPermissions: boolean; manageGlobalRoles: boolean };
}) {
    const { t, locale } = useLocale();
    const [search, setSearch] = useState('');

    const form = useForm<{ name: string; scope_type: 'scoped' | 'global'; permissions: string[] }>({
        name: role.name,
        scope_type: role.scope_type,
        permissions: role.permissions,
    });

    function togglePermission(name: string) {
        if (!can.assignPermissions) {
            return;
        }
        const current = form.data.permissions;
        form.setData(
            'permissions',
            current.includes(name) ? current.filter((p) => p !== name) : [...current, name],
        );
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(route('roles.update', role.id));
    }

    const filteredPermissions: Record<string, PermissionEntry[]> = search
        ? Object.fromEntries(
              Object.entries(permissions)
                  .map(([group, perms]): [string, PermissionEntry[]] => [
                      group,
                      perms.filter(
                          (p) =>
                              p.name.toLowerCase().includes(search.toLowerCase()) ||
                              (p.label_en ?? '').toLowerCase().includes(search.toLowerCase()) ||
                              (p.description_en ?? '').toLowerCase().includes(search.toLowerCase()),
                      ),
                  ])
                  .filter(([, perms]) => perms.length > 0),
          )
        : permissions;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('roles.editTitle')}
                    description={`${t('roles.editPrefix')} ${role.name}`}
                />
            }
        >
            <Head title={t('roles.editTitle')} />

            <div className="w-full">
                <form onSubmit={submit} className="space-y-5">
                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                        <div className="grid max-w-3xl gap-5 md:grid-cols-2">
                            <div>
                            <label className="block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('roles.roleName')}
                            </label>
                            <div className="mt-1">
                                <input
                                    className={inputCls}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </div>
                            {form.errors.name && (
                                <p className="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {form.errors.name}
                                </p>
                            )}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                    {t('roles.scopeType')}
                                </label>
                                <select
                                    className={`${inputCls} mt-1`}
                                    value={form.data.scope_type}
                                    onChange={(e) => form.setData('scope_type', e.target.value as 'scoped' | 'global')}
                                >
                                    <option value="scoped">{t('roles.scopedRole')}</option>
                                    <option value="global" disabled={!can.manageGlobalRoles}>{t('roles.globalRole')}</option>
                                </select>
                                <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">
                                    {form.data.scope_type === 'scoped'
                                        ? t('roles.scopedRoleHelp')
                                        : t('roles.globalRoleHelp')}
                                </p>
                                {form.errors.scope_type && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.scope_type}</p>}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                                {t('roles.permissionsSection')}
                                <span className="ml-2 text-xs font-normal text-[color:var(--app-muted-foreground)]">
                                    ({form.data.permissions.length} {t('permissions.usedByRoles').toLowerCase()})
                                </span>
                            </h2>
                            <input
                                type="search"
                                placeholder={t('permissions.searchPermissions')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className={cx(inputClassName, 'w-64')}
                            />
                        </div>
                        {!can.assignPermissions && (
                            <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                                {t('roles.cannotAssignPermissionsNotice')}
                            </p>
                        )}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            {Object.entries(filteredPermissions).map(([group, perms]) => (
                                <PermissionGroup
                                    key={group}
                                    group={group}
                                    permissions={perms}
                                    selected={form.data.permissions}
                                    onToggle={togglePermission}
                                    locale={locale}
                                    t={t}
                                    disabled={!can.assignPermissions}
                                />
                            ))}
                        </div>
                        {form.errors.permissions && (
                            <p className="mt-2 text-xs text-red-600 dark:text-red-400">
                                {form.errors.permissions}
                            </p>
                        )}
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link
                            href={route('roles.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {form.processing ? t('common.saving') : t('roles.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
