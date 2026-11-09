import { Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Money, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, TransportInvoice } from '@/types/transport';

const helper = createColumnHelper<TransportInvoice>();

export default function Index({ invoices, filters }: { invoices: Paginated<TransportInvoice>; filters: { status?: string } }) {
    const { t, label } = useTransportLabels();
    const [status, setStatus] = useState(filters.status ?? '');
    const apply = (next = status) => router.get(route('provider.portal.transport.invoices.index'), next ? { status: next } : {}, { preserveState: true });

    const columns = [
        helper.accessor('invoice_number', { header: t('transport.invoice'), cell: (c) => <Link href={route('provider.portal.transport.invoices.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()}</Link> }),
        helper.display({ id: 'period', header: t('transport.period'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.period_start} /> – <DateText value={c.row.original.period_end} /></span> }),
        helper.accessor('claimed_amount', { header: t('transport.claimedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.display({ id: 'settlement', header: t('transport.statement'), cell: (c) => (c.row.original.settlement && c.row.original.settlement.status !== 'draft' ? <Link href={route('provider.portal.transport.settlements.show', c.row.original.settlement.id)} className="underline-offset-2 hover:underline">{c.row.original.settlement.settlement_number}</Link> : '—') }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportInvoice, unknown>[];

    return (
        <TransportProviderLayout title={t('transport.invoices')}>
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-(--app-muted-foreground)">{t('transport.invoicesPortalIntro')}</p>
                    <Link href={route('provider.portal.transport.invoices.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.newInvoice')}</Link>
                </div>
                <FilterPanel onSubmit={() => apply()} onReset={() => { setStatus(''); apply(''); }} hasActiveFilters={Boolean(filters.status)}>
                    <FilterField label={t('transport.status')}><Select value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('transport.all')}</option>{['draft', 'submitted', 'under_review', 'returned', 'disputed', 'approved', 'withdrawn'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={invoices.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(invoices.meta)} onPageChange={(page) => router.get(route('provider.portal.transport.invoices.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </TransportProviderLayout>
    );
}
