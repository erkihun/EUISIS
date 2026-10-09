import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import RoleBadge from '@/Components/RoleBadge';
import EmptyState from '@/Components/EmptyState';
import UserAvatar from '@/Components/UserAvatar';
import { Plus } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useConfirm } from '@/Components/ConfirmProvider';

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

type Pagination = { current_page: number; last_page: number; per_page: number; total: number };

type Filters = { search?: string; status?: string; role?: string };

export default function UsersIndex({
    users,
    users_pagination,
    filters,
    roleOptions,
    can,
    scopedUserManagement,
}: {
    users: UserRow[];
    users_pagination?: Pagination;
    filters: Filters;
    roleOptions: string[];
    can: { create: boolean };
    scopedUserManagement: boolean;
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [loading, setLoading] = useState(false);

    const filterForm = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        role: filters.role ?? '',
    });

    useEffect(() => {
        filterForm.setData({
            search: filters.search ?? '',
            status: filters.status ?? '',
            role: filters.role ?? '',
        });
    }, [filters]);

    const visitOptions = {
        preserveState: true,
        preserveScroll: true,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    };

    /* Search applies as you type; the two selects apply on change. */
    useEffect(() => {
        if (filterForm.data.search === (filters.search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(route('users.index'), filterForm.data, visitOptions);
        }, 350);

        return () => window.clearTimeout(timer);
    }, [filterForm.data.search, filters.search]);

    function applyFilter(key: 'status' | 'role', value: string) {
        const next = { ...filterForm.data, [key]: value };
        filterForm.setData(next);
        router.get(route('users.index'), next, visitOptions);
    }

    function submitFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('users.index'), filterForm.data, visitOptions);
    }

    function resetFilters() {
        router.get(route('users.index'), {}, { ...visitOptions, preserveState: false });
    }

    function goToPage(page: number) {
        router.get(route('users.index'), { ...filterForm.data, page }, visitOptions);
    }

    const hasActiveFilters = Object.values(filterForm.data).some((value) => value !== '');
    const countFormat = new Intl.NumberFormat(locale);
    const total = users_pagination?.total ?? users.length;
    const start = users.length === 0 ? 0 : ((users_pagination?.current_page ?? 1) - 1) * (users_pagination?.per_page ?? users.length) + 1;
    const end = users.length === 0 ? 0 : start + users.length - 1;
    const resultSummary = t('users.results')
        .replace(':from', countFormat.format(start))
        .replace(':to', countFormat.format(end))
        .replace(':total', countFormat.format(total));

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:bg-gray-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900';

    const genderLabels: Record<string, string> = {
        male: t('users.genderMale'),
        female: t('users.genderFemale'),
        other: t('users.genderOther'),
        not_specified: t('users.genderNotSpecified'),
    };

    async function deactivate(user: UserRow) {
        const { confirmed } = await confirm({
            title: t('users.confirmDeactivateTitle'),
            /* Names the account: the button sits in a row of near-identical
             * rows, and this is the last point before someone loses access. */
            description: t('users.confirmDeactivateBody').replace(':name', user.name).replace(':email', user.email),
            confirmLabel: t('users.deactivate'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });

        if (confirmed) {
            router.post(route('users.deactivate', user.id), {}, { preserveScroll: true });
        }
    }

    async function restore(user: UserRow) {
        const { confirmed } = await confirm({
            title: t('users.confirmReactivateTitle'),
            description: t('users.confirmReactivateBody').replace(':name', user.name).replace(':email', user.email),
            confirmLabel: t('users.reactivate'),
            cancelLabel: t('confirmations.cancel'),
        });

        if (confirmed) {
            router.post(route('users.restore', user.id), {}, { preserveScroll: true });
        }
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
                                className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
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

            <section className="mb-4 rounded-card border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <form onSubmit={submitFilters} aria-label={t('users.filters')}>
                    <fieldset disabled={loading} className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <label className="min-w-0 space-y-1.5 sm:col-span-2">
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('common.search')}</span>
                            <input
                                type="search"
                                className={inputCls}
                                placeholder={t('users.searchPlaceholder')}
                                value={filterForm.data.search}
                                onChange={(e) => filterForm.setData('search', e.target.value)}
                            />
                        </label>

                        <label className="min-w-0 space-y-1.5">
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('common.status')}</span>
                            <select
                                className={inputCls}
                                value={filterForm.data.status}
                                onChange={(e) => applyFilter('status', e.target.value)}
                            >
                                <option value="">{t('users.allStatuses')}</option>
                                <option value="active">{t('users.statusActive')}</option>
                                <option value="inactive">{t('users.statusInactive')}</option>
                            </select>
                        </label>

                        <label className="min-w-0 space-y-1.5">
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('users.roles')}</span>
                            <select
                                className={inputCls}
                                value={filterForm.data.role}
                                onChange={(e) => applyFilter('role', e.target.value)}
                            >
                                <option value="">{t('users.allRoles')}</option>
                                {roleOptions.map((role) => (
                                    <option key={role} value={role}>{role}</option>
                                ))}
                            </select>
                        </label>

                        <div className="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-4">
                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="shrink-0 rounded-control px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                                >
                                    {t('common.reset')}
                                </button>
                            )}
                            <p role="status" aria-live="polite" className="text-xs tabular-nums text-gray-500 dark:text-slate-400">
                                {loading ? t('common.loading') : resultSummary}
                            </p>
                        </div>
                    </fieldset>
                </form>
            </section>

            <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
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
                                        { label: t('users.name') },
                                        { label: t('users.roles') },
                                        { label: t('common.status') },
                                        { label: t('users.lastLogin') },
                                        { label: t('common.actions'), hidden: true },
                                    ].map((column, index) => (
                                        <th
                                            key={`${column.label}-${index}`}
                                            scope="col"
                                            className="px-4 py-3 text-xs font-semibold text-gray-500 first:pl-5 last:pr-5 dark:text-slate-400"
                                        >
                                            <span className={column.hidden ? 'sr-only' : undefined}>{column.label}</span>
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
                                                        className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                )}
                                                {u.can.archive && u.status === 'active' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => deactivate(u)}
                                                        className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                                    >
                                                        {t('users.deactivate')}
                                                    </button>
                                                )}
                                                {u.can.restore && u.status !== 'active' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => restore(u)}
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

                {users_pagination && users_pagination.last_page > 1 && (
                    <nav aria-label={t('users.pagination')} className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 dark:border-slate-800">
                        <p className="text-xs text-gray-500 dark:text-slate-400">
                            {t('common.page')} {users_pagination.current_page} / {users_pagination.last_page}
                            {' - '}
                            {countFormat.format(users_pagination.total)} {t('common.results')}
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={loading || users_pagination.current_page <= 1}
                                onClick={() => goToPage(users_pagination.current_page - 1)}
                                className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:border-slate-600"
                            >
                                {t('common.previous')}
                            </button>
                            <button
                                type="button"
                                disabled={loading || users_pagination.current_page >= users_pagination.last_page}
                                onClick={() => goToPage(users_pagination.current_page + 1)}
                                className="rounded-lg border border-gray-200 px-3 py-1 text-xs font-medium text-gray-700 hover:border-gray-300 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:border-slate-600"
                            >
                                {t('common.next')}
                            </button>
                        </div>
                    </nav>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
