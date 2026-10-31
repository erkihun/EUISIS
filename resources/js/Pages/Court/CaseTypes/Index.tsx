import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import {
    FilterField, FilterPanel, Input, ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { CaseTypeRow, CaseTypesIndexProps } from '@/court/types/misc';

/** The case types: name, number prefix and how many cases use each; a type in use cannot be deleted. */
export default function CaseTypesIndex({ types, filters }: CaseTypesIndexProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.json.Case Types');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.case-types.index'), q ? { q } : {}, { preserveState: true });
    };
    const remove = async (type: CaseTypeRow) => {
        const { confirmed } = await confirm({
            title: t('cm.json.Delete'),
            description: t('cm.json.Delete this case type?'),
            confirmLabel: t('cm.json.Delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('court.case-types.delete', type.id), { preserveScroll: true, onStart: () => setBusy(type.id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} actions={<Link href={route('court.case-types.create')} className={buttonClassName({})}>{t('cm.json.Add Case Type')}</Link>} />}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.case-types.index'))} hasActiveFilters={Boolean(filters.q)} label={title} submitLabel={t('cm.json.Search')}>
                    <FilterField label={t('cm.json.Name')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('cm.json.Search name…')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.json.Name'), t('cm.json.Prefix'), t('cm.json.Cases Using'), t('cm.cases.table.actions')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {types.data.map((type) => (
                                    <tr key={type.id} className={cx(tableRowClassName, busy === type.id && 'opacity-60')}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{type.name}</td>
                                        <td className={cx(tableCellClassName, 'font-mono text-(--app-muted-foreground)')}>{type.prefix ?? '—'}</td>
                                        <td className={tableCellClassName}><span className={badgeClassName(type.cases_count > 0 ? 'info' : 'neutral')}>{formatNumber(type.cases_count)}</span></td>
                                        <td className={tableCellClassName}>
                                            <div className="flex flex-wrap gap-1.5">
                                                <Link href={route('court.case-types.edit', type.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.json.Edit')}</Link>
                                                <button type="button" disabled={type.cases_count > 0 || busy === type.id} onClick={() => void remove(type)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                    {t('cm.json.Delete')}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {types.data.length === 0 && <EmptyState title={t('cm.cases.types.empty')} description={t('cm.cases.types.adjust_search_hint')} />}
                    <ListPagination meta={types} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
