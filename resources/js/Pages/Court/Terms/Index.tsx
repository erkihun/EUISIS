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
import type { TermRow, TermsIndexProps } from '@/court/types/cms';

/** The terms and conditions texts: title, whether published and when, last edit; edit and delete. */
export default function TermsIndex({ terms, publicUrl }: TermsIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.terms.title');

    const remove = async (term: TermRow) => {
        const { confirmed } = await confirm({
            title: t('cm.terms.action_delete'),
            description: t('cm.terms.confirm_delete'),
            confirmLabel: t('cm.terms.action_delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.terms.destroy', term.id), { preserveScroll: true, onStart: () => setBusy(term.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.terms.manage_description')} actions={(
                <>
                    {publicUrl && <a href={publicUrl} target="_blank" rel="noreferrer" className={buttonClassName({ variant: 'outline' })}>{t('court.terms.viewPublic')}</a>}
                    <Link href={route('court.terms.create')} className={buttonClassName({})}>{t('cm.terms.create_new')}</Link>
                </>
            )} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('cm.terms.table_title'), t('cm.terms.table_published'), t('cm.terms.table_updated'), t('cm.terms.table_actions')].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {terms.data.map((term) => (
                                <tr key={term.id} className={cx(tableRowClassName, busy === term.id && 'opacity-60')}>
                                    <td className={cx(tableCellClassName, 'max-w-md align-top')}>
                                        <div className="font-semibold text-(--app-foreground)">{term.title}</div>
                                        <div className="text-xs text-(--app-muted-foreground)">{term.excerpt}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <span className={badgeClassName(term.is_published ? 'success' : 'neutral')}>{term.is_published ? t('cm.terms.status_published') : t('cm.terms.status_draft')}</span>
                                        {term.is_published && term.published_at && <div className="mt-1 text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={term.published_at} withTime /></div>}
                                    </td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap align-top text-(--app-muted-foreground)')}><LocalizedDateDisplay value={term.updated_at} withTime /></td>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="flex flex-wrap gap-1.5">
                                            <Link href={route('court.terms.edit', term.id)} className={buttonClassName({ size: 'sm' })}>{t('cm.terms.action_edit')}</Link>
                                            <button type="button" disabled={busy === term.id} onClick={() => void remove(term)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                {t('cm.terms.action_delete')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {terms.data.length === 0 && <EmptyState title={t('cm.terms.empty_state')} />}
                <ListPagination meta={terms} />
            </div>
        </AuthenticatedLayout>
    );
}
