import { useEffect, useState } from 'react';
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

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
    const { t } = useLocale();
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

    function submitFilters() {
        router.get(route('users.index'), filterForm.data, visitOptions);
    }

    function resetFilters() {
        router.get(route('users.index'), {}, { ...visitOptions, preserveState: false });
    }

    const hasActiveFilters = Object.values(filters).some(Boolean);

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
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
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

            <FilterPanel label={t('users.filters')} onSubmit={submitFilters} onReset={resetFilters} hasActiveFilters={hasActiveFilters} processing={loading} className="mb-4">
                <FilterField label={t('common.search')} wide>
                    <Input type="search" placeholder={t('users.searchPlaceholder')} value={filterForm.data.search} onChange={(e) => filterForm.setData('search', e.target.value)} />
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                        <option value="">{t('users.allStatuses')}</option>
                        <option value="active">{t('users.statusActive')}</option>
                        <option value="inactive">{t('users.statusInactive')}</option>
                    </Select>
                </FilterField>
                <FilterField label={t('users.roles')}>
                    <Select value={filterForm.data.role} onChange={(e) => filterForm.setData('role', e.target.value)}>
                        <option value="">{t('users.allRoles')}</option>
                        {roleOptions.map((role) => (
                            <option key={role} value={role}>{role}</option>
                        ))}
                    </Select>
                </FilterField>
            </FilterPanel>

            <div className={tableContainerClassName}>
                {users.length === 0 ? (
                    <EmptyState title={t('users.noUsers')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
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
                                            className={cx(tableHeaderCellClassName, 'text-left')}
                                        >
                                            <span className={column.hidden ? 'sr-only' : undefined}>{column.label}</span>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {users.map((u) => (
                                    <tr
                                        key={u.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <p className="font-medium text-[color:var(--app-foreground)]">
                                                <span className="inline-flex items-center gap-2">
                                                    <UserAvatar src={u.profile_photo_url} name={u.name} size={32} />
                                                    <span>{u.name}</span>
                                                </span>
                                            </p>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {u.email}
                                            </p>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {[u.phone_number, u.gender ? genderLabels[u.gender] : null]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap gap-1">
                                                {u.roles.length === 0 ? (
                                                    <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                                        {t('users.noRoles')}
                                                    </span>
                                                ) : (
                                                    u.roles.map((role) => (
                                                        <RoleBadge key={role} role={role} />
                                                    ))
                                                )}
                                            </div>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <StatusBadge status={u.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            {u.last_login_at
                                                ? <LocalizedDateDisplay value={u.last_login_at} withTime />
                                                : t('users.notAvailable')}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.edit'), href: route('users.edit', u.id), show: u.can.update },
                                                        { label: t('users.deactivate'), onClick: () => deactivate(u), variant: 'danger', show: u.can.archive && u.status === 'active' },
                                                        { label: t('users.reactivate'), onClick: () => restore(u), show: u.can.restore && u.status !== 'active' },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <ListPagination meta={users_pagination} label={t('users.pagination')} disabled={loading} onPageChange={(page) => router.get(route('users.index'), { ...filters, page }, visitOptions)} />
            </div>
        </AuthenticatedLayout>
    );
}
