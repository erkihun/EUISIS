import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Money, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportSettlement } from '@/types/transport';

const helper = createColumnHelper<TransportSettlement>();

export default function Index({ settlements, filters, providers, statuses, can }: { settlements: Paginated<TransportSettlement>; filters: Record<string, string | undefined>; providers: ProviderOption[]; statuses: string[]; can: { manage: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = (next = draft) => router.get(route('transport.settlements.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.accessor('settlement_number', { header: t('transport.statement'), cell: (c) => <Link href={route('transport.settlements.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()}</Link> }),
        helper.display({ id: 'provider', header: t('transport.provider'), cell: (c) => name(c.row.original.provider) }),
        helper.display({ id: 'period', header: t('transport.period'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.period_start} /> – <DateText value={c.row.original.period_end} /></span> }),
        helper.accessor('verified_boardings', { header: t('transport.verifiedBoardings'), cell: (c) => <span className="tabular-nums">{c.getValue()}</span> }),
        helper.accessor('calculated_amount', { header: t('transport.calculatedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('claimed_amount', { header: t('transport.claimedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('approved_amount', { header: t('transport.approvedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportSettlement, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.settlements')} description={t('transport.settlementsIntro')} actions={can.manage ? <Link href={route('transport.settlements.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.computeStatement')}</Link> : undefined} />}>
            <Head title={t('transport.settlements')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '', status: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.status)}>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{statuses.map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={settlements.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(settlements.meta)} onPageChange={(page) => router.get(route('transport.settlements.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
