import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { Alert, DataTable, FilterField, FilterPanel, Select, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, Pill, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, TransportDevice } from '@/types/transport';

const helper = createColumnHelper<TransportDevice>();

export default function Index({ devices, filters, providers, inactivityMinutes, credential, can }: {
    devices: Paginated<TransportDevice>; filters: Record<string, string | undefined>; providers: ProviderOption[]; inactivityMinutes: number;
    credential: { device_code: string; credential: string } | null; can: { create: boolean; revoke: boolean; update: boolean };
}) {
    const { t, name } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = (next = draft) => router.get(route('transport.devices.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });
    const stale = (d: TransportDevice) => d.status === 'active' && (!d.last_seen_at || Date.now() - new Date(d.last_seen_at).getTime() > inactivityMinutes * 60000);

    const columns = [
        helper.accessor('device_code', { header: t('transport.deviceCode'), cell: (c) => <div><div className="font-medium">{c.getValue()}</div><div className="text-xs text-(--app-muted-foreground)">{c.row.original.name} · {c.row.original.credential_prefix}…</div></div> }),
        helper.display({ id: 'provider', header: t('transport.provider'), cell: (c) => name(c.row.original.provider) }),
        helper.display({ id: 'vehicle', header: t('transport.boundVehicle'), cell: (c) => c.row.original.vehicle?.plate_number ?? t('transport.anyVehicleOfProvider') }),
        helper.accessor('last_seen_at', { header: t('transport.lastSeen'), cell: (c) => <span className={stale(c.row.original) ? 'text-amber-600' : ''}>{c.getValue() ? <DateText value={c.getValue()} withTime /> : t('transport.neverSeen')}</span> }),
        helper.accessor('status', { header: t('transport.status'), cell: (c) => <Pill value={c.getValue()} /> }),
        helper.display({ id: 'actions', header: '', cell: (c) => {
            const d = c.row.original;
            if (d.status === 'revoked') return null;
            return (
                <div className="flex justify-end gap-1">
                    {can.update && <ActionDialogButton label={d.status === 'active' ? t('transport.suspend') : t('transport.reactivate')} size="xs" url={route('transport.devices.status', d.id)} data={{ status: d.status === 'active' ? 'suspended' : 'active' }} />}
                    {can.update && <ActionDialogButton label={t('transport.rotateCredential')} title={t('transport.rotateCredential')} description={t('transport.rotateCredentialHelp')} size="xs" url={route('transport.devices.rotate', d.id)} />}
                    {can.revoke && <ActionDialogButton label={t('transport.revoke')} title={t('transport.revokeDevice')} description={t('transport.revokeDeviceHelp')} destructive size="xs" url={route('transport.devices.revoke', d.id)} fields={[{ name: 'reason', label: t('transport.reason'), required: true }]} />}
                </div>
            );
        } }),
    ] as ColumnDef<TransportDevice, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.devices')} description={t('transport.devicesIntro')} actions={can.create ? <Link href={route('transport.devices.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.registerDevice')}</Link> : undefined} />}>
            <Head title={t('transport.devices')} />
            <div className="space-y-4">
                {credential && (
                    <Alert tone="warning" title={t('transport.credentialOnce')}>
                        <p>{t('transport.credentialOnceHelp')} <strong>{credential.device_code}</strong></p>
                        <code className="mt-2 block select-all break-all rounded-control bg-(--app-surface) p-2 font-mono text-sm">{credential.credential}</code>
                    </Alert>
                )}
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '', status: '' })} hasActiveFilters={Boolean(filters.provider_id || filters.status)}>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                    <FilterField label={t('transport.status')}><Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">{t('transport.all')}</option><option value="active">{t('transport.active')}</option><option value="suspended">{t('transport.statuses.suspended')}</option><option value="revoked">{t('transport.statuses.revoked')}</option></Select></FilterField>
                </FilterPanel>
                <DataTable data={devices.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(devices.meta)} onPageChange={(page) => router.get(route('transport.devices.index'), queryWith({ page }), { preserveState: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
