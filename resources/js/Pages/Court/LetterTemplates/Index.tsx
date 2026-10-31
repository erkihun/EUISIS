import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    AppActionMenu, ListPagination, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type AppActionItem,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import type { LetterTemplateRow, LetterTemplatesIndexProps } from '@/court/types/letters';
import { useLetterText } from '@/Pages/Court/Letters/useLetterText';

/**
 * Letter templates: every template with its category, placeholder tags and
 * last change, with edit and delete by permission, plus the way to the
 * categories and to a new letter.
 */
export default function LetterTemplatesIndex({ templates, can }: LetterTemplatesIndexProps) {
    const { t, text } = useLetterText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.letters.templates.title');

    const remove = async (template: LetterTemplateRow) => {
        const { confirmed } = await confirm({
            title: t('cm.letters.actions.delete'),
            description: t('cm.letters.templates.actions.delete_template'),
            confirmLabel: t('cm.letters.actions.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.letter-templates.destroy', template.id), { preserveScroll: true, onStart: () => setBusy(template.id), onFinish: () => setBusy(null) });
    };
    const actionsFor = (template: LetterTemplateRow): AppActionItem[] => {
        const items: AppActionItem[] = [];
        if (can.update) items.push({ label: t('cm.letters.actions.edit'), href: route('court.letter-templates.edit', template.id) });
        if (can.delete) items.push({ label: t('cm.letters.actions.delete'), onClick: () => void remove(template), variant: 'danger' });
        return items;
    };
    const headings = ['title', 'category', 'placeholders', 'updated', 'actions'] as const;

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.letters.templates.description')} actions={(
                <>
                    {can.categories && <Link href={route('court.letter-categories.index')} className={buttonClassName({ variant: 'outline' })}>{text('manage_categories')}</Link>}
                    {can.compose && <Link href={route('court.letters.compose')} className={buttonClassName({ variant: 'outline' })}>{t('cm.letters.templates.actions.compose_letter')}</Link>}
                    {can.create && <Link href={route('court.letter-templates.create')} className={buttonClassName({})}>{t('cm.letters.templates.actions.new_template')}</Link>}
                </>
            )} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {headings.map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap', heading === 'actions' ? 'text-right' : 'text-left')}>{t(`cm.letters.templates.table.${heading}`)}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {templates.data.map((template) => {
                                const tags = template.placeholders.join(', ');
                                return (
                                    <tr key={template.id} className={cx(tableRowClassName, busy === template.id && 'opacity-60')}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{template.title}</td>
                                        <td className={tableCellClassName}>{template.category ?? t('cm.letters.cards.missing')}</td>
                                        <td className={tableCellClassName}>
                                            <span className="font-mono text-xs text-(--app-muted-foreground)" title={tags || t('cm.letters.templates.table.none')}>
                                                {tags ? (tags.length > 60 ? `${tags.slice(0, 60)}...` : tags) : t('cm.letters.templates.table.none')}
                                            </span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap')}><LocalizedDateDisplay value={template.updated_at} withTime /></td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            {actionsFor(template).length > 0 ? <AppActionMenu items={actionsFor(template)} label={t('cm.letters.templates.table.actions')} /> : <span className="text-(--app-muted-foreground)">—</span>}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {templates.data.length === 0 && (
                    <EmptyState
                        title={t('cm.letters.templates.table.empty')}
                        action={can.create ? <Link href={route('court.letter-templates.create')} className={buttonClassName({})}>{t('cm.letters.templates.actions.new_template')}</Link> : undefined}
                    />
                )}
                <ListPagination meta={templates} />
            </div>
        </AuthenticatedLayout>
    );
}
