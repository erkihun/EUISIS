import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    AppActionMenu, ListPagination, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type AppActionItem,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import type { LetterCategoriesIndexProps, LetterCategoryRow } from '@/court/types/letters';
import { useLetterText } from '@/Pages/Court/Letters/useLetterText';

/** Letter categories: each name and description, with edit and delete by permission. */
export default function LetterCategoriesIndex({ categories, can }: LetterCategoriesIndexProps) {
    const { t, text } = useLetterText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = text('categories_title');

    const remove = async (category: LetterCategoryRow) => {
        const { confirmed } = await confirm({
            title: t('cm.json.Delete'),
            description: text('delete_category_confirm'),
            confirmLabel: t('cm.json.Delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.letter-categories.destroy', category.id), { preserveScroll: true, onStart: () => setBusy(category.id), onFinish: () => setBusy(null) });
    };
    const actionsFor = (category: LetterCategoryRow): AppActionItem[] => {
        const items: AppActionItem[] = [];
        if (can.update) items.push({ label: t('cm.json.Edit'), href: route('court.letter-categories.edit', category.id) });
        if (can.delete) items.push({ label: t('cm.json.Delete'), onClick: () => void remove(category), variant: 'danger' });
        return items;
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={text('categories_description')} actions={(
                <>
                    <Link href={route('court.letter-templates.index')} className={buttonClassName({ variant: 'outline' })}>{text('back_to_templates')}</Link>
                    {can.create && <Link href={route('court.letter-categories.create')} className={buttonClassName({})}>{text('new_category')}</Link>}
                </>
            )} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.json.Name')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{text('description')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.letters.templates.table.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {categories.data.map((category) => (
                                <tr key={category.id} className={cx(tableRowClassName, busy === category.id && 'opacity-60')}>
                                    <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{category.name}</td>
                                    <td className={tableCellClassName}>{category.description || '-'}</td>
                                    <td className={cx(tableCellClassName, 'text-right')}>
                                        {actionsFor(category).length > 0 ? <AppActionMenu items={actionsFor(category)} label={t('cm.letters.templates.table.actions')} /> : <span className="text-(--app-muted-foreground)">—</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {categories.data.length === 0 && (
                    <EmptyState
                        title={text('no_categories')}
                        action={can.create ? <Link href={route('court.letter-categories.create')} className={buttonClassName({})}>{text('new_category')}</Link> : undefined}
                    />
                )}
                <ListPagination meta={categories} />
            </div>
        </AuthenticatedLayout>
    );
}
