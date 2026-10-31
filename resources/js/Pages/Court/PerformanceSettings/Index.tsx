import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AppDialog } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    Button, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import type { PerformanceCriterionRow, PerformanceSettingsProps } from '@/court/types/performance';
import { CriterionDialog } from './Partials/CriterionForm';
import { CategoryDialog } from './Partials/CategoryForm';

/**
 * The performance evaluation setting: every criterion with its category,
 * weight, order, state, description and how many scores use it; the active
 * weight total; view, add, edit and delete a criterion (one already scored
 * is deactivated instead), and add a category.
 */
export default function PerformanceSettingsIndex({ criteria, categories, totalActiveWeight, returnUrl }: PerformanceSettingsProps) {
    const { p } = usePerformanceText();
    const { confirm } = useConfirm();
    // The dialogs keep their criterion while they fade out; `open` says which one shows.
    const [viewing, setViewing] = useState<PerformanceCriterionRow | null>(null);
    const [editing, setEditing] = useState<PerformanceCriterionRow | null>(null);
    const [open, setOpen] = useState<'view' | 'edit' | null>(null);
    const [creating, setCreating] = useState(false);
    const [categoryOpen, setCategoryOpen] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);
    const title = p('settings.title');
    const na = p('not_available');

    const remove = async (criterion: PerformanceCriterionRow) => {
        const { confirmed } = await confirm({
            title: p('settings.actions.delete'),
            description: p('settings.confirm_delete'),
            confirmLabel: p('settings.actions.delete'),
            cancelLabel: p('actions.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('court.settings.performance-evaluation.criteria.destroy', criterion.id), {
                preserveScroll: true,
                onStart: () => setBusy(criterion.id),
                onFinish: () => setBusy(null),
            });
        }
    };

    const headings = [
        p('settings.fields.name'), p('settings.fields.name_am'), p('settings.fields.category'), p('settings.fields.weight'),
        p('settings.fields.order'), p('settings.fields.status'), p('settings.fields.description'),
    ];

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={p('settings.subtitle')} actions={(
                <>
                    <Button variant="outline" onClick={() => setCreating(true)}>{p('settings.actions.add_criterion')}</Button>
                    <Button variant="outline" onClick={() => setCategoryOpen(true)}>{p('settings.actions.add_category')}</Button>
                    <Link href={route('court.performance-evaluations.index')} className={buttonClassName({ variant: 'ghost' })}>{p('settings.back_to_evaluations')}</Link>
                </>
            )} />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                    <div>
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{p('settings.criteria_title')}</h2>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{p('settings.criteria_hint')}</p>
                    </div>
                    <span className={badgeClassName(totalActiveWeight === 100 ? 'success' : 'warning', 'px-3 py-1.5 text-sm font-semibold')}>
                        {p('settings.active_weight')}: {totalActiveWeight}%
                    </span>
                </div>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {headings.map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>)}
                                <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-center')}>{p('settings.used_scores')}</th>
                                <th className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-right')}>{p('fields.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {criteria.map((criterion) => (
                                <tr key={criterion.id} className={cx(tableRowClassName, 'align-top')}>
                                    <td className={cx(tableCellClassName, 'font-semibold text-(--app-foreground)')}>{criterion.name}</td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')} lang="am">{criterion.name_am || na}</td>
                                    <td className={tableCellClassName}><span className={badgeClassName('neutral')}>{criterion.category_label}</span></td>
                                    <td className={cx(tableCellClassName, 'tabular-nums')}>{criterion.weight}%</td>
                                    <td className={cx(tableCellClassName, 'tabular-nums')}>{criterion.sort_order}</td>
                                    <td className={tableCellClassName}>
                                        <span className={badgeClassName(criterion.active ? 'success' : 'neutral')}>{criterion.active ? p('settings.active') : p('settings.inactive')}</span>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>
                                        <div className="max-w-xs truncate" title={criterion.description ?? undefined}>{criterion.description || na}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-center font-semibold tabular-nums text-(--app-muted-foreground)')}>{criterion.scores_count}</td>
                                    <td className={tableCellClassName}>
                                        <div className="flex justify-end gap-1.5">
                                            <Button variant="outline" size="sm" onClick={() => { setViewing(criterion); setOpen('view'); }}>{p('actions.view')}</Button>
                                            <Button variant="primary" size="sm" onClick={() => { setEditing(criterion); setOpen('edit'); }}>{p('actions.edit')}</Button>
                                            <Button variant="destructiveOutline" size="sm" disabled={busy === criterion.id} onClick={() => void remove(criterion)}>{p('settings.actions.delete')}</Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {criteria.length === 0 && <EmptyState title={p('settings.empty')} />}
            </div>

            <AppDialog open={open === 'view'} onClose={() => setOpen(null)} maxWidth="2xl" title={p('settings.view_criterion_title')}
                footer={<Button variant="outline" onClick={() => setOpen(null)}>{p('actions.back')}</Button>}>
                {viewing && (
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Detail label={p('settings.fields.name')} value={viewing.name} />
                        <Detail label={p('settings.fields.name_am')} value={viewing.name_am || na} />
                        <Detail label={p('settings.fields.category')} value={viewing.category_label} />
                        <Detail label={p('settings.fields.weight')} value={`${viewing.weight}%`} />
                        <Detail label={p('settings.fields.order')} value={String(viewing.sort_order)} />
                        <Detail label={p('settings.used_scores')} value={String(viewing.scores_count)} />
                        <Detail label={p('settings.fields.description')} value={viewing.description || na} wide />
                    </dl>
                )}
            </AppDialog>

            <CriterionDialog open={creating} criterion={null} categories={categories} returnUrl={returnUrl} onClose={() => setCreating(false)} />
            <CriterionDialog open={open === 'edit' && editing !== null} criterion={editing} categories={categories} returnUrl={returnUrl} onClose={() => setOpen(null)} />
            <CategoryDialog open={categoryOpen} returnUrl={returnUrl} onClose={() => setCategoryOpen(false)} />
        </AuthenticatedLayout>
    );
}

function Detail({ label, value, wide = false }: { label: string; value: string; wide?: boolean }) {
    return (
        <div className={wide ? 'sm:col-span-2' : undefined}>
            <dt className="text-xs font-semibold uppercase text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 whitespace-pre-line text-sm text-(--app-foreground)">{value}</dd>
        </div>
    );
}
