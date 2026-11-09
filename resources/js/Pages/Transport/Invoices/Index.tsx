import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Money, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportInvoice } from '@/types/transport';

const helper = createColumnHelper<TransportInvoice>();

export default function Index({ invoices, filters, providers }: { invoices: Paginated<TransportInvoice>; filters: Record<string, string | undefined>; providers: ProviderOption[]; can: { review: boolean; settlements: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = (next = draft) => router.get(route('transport.invoices.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.accessor('invoice_number', { header: t('transport.invoice'), cell: (c) => <Link href={route('transport.invoices.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()}</Link> }),
        helper.display({ id: 'provider', header: t('transport.provider'), cell: (c) => name(c.row.original.provider) }),
        helper.display({ id: 'period', header: t('transport.period'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.period_start} /> – <DateText value={c.row.original.period_end} /></span> }),
        helper.accessor('claimed_boardings', { header: t('transport.claimedBoardings'), cell: (c) => <span className="tabular-nums">{c.getValue() ?? '—'}</span> }),
        helper.accessor('claimed_amount', { header: t('transport.claimedAmount'), cell: (c) => <Money value={c.getValue()} currency={c.row.original.currency_code} /> }),
        helper.display({ id: 'settlement', header: t('transport.statement'), cell: (c) => (c.row.original.settlement ? <Link href={route('transport.settlements.show', c.row.original.settlement.id)} className="underline-offset-2 hover:underline">{c.row.original.settlement.settlement_number}</Link> : '—') }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportInvoice, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.invoices')} description={t('transport.invoicesIntro')} />}>
            <Head title={t('transport.invoices')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '', status: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.status)}>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{['submitted', 'under_review', 'returned', 'disputed', 'approved', 'withdrawn'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={invoices.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(invoices.meta)} onPageChange={(page) => router.get(route('transport.invoices.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
