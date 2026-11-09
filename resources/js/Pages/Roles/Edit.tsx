import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import PermissionPicker, { RoleScopeField, type PermissionEntry } from '@/Components/Roles/PermissionPicker';
import { useLocale } from '@/hooks/useLocale';
import type { PermissionModule } from '@/lib/permissionGroups';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';

type RoleData = { id: number; name: string; name_am?: string | null; scope_type: 'scoped' | 'global'; permissions: string[] };

const inputCls = cx(inputClassName, 'w-full');

export default function EditRole({
    role,
    permissions,
    modules,
    can,
}: {
    role: RoleData;
    permissions: Record<string, PermissionEntry[]>;
    modules: PermissionModule[];
    can: { assignPermissions: boolean; manageGlobalRoles: boolean };
}) {
    const { t, locale } = useLocale();

    const form = useForm<{ name: string; name_am: string; scope_type: 'scoped' | 'global'; permissions: string[] }>({
        name: role.name,
        name_am: role.name_am ?? '',
        scope_type: role.scope_type,
        permissions: role.permissions,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(route('roles.update', role.id));
    }

    return (
        <AuthenticatedLayout
            contentWidth="form"
            header={
                <PageHeader
                    title={t('roles.editTitle')}
                    description={`${t('roles.editPrefix')} ${role.name}`}
                />
            }
        >
            <Head title={t('roles.editTitle')} />

            <form onSubmit={submit} className="space-y-5">
                <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
                        <div>
                            <label className="block text-xs font-medium text-(--app-muted-foreground)">
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
                            <label className="mt-4 block text-xs font-medium text-(--app-muted-foreground)">
                                {t('roles.roleNameAm')}
                            </label>
                            <div className="mt-1">
                                <input
                                    className={inputCls}
                                    value={form.data.name_am}
                                    onChange={(e) => form.setData('name_am', e.target.value)}
                                />
                            </div>
                            {form.errors.name_am && (
                                <p className="mt-1 text-xs text-red-600 dark:text-red-400">
                                    {form.errors.name_am}
                                </p>
                            )}
                        </div>
                        <RoleScopeField
                            value={form.data.scope_type}
                            onChange={(scope) => form.setData('scope_type', scope)}
                            canManageGlobal={can.manageGlobalRoles}
                            error={form.errors.scope_type}
                            t={t}
                        />
                    </div>
                </div>

                <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6">
                    <h2 className="mb-4 text-sm font-semibold text-(--app-foreground)">
                        {t('roles.permissionsSection')}
                        <span className="ml-2 text-xs font-normal text-(--app-muted-foreground)">
                            ({form.data.permissions.length} {t('permissions.usedByRoles').toLowerCase()})
                        </span>
                    </h2>
                    {!can.assignPermissions && (
                        <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                            {t('roles.cannotAssignPermissionsNotice')}
                        </p>
                    )}
                    <PermissionPicker
                        permissions={permissions}
                        modules={modules}
                        selected={form.data.permissions}
                        onChange={(names) => form.setData('permissions', names)}
                        disabled={!can.assignPermissions}
                        locale={locale}
                        t={t}
                    />
                    {form.errors.permissions && (
                        <p className="mt-2 text-xs text-red-600 dark:text-red-400">
                            {form.errors.permissions}
                        </p>
                    )}
                </div>

                <div className="flex items-center justify-end gap-3">
                    <Link
                        href={route('roles.index')}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)"
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
        </AuthenticatedLayout>
    );
}
