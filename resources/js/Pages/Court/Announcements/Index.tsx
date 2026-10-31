import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { AnnouncementRow, AnnouncementsIndexProps } from '@/court/types/cms';

/** The announcements shown to staff: title, dates, state, with view, edit and delete. */
export default function AnnouncementsIndex({ announcements }: AnnouncementsIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.announcements.title');
    const statusLabel = (status: string) => {
        const key = `cm.announcements.status_${status}`;
        const text = t(key);
        return text === key ? status : text;
    };

    const remove = async (announcement: AnnouncementRow) => {
        const { confirmed } = await confirm({
            title: t('cm.announcements.action_delete'),
            description: t('cm.announcements.confirm_delete'),
            confirmLabel: t('cm.announcements.action_delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.announcements.destroy', announcement.id), { preserveScroll: true, onStart: () => setBusy(announcement.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.announcements.manage_description')}
                actions={<Link href={route('court.announcements.create')} className={buttonClassName({})}>{t('cm.announcements.create_cta')}</Link>} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('cm.announcements.table_title'), t('cm.announcements.table_created'), t('cm.announcements.table_updated'), t('cm.announcements.form_status_label'), t('cm.announcements.table_actions')].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {announcements.data.map((announcement) => (
                                <tr key={announcement.id} className={cx(tableRowClassName, busy === announcement.id && 'opacity-60')}>
                                    <td className={cx(tableCellClassName, 'font-semibold text-(--app-foreground)')}>{announcement.title}</td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={announcement.created_at} withTime /></td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={announcement.updated_at} withTime /></td>
                                    <td className={tableCellClassName}><span className={badgeClassName(announcement.status === 'active' ? 'success' : 'neutral')}>{statusLabel(announcement.status)}</span></td>
                                    <td className={tableCellClassName}>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Link href={route('court.announcements.show', announcement.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.announcements.action_view')}</Link>
                                            <Link href={route('court.announcements.edit', announcement.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.announcements.action_edit')}</Link>
                                            <button type="button" disabled={busy === announcement.id} onClick={() => void remove(announcement)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                {t('cm.announcements.action_delete')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {announcements.data.length === 0 && <EmptyState title={t('cm.announcements.empty_state')} description={t('cm.announcements.empty_state_help')} />}
                <ListPagination meta={announcements} />
            </div>
        </AuthenticatedLayout>
    );
}
