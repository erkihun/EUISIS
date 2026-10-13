import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { FilterField, FilterPanel, Input, ListPagination, Select, TableCard, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
type Establishment = {
    id: string;
    establishment_number: string;
    status: string;
    approved_slots: number;
    effective_from: string;
    effective_to: string | null;
    organization: { name_en: string } | null;
    position: { title_en: string } | null;
};

type Filters = { search: string; status: string; organization_id: string };

type Props = {
    establishments: { data: Establishment[] } & LaravelPageMeta;
    filters: Filters;
    filterOptions: { statuses: string[] };
};

export default function PositionEstablishmentsIndex({ establishments, filters, filterOptions }: Props) {
    const { t } = useLocale();
    const [values, setValues] = useState<Filters>(filters);
    const filtersActive = Boolean(filters.search || filters.status);

    function submit() {
        router.get(route('position-establishments.index'), { ...values, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        // The organization scope comes from the link that opened the page; a reset keeps it.
        const empty: Filters = { search: '', status: '', organization_id: filters.organization_id };
        setValues(empty);
        router.get(route('position-establishments.index'), empty, { preserveScroll: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('positionEstablishments.title')} />}
        >
            <Head title={t('positionEstablishments.title')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={resetFilters} hasActiveFilters={filtersActive}>
                    <FilterField label={t('common.search')} wide>
                        <Input
                            type="search"
                            value={values.search}
                            onChange={(event) => setValues({ ...values, search: event.target.value })}
                            placeholder={t('positionEstablishments.establishmentNumber')}
                        />
                    </FilterField>
                    <FilterField label={t('positionEstablishments.status')}>
                        <Select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {filterOptions.statuses.map((status) => (
                                <option key={status} value={status}>{t(`common.${status}`)}</option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard footer={<ListPagination meta={establishments} />}>
                    {establishments.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('common.noResults')} description="" />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[
                                            t('positionEstablishments.establishmentNumber'),
                                            t('positionEstablishments.organization'),
                                            t('positionEstablishments.position'),
                                            t('positionEstablishments.approvedSlots'),
                                            t('positionEstablishments.status'),
                                            t('positionEstablishments.effectiveFrom'),
                                        ].map((h) => (
                                            <th key={h} className={cx(tableHeaderCellClassName, 'text-left')}>
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {establishments.data.map((e) => (
                                        <tr key={e.id} className={tableRowClassName}>
                                            <td className={tableCellClassName}>
                                                <Link
                                                    href={route('position-establishments.show', e.id)}
                                                    className="font-mono text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                                >
                                                    {e.establishment_number}
                                                </Link>
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.organization?.name_en ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.position?.title_en ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.approved_slots}</td>
                                            <td className={tableCellClassName}><StatusBadge status={e.status} /></td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={e.effective_from} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
