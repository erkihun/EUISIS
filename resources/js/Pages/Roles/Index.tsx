import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

type RoleRow = {
    id: number;
    name: string;
    scope_type: 'scoped' | 'global';
    users_count: number;
    permissions: string[];
    is_super_admin: boolean;
    can: { update: boolean; delete: boolean; assignPermissions: boolean };
};

export default function RolesIndex({
    roles,
    can,
}: {
    roles: RoleRow[];
    can: { create: boolean };
}) {
    const { t } = useLocale();

    function destroy(id: number, name: string) {
        if (!confirm(`${t('roles.deleteRole')} "${name}"? ${t('common.cannotUndo')}`)) return;
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
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
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

            <div className="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                {roles.length === 0 ? (
                    <div className="p-6">
                        <EmptyState title={t('roles.noRoles')} description="" />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
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
                                            className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {roles.map((r) => (
                                    <tr
                                        key={r.id}
                                        className="border-t border-gray-100 hover:bg-gray-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="py-3 pl-5 pr-4">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-gray-900 dark:text-slate-100">
                                                    {r.name}
                                                </span>
                                                {r.is_super_admin && (
                                                    <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                                        {t('roles.fullAccess')}
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${r.scope_type === 'global'
                                                ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                                                : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'}`}>
                                                {r.scope_type === 'global' ? t('roles.globalRole') : t('roles.scopedRole')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {r.is_super_admin ? (
                                                <span className="text-xs text-gray-400 dark:text-slate-500">
                                                    {t('roles.fullAccess')}
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-slate-800 dark:text-slate-300">
                                                    {r.permissions.length}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums text-gray-500 dark:text-slate-400">
                                            {r.users_count}
                                        </td>
                                        <td className="py-3 pl-4 pr-5">
                                            <div className="flex items-center justify-end gap-3">
                                                {r.can.update && (
                                                    <Link
                                                        href={route('roles.edit', r.id)}
                                                        className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {r.can.delete && (
                                                    <button
                                                        type="button"
                                                        onClick={() => destroy(r.id, r.name)}
                                                        className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    >
                                                        {t('common.delete')}
                                                    </button>
                                                )}
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
