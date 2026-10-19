import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportContract } from '@/types/transport';

const helper = createColumnHelper<TransportContract>();

export default function Index({ contracts, filters, providers, can }: { contracts: Paginated<TransportContract>; filters: Record<string, string | undefined>; providers: ProviderOption[]; can: { create: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = (next = draft) => router.get(route('transport.contracts.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns: ColumnDef<TransportContract, unknown>[] = [
        helper.accessor('contract_reference', { header: t('transport.contractReference'), cell: (c) => <Link href={route('transport.contracts.show', c.row.original.id)} className="font-medium underline-offset-2 hover:underline">{c.getValue()} <span className="text-xs text-(--app-muted-foreground)">v{c.row.original.version_no}</span></Link> }),
        helper.display({ id: 'provider', header: t('transport.provider'), cell: (c) => name(c.row.original.provider) }),
        helper.display({ id: 'organization', header: t('transport.organization'), cell: (c) => (c.row.original.organization ? name(c.row.original.organization) : t('transport.wholeCity')) }),
        helper.accessor('settlement_model', { header: t('transport.settlementModel'), cell: (c) => label('settlementModels', c.getValue()) }),
        helper.display({ id: 'period', header: t('transport.validity'), cell: (c) => <span className="whitespace-nowrap"><DateText value={c.row.original.effective_from} /> – {c.row.original.effective_to ? <DateText value={c.row.original.effective_to} /> : '…'}</span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportContract, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.contracts')} description={t('transport.contractsIntro')} actions={can.create ? <Link href={route('transport.contracts.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.newContract')}</Link> : undefined} />}>
            <Head title={t('transport.contracts')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '', status: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.status)}>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option>{['draft', 'active', 'superseded', 'ended', 'cancelled'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={contracts.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(contracts.meta)} onPageChange={(page) => router.get(route('transport.contracts.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
