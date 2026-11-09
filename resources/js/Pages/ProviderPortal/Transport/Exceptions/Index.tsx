import { Link, router } from '@inertiajs/react';
import { DataTable, FilterField, FilterPanel, Input, Select, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { DateText, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, TransportException } from '@/types/transport';

const helper = createColumnHelper<TransportException>();

/** What went wrong on this provider's trips, as facts; the city reviews them. */
export default function Index({ exceptions, filters, types }: { exceptions: Paginated<TransportException>; filters: Record<string, string | undefined>; types: string[] }) {
    const { t, label } = useTransportLabels();
    const [draft, setDraft] = useState({ status: filters.status ?? 'open', type: filters.type ?? '', date_from: filters.date_from ?? '', date_to: filters.date_to ?? '' });
    const apply = (next = draft) => router.get(route('provider.portal.transport.exceptions.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });

    const columns = [
        helper.accessor('occurred_at', { header: t('transport.occurredAt'), cell: (c) => <span className="whitespace-nowrap"><DateText value={c.getValue()} withTime /></span> }),
        helper.accessor('type', { header: t('transport.type'), cell: (c) => <div><div className="font-medium">{label('exceptionTypes', c.getValue())}</div><div className="text-xs text-(--app-muted-foreground)">{c.row.original.trip ? <Link href={route('provider.portal.transport.trips.show', c.row.original.trip.id)} className="underline-offset-2 hover:underline">{c.row.original.trip.trip_number}</Link> : null}{c.row.original.vehicle ? ` · ${c.row.original.vehicle.plate_number}` : ''}{c.row.original.device ? ` · ${c.row.original.device.device_code}` : ''}</div></div> }),
        helper.accessor('severity', { header: t('transport.severity'), cell: (c) => <Pill group="severities" value={c.getValue()} /> }),
        helper.display({ id: 'employee', header: t('transport.employee'), cell: (c) => c.row.original.employee?.employee_number ?? '—' }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
    ] as ColumnDef<TransportException, unknown>[];

    return (
        <TransportProviderLayout title={t('transport.exceptions')}>
            <div className="space-y-4">
                <p className="text-sm text-(--app-muted-foreground)">{t('transport.exceptionsPortalIntro')}</p>
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ status: 'open', type: '', date_from: '', date_to: '' })} hasActiveFilters={Boolean(filters.type || filters.date_from || (filters.status && filters.status !== 'open'))}>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="all">{t('transport.all')}</option>{['open', 'reviewed', 'dismissed'].map((s) => <option key={s} value={s}>{label('statuses', s)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.type')}><Select value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}><option value="">{t('transport.all')}</option>{types.map((x) => <option key={x} value={x}>{label('exceptionTypes', x)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.dateFrom')}><Input type="date" value={draft.date_from} onChange={(e) => setDraft({ ...draft, date_from: e.target.value })} /></FilterField>
                    <FilterField label={t('transport.dateTo')}><Input type="date" value={draft.date_to} onChange={(e) => setDraft({ ...draft, date_to: e.target.value })} /></FilterField>
                </FilterPanel>
                <DataTable data={exceptions.data} columns={columns} emptyTitle={t('transport.noExceptions')} pagination={uiMeta(exceptions.meta)} onPageChange={(page) => router.get(route('provider.portal.transport.exceptions.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </TransportProviderLayout>
    );
}
