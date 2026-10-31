import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    FilterField, FilterPanel, Input, ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { DecisionsIndexProps } from '@/court/types/decisions';

/** The decisions register: each decision with its case, parties, judge, date, status and approval. */
export default function DecisionsIndex({ decisions, filters, canCreate }: DecisionsIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.decisions.index.title');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.decisions.index'), q ? { q } : {}, { preserveState: true });
    };
    const remove = async (id: number) => {
        const { confirmed } = await confirm({
            title: t('cm.decisions.index.delete'),
            description: t('court.decisions.deleteConfirm'),
            confirmLabel: t('cm.decisions.index.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.delete(route('court.decisions.destroy', id), { preserveScroll: true, onStart: () => setBusy(id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={`${t('cm.decisions.index.showing')} ${formatNumber(decisions.total)} ${t('cm.decisions.index.total')}`}
                actions={canCreate ? <Link href={route('court.decisions.create')} className={buttonClassName({})}>{t('cm.decisions.index.new')}</Link> : undefined} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.decisions.index'))} hasActiveFilters={Boolean(filters.q)} label={title}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('cm.decisions.index.search_placeholder')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.decisions.index.no'), t('cm.decisions.index.case_number'), t('cm.decisions.index.parties'), t('cm.decisions.index.judge'),
                                        t('cm.decisions.index.decision_date'), t('cm.app.Status'), t('cm.decisions.index.approval_status'), t('cm.decisions.index.actions'),
                                    ].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {decisions.data.map((decision, index) => (
                                    <tr key={decision.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'align-top tabular-nums text-(--app-muted-foreground)')}>{(decisions.from ?? 1) + index}</td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            {decision.case_id
                                                ? <Link href={route('court.cases.show', decision.case_id)} className="font-mono font-semibold text-primary hover:underline">{decision.case_number}</Link>
                                                : <span className="font-mono font-semibold">{decision.case_number ?? '—'}</span>}
                                            {decision.case_filed_date && (
                                                <div className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.decisions.index.filed').replace(':date', '')} <LocalizedDateDisplay value={decision.case_filed_date} /></div>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="text-[10px] uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.decisions.index.applicant')}</div>
                                            <div className="text-(--app-foreground)">{decision.applicant ?? '—'}</div>
                                            <div className="mt-2 text-[10px] uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.decisions.index.respondent')}</div>
                                            <div className="text-(--app-foreground)">{decision.respondent ?? '—'}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>{decision.judge ?? '—'}</td>
                                        <td className={cx(tableCellClassName, 'align-top whitespace-nowrap')}><LocalizedDateDisplay value={decision.decision_date} /></td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <span className={badgeClassName(decision.status === 'published' ? 'success' : 'neutral')}>{decision.status ? t(`cm.decisions.status.${decision.status}`) : '—'}</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <span className={badgeClassName(decision.approved ? 'success' : 'warning')}>{t(`cm.decisions.approval_status.${decision.approved ? 'approved' : 'pending'}`)}</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'align-top')}>
                                            <div className="flex flex-wrap gap-1.5">
                                                <Link href={route('court.decisions.show', decision.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.decisions.index.view')}</Link>
                                                {decision.can_edit && <Link href={route('court.decisions.edit', decision.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.decisions.index.edit')}</Link>}
                                                {decision.can_delete && (
                                                    <button type="button" disabled={busy === decision.id} onClick={() => void remove(decision.id)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                        {t('cm.decisions.index.delete')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {decisions.data.length === 0 && <EmptyState title={t('cm.decisions.index.empty')} />}
                    <ListPagination meta={decisions} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
