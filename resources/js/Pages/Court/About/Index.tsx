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
import type { AboutIndexProps, AboutPageRow } from '@/court/types/cms';

/** The About pages of the public site: title and slug, published or draft, last edit; view, edit and delete. */
export default function AboutIndex({ pages }: AboutIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.about.title');

    const remove = async (page: AboutPageRow) => {
        const { confirmed } = await confirm({
            title: t('cm.about.action_delete'),
            description: t('cm.about.confirm_delete'),
            confirmLabel: t('cm.about.action_delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.about.destroy', page.id), { preserveScroll: true, onStart: () => setBusy(page.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.about.manage_description')}
                actions={<Link href={route('court.about.create')} className={buttonClassName({})}>{t('cm.about.create_new')}</Link>} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('cm.about.table_title'), t('cm.about.table_status'), t('cm.about.table_updated'), t('cm.about.table_actions')].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {pages.data.map((page) => (
                                <tr key={page.id} className={cx(tableRowClassName, busy === page.id && 'opacity-60')}>
                                    <td className={tableCellClassName}>
                                        <div className="font-semibold text-(--app-foreground)">{page.title}</div>
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">/{page.slug}</div>
                                    </td>
                                    <td className={tableCellClassName}><span className={badgeClassName(page.is_published ? 'success' : 'neutral')}>{page.is_published ? t('cm.about.status_published') : t('cm.about.status_draft')}</span></td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={page.updated_at} withTime /></td>
                                    <td className={tableCellClassName}>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Link href={route('court.about.show', page.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.about.action_view')}</Link>
                                            <Link href={route('court.about.edit', page.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.about.action_edit')}</Link>
                                            <button type="button" disabled={busy === page.id} onClick={() => void remove(page)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                {t('cm.about.action_delete')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {pages.data.length === 0 && <EmptyState title={t('cm.about.empty_state')} description={t('cm.about.empty_state_help')} />}
                <ListPagination meta={pages} />
            </div>
        </AuthenticatedLayout>
    );
}
