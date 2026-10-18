import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, type ColumnDef, FilterField, FilterPanel, Select } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { ActionButton, inputCls, pageTo, SelectOptions, StatusPill, useNames, type Meta, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type Row = {
    id: string; organization: NamePair; provider: NamePair; network: NamePair; primary_cafeteria: NamePair;
    allow_cross_location_usage: boolean; exception_count: number;
    effective_from: string | null; effective_to: string | null; status: string;
};

type Option = { id: string; code: string; name_en: string; name_am: string | null };

export default function AccessIndex({ rows, meta, filters, organizations, networks, can }: {
    rows: Row[]; meta: Meta; filters: { organization_id?: string; network_id?: string; status?: string };
    organizations: Option[]; networks: Option[];
    can: { manage: boolean; approve: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const [f, setF] = useState({ organization_id: filters.organization_id ?? '', network_id: filters.network_id ?? '', status: filters.status ?? '' });
    const apply = () => router.get(route('cafeteria.access.index'), f, { preserveState: true });

    const columns: ColumnDef<Row, unknown>[] = [
        { id: 'organization', header: t('cafeteriaPolicy.organization'), cell: ({ row }) => <span className="font-medium">{label(row.original.organization)}</span> },
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => label(row.original.provider) },
        { id: 'network', header: t('cafeteriaPolicy.network'), cell: ({ row }) => label(row.original.network) },
        { id: 'primary', header: t('cafeteriaPolicy.primaryCafeteria'), cell: ({ row }) => label(row.original.primary_cafeteria) },
        { id: 'cross', header: t('cafeteriaPolicy.crossLocationUsage'), cell: ({ row }) => (
            <>{row.original.allow_cross_location_usage ? t('cafeteriaPolicy.yes') : t('cafeteriaPolicy.no')}{row.original.exception_count > 0 && <span className="ms-1 text-xs text-(--app-muted-foreground)">(+{row.original.exception_count})</span>}</>
        ) },
        { id: 'from', header: t('cafeteriaPolicy.effectiveFrom'), cell: ({ row }) => <LocalizedDateDisplay value={row.original.effective_from} /> },
        { id: 'to', header: t('cafeteriaPolicy.effectiveTo'), cell: ({ row }) => row.original.effective_to ? <LocalizedDateDisplay value={row.original.effective_to} /> : t('cafeteriaPolicy.openEnded') },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
        { id: 'actions', header: t('cafeteriaPolicy.actions'), cell: ({ row }) => (
            <div className="flex flex-wrap gap-2">
                {can.manage && ['pending_approval', 'active'].includes(row.original.status) && <Button as={Link} href={route('cafeteria.access.edit', row.original.id)} size="sm" variant="ghost">{t('cafeteriaPolicy.edit')}</Button>}
                {can.approve && row.original.status === 'pending_approval' && <ActionButton primary label={t('cafeteriaPolicy.approve')} url={route('cafeteria.access.approve', row.original.id)} />}
                {can.manage && ['pending_approval', 'active'].includes(row.original.status) && <ActionButton destructive field="effective_to" label={t('cafeteriaPolicy.endAccess')} url={route('cafeteria.access.end', row.original.id)} />}
            </div>
        ) },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.navAccess')} description={t('cafeteriaPolicy.accessDescription')}
            actions={can.manage ? <Button as={Link} href={route('cafeteria.access.create')} variant="primary" size="sm">{t('cafeteriaPolicy.grantAccess')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.navAccess')} />
            <div className="space-y-4">
                <FilterPanel onSubmit={apply} onReset={() => router.get(route('cafeteria.access.index'))} hasActiveFilters={Boolean(f.organization_id || f.network_id || f.status)}>
                    <FilterField label={t('cafeteriaPolicy.organization')}>
                        <Select value={f.organization_id} onChange={(e) => setF({ ...f, organization_id: e.target.value })}>
                            <SelectOptions items={organizations} placeholder={t('cafeteriaPolicy.all')} />
                        </Select>
                    </FilterField>
                    <FilterField label={t('cafeteriaPolicy.network')}>
                        <Select value={f.network_id} onChange={(e) => setF({ ...f, network_id: e.target.value })}>
                            <SelectOptions items={networks} placeholder={t('cafeteriaPolicy.all')} />
                        </Select>
                    </FilterField>
                    <FilterField label={t('cafeteriaPolicy.status')}>
                        <Select value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                            <option value="">{t('cafeteriaPolicy.all')}</option>
                            {['pending_approval', 'active', 'ended', 'cancelled'].map((s) => <option key={s} value={s}>{t(`cafeteriaPolicy.statuses.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>
                <DataTable data={rows} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')} pagination={meta} onPageChange={pageTo(route('cafeteria.access.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
