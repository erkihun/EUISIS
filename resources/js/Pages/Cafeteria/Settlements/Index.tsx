import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, FilterBar, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, Money, pageTo, SelectOptions, StatusPill, useNames, type Meta, type NamePair } from '@/Components/Cafeteria/PolicyUi';

export type SettlementSummary = {
    id: string; settlement_number: string; provider: NamePair; period_start: string | null; period_end: string | null; status: string;
    transaction_count: number; total_subsidy_amount: string; total_employee_amount: string; total_provider_amount: string; currency_code: string;
};

export default function SettlementsIndex({ rows, meta, filters, providers, can }: {
    rows: SettlementSummary[]; meta: Meta; filters: { provider_id?: string; status?: string };
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const [f, setF] = useState({ provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = () => router.get(route('cafeteria.settlements.index'), f, { preserveState: true });

    const columns: ColumnDef<SettlementSummary, unknown>[] = [
        { id: 'number', header: t('cafeteriaPolicy.settlementNumber'), cell: ({ row }) => (
            <Link href={route('cafeteria.settlements.show', row.original.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{row.original.settlement_number}</Link>
        ) },
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => label(row.original.provider) },
        { id: 'period', header: t('cafeteriaPolicy.period'), cell: ({ row }) => <><LocalizedDateDisplay value={row.original.period_start} /> — <LocalizedDateDisplay value={row.original.period_end} /></> },
        { id: 'count', header: t('cafeteriaPolicy.transactions'), cell: ({ row }) => row.original.transaction_count },
        { id: 'subsidy', header: t('cafeteriaPolicy.subsidyAmount'), cell: ({ row }) => <Money value={row.original.total_subsidy_amount} currency={row.original.currency_code} /> },
        { id: 'payable', header: t('cafeteriaPolicy.providerAmount'), cell: ({ row }) => <Money value={row.original.total_provider_amount} currency={row.original.currency_code} /> },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.navSettlements')} description={t('cafeteriaPolicy.settlementsDescription')}
            actions={can.manage ? <Button as={Link} href={route('cafeteria.settlements.create')} variant="primary" size="sm">{t('cafeteriaPolicy.createSettlement')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.navSettlements')} />
            <div className="space-y-4">
                <FilterBar onSubmit={apply} hasActiveFilters={Boolean(f.provider_id || f.status)} onReset={() => router.get(route('cafeteria.settlements.index'))}
                    actions={<Button type="submit" size="sm">{t('cafeteriaPolicy.search')}</Button>}>
                    <select aria-label={t('cafeteriaPolicy.provider')} className={`${inputCls} w-auto`} value={f.provider_id} onChange={(e) => setF({ ...f, provider_id: e.target.value })}>
                        <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={t('cafeteriaPolicy.provider')} />
                    </select>
                    <select aria-label={t('cafeteriaPolicy.status')} className={`${inputCls} w-auto`} value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                        <option value="">{t('cafeteriaPolicy.status')}</option>
                        {['draft', 'finalized', 'cancelled'].map((s) => <option key={s} value={s}>{t(`cafeteriaPolicy.statuses.${s}`)}</option>)}
                    </select>
                </FilterBar>
                <DataTable data={rows} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')} pagination={meta} onPageChange={pageTo(route('cafeteria.settlements.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
