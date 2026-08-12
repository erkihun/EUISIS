import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

type PermissionEntry = {
    name: string;
    label_en: string | null;
    label_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_system: boolean;
};

type RoleData = { id: number; name: string; permissions: string[] };

const CRITICAL = new Set([
    'users.assignRoles',
    'roles.assignPermissions',
    'permissions.delete',
    'system-settings.manageSecurity',
    'recycle-bin.forceDelete',
]);

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

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
        <div className="rounded-xl border border-gray-200 p-4 dark:border-slate-700">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">
                    {group}
                    <span className="ml-2 rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                        {permissions.filter((p) => selected.includes(p.name)).length}/{permissions.length}
                    </span>
                </h3>
                <button
                    type="button"
                    onClick={toggleAll}
                    disabled={disabled}
                    className="text-xs text-blue-600 hover:underline disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline dark:text-blue-400 dark:disabled:text-slate-600"
                >
                    {allSelected ? t('permissions.clearGroup') : t('permissions.selectAllInGroup')}
                </button>
            </div>
            <div className="grid grid-cols-2 gap-1.5">
                {permissions.map((perm) => (
                    <label
                        key={perm.name}
                        className="flex items-start gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-60 aria-disabled:hover:bg-transparent dark:hover:bg-slate-800"
                        aria-disabled={disabled}
                    >
                        <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-slate-600"
                            checked={selected.includes(perm.name)}
                            disabled={disabled}
                            onChange={() => onToggle(perm.name)}
                        />
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1">
                                <span className="font-mono text-xs text-gray-700 dark:text-slate-300">{perm.name}</span>
                                {CRITICAL.has(perm.name) && (
                                    <span
                                        title={t('permissions.criticalPermissionWarning')}
                                        className="rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400"
                                    >
                                        {t('permissions.criticalPermission')}
                                    </span>
                                )}
                            </div>
                            {label(perm) !== perm.name && (
                                <p className="text-xs font-medium text-gray-900 dark:text-slate-100">{label(perm)}</p>
                            )}
                            {description(perm) && (
                                <p className="line-clamp-2 text-xs text-gray-400 dark:text-slate-500">{description(perm)}</p>
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
    can: { assignPermissions: boolean };
}) {
    const { t, locale } = useLocale();
    const [search, setSearch] = useState('');

    const form = useForm<{ name: string; permissions: string[] }>({
        name: role.name,
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
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <div className="max-w-lg">
                            <label className="block text-xs font-medium text-gray-600 dark:text-slate-400">
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
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                {t('roles.permissionsSection')}
                                <span className="ml-2 text-xs font-normal text-gray-500 dark:text-slate-400">
                                    ({form.data.permissions.length} {t('permissions.usedByRoles').toLowerCase()})
                                </span>
                            </h2>
                            <input
                                type="search"
                                placeholder={t('permissions.searchPermissions')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-64 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
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
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('roles.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
