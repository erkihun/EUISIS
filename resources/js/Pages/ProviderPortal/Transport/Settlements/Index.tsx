import { Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Money, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, TransportSettlement } from '@/types/transport';

const helper = createColumnHelper<TransportSettlement>();

export default function Index({ settlements, filters, statuses }: { settlements: Paginated<TransportSettlement>; filters: { status?: string }; statuses: string[] }) {
    const { t, label } = useTransportLabels();
    const [status, setStatus] = useState(filters.status ?? '');
    const apply = (next = status) => router.get(route('provider.portal.transport.settlements.index'), next ? { status: next } : {}, { preserveState: true });

    const columns = [
        helper.accessor('settlement_number', { header: t('transport.statement'), cell: (c) => <Link href={route('provider.portal.transport.settlements.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()}</Link> }),
        helper.display({ id: 'period', header: t('transport.period'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.period_start} /> – <DateText value={c.row.original.period_end} /></span> }),
        helper.accessor('verified_boardings', { header: t('transport.verifiedBoardings'), cell: (c) => <span className="tabular-nums">{c.getValue()}</span> }),
        helper.accessor('claimed_amount', { header: t('transport.claimedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('calculated_amount', { header: t('transport.calculatedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('approved_amount', { header: t('transport.approvedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportSettlement, unknown>[];

    return (
        <TransportProviderLayout title={t('transport.settlements')}>
            <div className="space-y-4">
                <p className="text-sm text-(--app-muted-foreground)">{t('transport.settlementsPortalIntro')}</p>
                <FilterPanel onSubmit={() => apply()} onReset={() => { setStatus(''); apply(''); }} hasActiveFilters={Boolean(filters.status)}>
                    <FilterField label={t('transport.status')}><Select value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('transport.all')}</option>{statuses.map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={settlements.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(settlements.meta)} onPageChange={(page) => router.get(route('provider.portal.transport.settlements.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </TransportProviderLayout>
    );
}
