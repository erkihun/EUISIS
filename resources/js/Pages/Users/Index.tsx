import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RoleBadge from '@/Components/RoleBadge';
import EmptyState from '@/Components/EmptyState';
import UserAvatar from '@/Components/UserAvatar';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type UserRow = {
    id: number;
    name: string;
    email: string;
    status: string;
    phone_number: string | null;
    gender: string | null;
    profile_photo_url: string | null;
    last_login_at: string | null;
    created_at: string | null;
    roles: string[];
    can: { update: boolean; archive: boolean; restore: boolean; assignRoles: boolean };
};

export default function UsersIndex({
    users,
    can,
    scopedUserManagement,
}: {
    users: UserRow[];
    can: { create: boolean };
    scopedUserManagement: boolean;
}) {
    const { t } = useLocale();

    const genderLabels: Record<string, string> = {
        male: t('users.genderMale'),
        female: t('users.genderFemale'),
        other: t('users.genderOther'),
        not_specified: t('users.genderNotSpecified'),
    };

    function deactivate(id: number) {
        router.post(route('users.deactivate', id), {}, { preserveScroll: true });
    }

    function restore(id: number) {
        router.post(route('users.restore', id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('users.title')}
                    description=""
                    actions={
                        can.create ? (
                            <Link
                                href={route('users.create')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                            >
                                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                                {t('users.createUser')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('users.title')} />

            {scopedUserManagement && (
                <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                    {t('users.manageUsersWithinScope')}
                </div>
            )}

            <div className="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                {users.length === 0 ? (
                    <div className="p-6">
                        <EmptyState title={t('users.noUsers')} description="" />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
                                <tr>
                                    {[
                                        t('users.name'),
                                        t('users.roles'),
                                        t('common.status'),
                                        t('users.lastLogin'),
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
                                {users.map((u) => (
                                    <tr
                                        key={u.id}
                                        className="border-t border-gray-100 hover:bg-gray-50 dark:border-slate-800 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="py-3 pl-5 pr-4">
                                            <p className="font-medium text-gray-900 dark:text-slate-100">
                                                <span className="inline-flex items-center gap-2">
                                                    <UserAvatar src={u.profile_photo_url} name={u.name} size={32} />
                                                    <span>{u.name}</span>
                                                </span>
                                            </p>
                                            <p className="text-xs text-gray-400 dark:text-slate-500">
                                                {u.email}
                                            </p>
                                            <p className="text-xs text-gray-400 dark:text-slate-500">
                                                {[u.phone_number, u.gender ? genderLabels[u.gender] : null]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {u.roles.length === 0 ? (
                                                    <span className="text-xs text-gray-400 dark:text-slate-500">
                                                        {t('users.noRoles')}
                                                    </span>
                                                ) : (
                                                    u.roles.map((role) => (
                                                        <RoleBadge key={role} role={role} />
                                                    ))
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={u.status} />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-400 dark:text-slate-500">
                                            {u.last_login_at
                                                ? <LocalizedDateDisplay value={u.last_login_at} withTime />
                                                : t('users.notAvailable')}
                                        </td>
                                        <td className="py-3 pl-4 pr-5">
                                            <div className="flex items-center justify-end gap-3">
                                                {u.can.update && (
                                                    <Link
                                                        href={route('users.edit', u.id)}
                                                        className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {u.can.archive && u.status === 'active' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => deactivate(u.id)}
                                                        className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    >
                                                        {t('users.deactivate')}
                                                    </button>
                                                )}
                                                {u.can.restore && u.status !== 'active' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => restore(u.id)}
                                                        className="text-xs font-medium text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300"
                                                    >
                                                        {t('users.reactivate')}
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
