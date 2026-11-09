import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Select, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportException } from '@/types/transport';

const helper = createColumnHelper<TransportException>();

export default function Index({ exceptions, filters, providers, types, can }: { exceptions: Paginated<TransportException>; filters: Record<string, string | undefined>; providers: ProviderOption[]; types: string[]; can: { review: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '', type: filters.type ?? '', status: filters.status ?? 'open', severity: filters.severity ?? '' });
    const apply = (next = draft) => router.get(route('transport.exceptions.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.accessor('occurred_at', { header: t('transport.occurredAt'), cell: (c) => <span className="whitespace-nowrap"><DateText value={c.getValue()} withTime /></span> }),
        helper.accessor('type', { header: t('transport.type'), cell: (c) => <div><div className="font-medium">{label('exceptionTypes', c.getValue())}</div><div className="text-xs text-(--app-muted-foreground)">{name(c.row.original.provider)}{c.row.original.trip ? ` · ${c.row.original.trip.trip_number}` : ''}{c.row.original.vehicle ? ` · ${c.row.original.vehicle.plate_number}` : ''}{c.row.original.device ? ` · ${c.row.original.device.device_code}` : ''}</div></div> }),
        helper.accessor('severity', { header: t('transport.severity'), cell: (c) => <Pill group="severities" value={c.getValue()} /> }),
        helper.display({ id: 'employee', header: t('transport.employee'), cell: (c) => (c.row.original.employee ? <div><div>{c.row.original.employee.full_name}</div><div className="text-xs text-(--app-muted-foreground)">{c.row.original.employee.employee_number}</div></div> : '—') }),
        helper.display({ id: 'detail', header: t('transport.detail'), cell: (c) => <span className="text-xs text-(--app-muted-foreground)">{Object.entries(c.row.original.detail ?? {}).filter(([k]) => !k.endsWith('_id') && k !== 'credential_hash').map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`).join(' · ')}</span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <div><Pill value={c.getValue()} />{c.row.original.review_notes ? <div className="mt-1 text-xs text-(--app-muted-foreground)">{c.row.original.review_notes}</div> : null}</div> }),
        helper.display({ id: 'actions', header: '', cell: (c) => {
            const e = c.row.original;
            return (
                <div className="flex justify-end gap-1">
                    {e.trip && <Link href={route('transport.trips.show', e.trip.id)} className="text-xs underline-offset-2 hover:underline">{t('transport.trip')}</Link>}
                    {can.review && e.status === 'open' && <ActionDialogButton label={t('transport.review')} title={t('transport.reviewException')} size="xs" url={route('transport.exceptions.review', e.id)} fields={[{ name: 'status', label: t('transport.outcome'), type: 'select', options: [{ value: 'reviewed', label: label('statuses', 'reviewed') }, { value: 'dismissed', label: label('statuses', 'dismissed') }] }, { name: 'review_notes', label: t('transport.notes'), type: 'textarea' }]} />}
                </div>
            );
        } }),
    ] as ColumnDef<TransportException, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.exceptions')} description={t('transport.exceptionsIntro')} />}>
            <Head title={t('transport.exceptions')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '', type: '', status: 'open', severity: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.type || filters.severity || (filters.status && filters.status !== 'open'))}>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="all">{t('transport.all')}</option>{['open', 'reviewed', 'dismissed'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.type')}><Select value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}><option value="">{t('transport.all')}</option>{types.map((x) => <option key={x} value={x}>{label('exceptionTypes', x)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.severity')}><Select value={draft.severity} onChange={(e) => setDraft({ ...draft, severity: e.target.value })}><option value="">{t('transport.all')}</option>{['info', 'warning', 'critical'].map((x) => <option key={x} value={x}>{label('severities', x)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={exceptions.data} columns={columns} emptyTitle={t('transport.noExceptions')} pagination={uiMeta(exceptions.meta)} onPageChange={(page) => router.get(route('transport.exceptions.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
