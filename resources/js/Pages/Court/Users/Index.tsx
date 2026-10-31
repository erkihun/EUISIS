import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import UserAvatar from '@/Components/UserAvatar';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { UserRow, UsersIndexProps } from '@/court/types/people';

/** The staff accounts: who they are, their roles, state and verification, with view, edit and delete by policy. */
export default function UsersIndex({ users, filters, canCreate }: UsersIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState<string>(filters.status);
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.users.administration');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        if (q) params.q = q;
        if (status) params.status = status;
        router.get(route('court.users.index'), params, { preserveState: true });
    };
    const remove = async (user: UserRow) => {
        const { confirmed } = await confirm({
            title: t('cm.permissions.index.delete'),
            description: t('cm.users.delete_user_confirm'),
            confirmLabel: t('cm.permissions.index.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.users.destroy', user.id), { preserveScroll: true, onStart: () => setBusy(user.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.users.management_subtitle')}
                actions={canCreate ? (
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('court.users.euisis.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.euisis_staff.roster_link')}</Link>
                        <Link href={route('court.users.create')} className={buttonClassName({})}>{t('cm.users.new_user')}</Link>
                    </div>
                ) : undefined} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.users.index'))} hasActiveFilters={Boolean(filters.q || filters.status)} label={title}
                    submitLabel={t('cm.users.filter')} resetLabel={t('cm.users.reset')}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={`${t('cm.app.Search')}…`} />
                    </FilterField>
                    <FilterField label={t('cm.users.status')}>
                        <Select value={status} onChange={(event) => setStatus(event.target.value)}>
                            <option value="">{t('cm.users.all_statuses')}</option>
                            <option value="active">{t('cm.users.active')}</option>
                            <option value="inactive">{t('cm.users.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {['user', 'email', 'roles', 'status', 'verified_column', 'created', 'actions'].map((key) => (
                                        <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{t(`cm.users.${key}`)}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {users.data.map((user) => (
                                    <tr key={user.id} className={cx(tableRowClassName, busy === user.id && 'opacity-60')}>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center gap-3">
                                                <UserAvatar src={user.avatar_url} name={user.name} size={36} />
                                                <div>
                                                    <div className="font-semibold text-(--app-foreground)">{user.name}</div>
                                                    {user.has_signature && <span className={badgeClassName('success', 'mt-1')}>{t('cm.app.signature')}</span>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{user.email}</td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles.length === 0 ? <span className="text-(--app-muted-foreground)">—</span> : user.roles.map((role) => <span key={role} className={badgeClassName('info')}>{role}</span>)}
                                            </div>
                                        </td>
                                        <td className={tableCellClassName}><span className={badgeClassName(user.status === 'active' ? 'success' : 'warning')}>{user.status === 'active' ? t('cm.users.active') : t('cm.users.inactive')}</span></td>
                                        <td className={tableCellClassName}><span className={badgeClassName(user.verified ? 'success' : 'neutral')}>{user.verified ? t('cm.users.verified') : t('cm.users.unverified')}</span></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={user.created_at} /></td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap gap-1.5">
                                                {user.can.view && <Link href={route('court.users.show', user.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.users.view_profile')}</Link>}
                                                {user.can.update && <Link href={route('court.users.edit', user.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.users.edit_profile')}</Link>}
                                                {user.can.delete && (
                                                    <button type="button" disabled={busy === user.id} onClick={() => void remove(user)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                        {t('cm.permissions.index.delete')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {users.data.length === 0 && <EmptyState title={t('cm.users.no_users_found')} />}
                    <ListPagination meta={users} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
