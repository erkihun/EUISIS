import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    FilterField, FilterPanel, Input, ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import { ChipList } from '@/Pages/Court/Roles/Index';
import type { PermissionRow, PermissionsIndexProps } from '@/court/types/access';

/** The permissions: each with its label, description, the roles that hold it and how many people that reaches. */
export default function PermissionsIndex({ permissions, filters }: PermissionsIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.permissions.index.title');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.permissions.index'), q ? { q } : {}, { preserveState: true });
    };
    const remove = async (permission: PermissionRow) => {
        const { confirmed } = await confirm({
            title: t('cm.permissions.index.delete_title'),
            description: t('cm.permissions.index.delete_confirm'),
            confirmLabel: t('cm.permissions.index.delete'),
            cancelLabel: t('cm.permissions.index.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.permissions.destroy', permission.id), { preserveScroll: true, onStart: () => setBusy(permission.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={`${t('cm.permissions.index.showing')} ${formatNumber(permissions.total)} ${t('cm.permissions.index.total_permissions')}`}
                actions={<Link href={route('court.permissions.create')} className={buttonClassName({})}>{t('cm.permissions.index.new_permission')}</Link>} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.permissions.index'))} hasActiveFilters={Boolean(filters.q)} label={title}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('cm.permissions.index.search_placeholder')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.permissions.fields.name'), t('cm.permissions.fields.label'), t('cm.permissions.fields.description'), t('cm.permissions.index.roles'), t('cm.permissions.index.users'), t('cm.permissions.index.actions')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {permissions.data.map((permission) => (
                                    <tr key={permission.id} className={cx(tableRowClassName, busy === permission.id && 'opacity-60')}>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <Link href={route('court.permissions.edit', permission.id)} className="font-mono font-semibold text-(--app-foreground) hover:underline">{permission.name}</Link>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>{permission.label ?? '-'}</td>
                                        <td className={cx(tableCellClassName, 'max-w-md align-top text-(--app-muted-foreground)')}>{permission.description ?? '-'}</td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <ChipList items={permission.roles} more={t('cm.roles.index.more')} />
                                            <div className="mt-1 text-xs text-(--app-muted-foreground)">{formatNumber(permission.roles_count)} {t('cm.permissions.index.roles_count')}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}><span className={badgeClassName('info')}>{formatNumber(permission.users_count)}</span></td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="flex flex-wrap gap-1.5">
                                                <Link href={route('court.permissions.edit', permission.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.permissions.index.edit')}</Link>
                                                <button type="button" disabled={busy === permission.id} onClick={() => void remove(permission)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                    {t('cm.permissions.index.delete')}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {permissions.data.length === 0 && <EmptyState title={t('cm.permissions.index.empty')} />}
                    <ListPagination meta={permissions} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
