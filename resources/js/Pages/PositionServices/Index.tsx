import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState, type JSX } from 'react';
import { useConfirm } from '@/hooks/useConfirm';

import { FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
/**
 * Feedback services per position.
 *
 * Distinct from /service-types, which manages the shared platform catalog.
 * These rows say which services a POSITION provides, and they drive the public
 * feedback dropdown an employee's QR opens.
 */

type NamePair = { name_en: string; name_am: string | null } | null;

type Record = {
    id: string;
    service_no: string | null;
    is_active: boolean;
    is_performance_evaluation_enabled: boolean;
    sort_order: number;
    position: {
        id: string;
        code: string | null;
        title_en: string;
        title_am: string | null;
    } | null;
    organization: (NamePair & { id: string }) | null;
    name_en: string;
    name_am: string | null;
    description: string | null;
    /** Items of published plans in open cycles; null when the viewer cannot see plans. */
    current_plan_items_count: number | null;
};

type Props = {
    records: LaravelPageMeta & { data: Record[] };
    filters: { organization_id?: string; position_id?: string; search?: string };
    organizations: { id: string; name_en: string; name_am: string | null }[];
    can: { create: boolean; viewPlanUsage: boolean };
};

export default function PositionServicesIndex({ records, filters, organizations, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const am = locale === 'am';

    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', search: filters.search ?? '' });
    const hasActiveFilters = Object.values(filters).some(Boolean);

    function submit() {
        router.get(route('position-services.index'), { ...filters, ...draft }, { preserveState: true, preserveScroll: true });
    }

    async function destroy(id: string) {
        const result = await confirm({ title: t('confirmations.deleteWarning'), variant: 'danger' });
        if (!result.confirmed) return;

        router.delete(route('position-services.destroy', id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.positionServices')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.positionServices')}
                    description={t('serviceFeedback.positionServicesHint')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('position-services.create')}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                {t('serviceFeedback.addPositionService')}
                            </Link>
                        ) : undefined
                    }
                />

                <FilterPanel onSubmit={submit} onReset={() => router.get(route('position-services.index'))} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={t('serviceFeedback.searchServices')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={t('serviceFeedback.filterOrganization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {am ? (org.name_am ?? org.name_en) : org.name_en}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <Th>{t('serviceFeedback.serviceIdNo')}</Th>
                                <Th>{t('serviceFeedback.serviceName')}</Th>
                                <Th>{t('serviceFeedback.serviceDescription')}</Th>
                                <Th>{t('serviceFeedback.position')}</Th>
                                <Th>{t('serviceFeedback.filterOrganization')}</Th>
                                <Th>{t('serviceFeedback.filterStatus')}</Th>
                                <Th>{t('serviceFeedback.usePerformanceEvaluation')}</Th>
                                {can.viewPlanUsage && <Th>{t('serviceFeedback.currentPlanUsage')}</Th>}
                                <Th />
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {records.data.length === 0 && (
                                <tr>
                                    <td colSpan={can.viewPlanUsage ? 9 : 8} className={cx(tableCellClassName, 'text-center text-[color:var(--app-muted-foreground)]')}>
                                        {t('serviceFeedback.noPositionServices')}
                                    </td>
                                </tr>
                            )}

                            {records.data.map((row) => (
                                <tr key={row.id} className={tableRowClassName}>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap font-mono text-[color:var(--app-foreground)]')}>
                                        {row.service_no ?? '—'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>
                                        {am ? (row.name_am ?? row.name_en) : row.name_en}
                                    </td>
                                    <td className={cx(tableCellClassName, 'max-w-xs text-[color:var(--app-muted-foreground)]')}>
                                        <span className="line-clamp-2" title={row.description ?? undefined}>{row.description || '—'}</span>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>
                                        {row.position
                                            ? am
                                                ? (row.position.title_am ?? row.position.title_en)
                                                : row.position.title_en
                                            : '—'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                        {row.organization
                                            ? am
                                                ? (row.organization.name_am ?? row.organization.name_en)
                                                : row.organization.name_en
                                            : '—'}
                                    </td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap')}>
                                        <span className={badgeClassName(row.is_active ? 'success' : 'neutral')}>
                                            {row.is_active ? t('common.active') : t('common.inactive')}
                                        </span>
                                    </td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}>
                                        {row.is_performance_evaluation_enabled ? t('common.yes') : t('common.no')}
                                    </td>
                                    {can.viewPlanUsage && (
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-[color:var(--app-muted-foreground)]')}>
                                            {row.current_plan_items_count
                                                ? t('serviceFeedback.currentPlanItems').replace('{count}', String(row.current_plan_items_count))
                                                : t('serviceFeedback.notInCurrentPlan')}
                                        </td>
                                    )}
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-right')}>
                                        {/* Sub-services, main tasks and task standards (BPR plans). */}
                                        <Link
                                            href={route('work-structure.show', row.id)}
                                            className="mr-3 text-xs font-medium text-[color:var(--color-primary)] hover:underline"
                                        >
                                            {t('workStandards.open')}
                                        </Link>
                                        <Link
                                            href={route('position-services.edit', row.id)}
                                            className="text-xs font-medium text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => destroy(row.id)}
                                            className="ml-3 text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                        >
                                            {t('common.delete')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    <ListPagination meta={records} />
                </div>

            </div>
        </AuthenticatedLayout>
    );
}

function Th({ children }: { children?: React.ReactNode }): JSX.Element {
    return (
        <th className={cx(tableHeaderCellClassName, 'text-left')}>
            {children}
        </th>
    );
}
