import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type Rule = {
    id: string; code: string; name_en: string; subsidy_amount: number;
    currency: string; effective_from: string; effective_to: string | null;
    applies_to: string; is_active: boolean; deleted_at: string | null;
    can: { update: boolean; archive: boolean };
};
type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function SubsidyRulesIndex({ rules, meta, filters, can }: { rules: Rule[]; meta: Meta; filters: Record<string, string>; can: { create: boolean } }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const hasActiveFilters = Object.entries(filters).some(([key, value]) => key !== 'page' && Boolean(value));

    async function handleArchive(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.subsidy-rules.archive', id));
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        const fd = new FormData(e.currentTarget);
        router.get(route('cafeteria.subsidy-rules.index'), Object.fromEntries(fd), { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.subsidyRules')}
                    actions={can.create ? (
                        <Link href={route('cafeteria.subsidy-rules.create')} className={buttonClassName({ variant: 'primary' })}>
                            + {t('cafeteria.addSubsidyRule')}
                        </Link>
                    ) : undefined}
                />
            }
        >
            <Head title={t('cafeteria.subsidyRules')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('cafeteria.subsidy-rules.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" name="search" defaultValue={filters.search ?? ''} placeholder={t('common.search')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    {rules.length === 0 ? <EmptyState description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} /> : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.subsidyRule')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.subsidyAmount')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.effectiveFrom')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.effectiveTo')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.appliesTo')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {rules.map((rule) => (
                                        <tr key={rule.id} className={tableRowClassName}>
                                            <td className={tableCellClassName}>
                                                <div className="font-medium text-(--app-foreground)">{rule.name_en}</div>
                                                <div className="font-mono text-xs text-(--app-muted-foreground)">{rule.code}</div>
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-right font-medium text-emerald-600')}>{rule.subsidy_amount.toFixed(2)} {rule.currency}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}><LocalizedDateDisplay value={rule.effective_from} /></td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}><LocalizedDateDisplay value={rule.effective_to} /></td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{rule.applies_to}</td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('cafeteria.subsidy-rules.edit', rule.id), show: rule.can.update },
                                                            { label: t('common.archive'), onClick: () => handleArchive(rule.id), variant: 'danger', show: rule.can.archive && !rule.deleted_at },
                                                        ]}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <ListPagination meta={meta} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
