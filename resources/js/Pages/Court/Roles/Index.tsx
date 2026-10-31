import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import { fill } from '@/court/i18n/useAccessForms';
import type { RoleRow, RolesIndexProps } from '@/court/types/access';

const CHIP_LIMIT = 6;

/** A list of names as chips, the first few shown and the rest behind a count. */
export function ChipList({ items, more }: { items: string[]; more: string }) {
    const [open, setOpen] = useState(false);
    const shown = open ? items : items.slice(0, CHIP_LIMIT);
    const extra = items.length - CHIP_LIMIT;
    if (items.length === 0) return <span className="text-(--app-muted-foreground)">—</span>;

    return (
        <div className="flex flex-wrap gap-1">
            {shown.map((item) => <span key={item} className={badgeClassName('neutral')}>{item}</span>)}
            {extra > 0 && !open && (
                <button type="button" onClick={() => setOpen(true)} className={badgeClassName('info', 'cursor-pointer')}>+{extra} {more}</button>
            )}
        </div>
    );
}

/** The roles: each with its description, permissions and member count; edit and delete. */
export default function RolesIndex({ roles }: RolesIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.roles.index.title');

    const remove = async (role: RoleRow) => {
        const { confirmed } = await confirm({
            title: t('cm.roles.index.delete_title'),
            description: t('cm.roles.index.delete_confirm'),
            confirmLabel: t('cm.roles.index.delete'),
            cancelLabel: t('cm.roles.index.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.roles.destroy', role.id), { preserveScroll: true, onStart: () => setBusy(role.id), onFinish: () => setBusy(null) });
    };
    // The server refuses these deletes too; the reason is the disabled button's tooltip.
    const blockedReason = (role: RoleRow): string | null => {
        if (role.delete_blocked === 'admin') return t('cm.roles.index.cannot_delete_admin');
        if (role.delete_blocked === 'assigned') return fill(t('cm.roles.index.cannot_delete_assigned'), { count: formatNumber(role.users_count) });
        return null;
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={`${t('cm.roles.index.showing')} ${formatNumber(roles.total)} ${t('cm.roles.index.total_roles')}`}
                actions={<Link href={route('court.roles.create')} className={buttonClassName({})}>{t('cm.roles.index.new_role')}</Link>} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('cm.roles.fields.name'), t('cm.roles.fields.description'), t('cm.roles.fields.permissions'), t('cm.roles.fields.users'), t('cm.roles.index.actions')].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {roles.data.map((role) => (
                                <tr key={role.id} className={cx(tableRowClassName, busy === role.id && 'opacity-60')}>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <Link href={route('court.roles.edit', role.id)} className="font-semibold text-(--app-foreground) hover:underline">{role.name}</Link>
                                    </td>
                                    <td className={cx(tableCellClassName, 'max-w-md align-top text-(--app-muted-foreground)')}>{role.description ?? '-'}</td>
                                    <td className={cx(tableCellClassName, 'align-top')}><ChipList items={role.permissions} more={t('cm.roles.index.more')} /></td>
                                    <td className={cx(tableCellClassName, 'align-top')}><span className={badgeClassName('info')}>{formatNumber(role.users_count)}</span></td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Link href={route('court.roles.edit', role.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.permissions.index.edit')}</Link>
                                            <span title={blockedReason(role) ?? undefined}>
                                                <button type="button" disabled={busy === role.id || blockedReason(role) !== null} aria-label={blockedReason(role) ? `${t('cm.roles.index.delete')}: ${blockedReason(role)}` : undefined}
                                                    onClick={() => void remove(role)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                    {t('cm.roles.index.delete')}
                                                </button>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {roles.data.length === 0 && <EmptyState title={t('cm.roles.index.empty')} />}
                <ListPagination meta={roles} />
            </div>
        </AuthenticatedLayout>
    );
}
