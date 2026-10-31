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
import type { DecisionTemplatesIndexProps } from '@/court/types/decisions';
import { useDecisionText } from '../Decisions/useDecisionText';

const PLACEHOLDER_LIMIT = 60;

/** The decision templates register: title (with the default badge), category, placeholders, last update, and the edit / delete actions. */
export default function DecisionTemplatesIndex({ templates, can }: DecisionTemplatesIndexProps) {
    const { t, text } = useDecisionText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.decision_templates.title');

    const remove = async (id: number) => {
        const { confirmed } = await confirm({
            title: text('template_delete_title'),
            description: t('cm.decision_templates.actions.delete_template'),
            confirmLabel: t('cm.decision_templates.actions.delete'),
            cancelLabel: t('cm.decision_templates.actions.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.delete(route('court.decision-templates.destroy', id), { preserveScroll: true, onStart: () => setBusy(id), onFinish: () => setBusy(null) });
    };

    const truncate = (value: string): string => (value.length > PLACEHOLDER_LIMIT ? `${value.slice(0, PLACEHOLDER_LIMIT).trimEnd()}...` : value);

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.decision_templates.description')}
                actions={can.create ? <Link href={route('court.decision-templates.create')} className={buttonClassName({})}>{t('cm.decision_templates.actions.new_template')}</Link> : undefined} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                {templates.data.length === 0 ? (
                    <EmptyState title={t('cm.decision_templates.table.empty')} />
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('cm.decision_templates.table.title'), t('cm.decision_templates.table.category'), t('cm.decision_templates.table.placeholders'),
                                            t('cm.decision_templates.table.updated'),
                                        ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>)}
                                        <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-right')}>{t('cm.decision_templates.table.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {templates.data.map((template) => (
                                        <tr key={template.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                                {template.title}
                                                {template.is_default && <span className={badgeClassName('success', 'ml-1')}>{t('cm.decision_templates.output.default_layout')}</span>}
                                            </td>
                                            <td className={tableCellClassName}>{template.category ?? t('cm.decision_templates.table.missing')}</td>
                                            <td className={tableCellClassName}>
                                                <span className="text-xs text-(--app-muted-foreground)" title={template.placeholders ?? t('cm.decision_templates.table.none')}>
                                                    {template.placeholders ? truncate(template.placeholders) : t('cm.decision_templates.table.none')}
                                                </span>
                                            </td>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={template.updated_at} withTime /></td>
                                            <td className={tableCellClassName}>
                                                <div className="flex justify-end gap-2">
                                                    {can.update && <Link href={route('court.decision-templates.edit', template.id)} className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.decision_templates.actions.edit')}</Link>}
                                                    {can.delete && (
                                                        <button type="button" disabled={busy === template.id} onClick={() => void remove(template.id)} className={buttonClassName({ variant: 'destructiveOutline', size: 'xs' })}>
                                                            {t('cm.decision_templates.actions.delete')}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <ListPagination meta={templates} />
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
