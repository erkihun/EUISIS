import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, FilterBar, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { ActionButton, inputCls, pageTo, SelectOptions, StatusPill, useNames, type Meta, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type Row = {
    id: string; organization: NamePair; provider: NamePair; network: NamePair; cafeteria: NamePair;
    policy_count: number; effective_from: string | null; effective_to: string | null; status: string;
};

export default function AssignmentsIndex({ rows, meta, filters, organizations, providers, can }: {
    rows: Row[]; meta: Meta; filters: { organization_id?: string; provider_id?: string; status?: string };
    organizations: Array<{ id: string; code: string; name_en: string; name_am: string | null }>;
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    can: { create: boolean; update: boolean; approve: boolean; end: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const [f, setF] = useState({ organization_id: filters.organization_id ?? '', provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = () => router.get(route('cafeteria.assignments.index'), f, { preserveState: true });
    const scopeOf = (r: Row) => r.cafeteria ? `${t('cafeteriaPolicy.scopeCafeteria')}: ${label(r.cafeteria)}`
        : r.network ? `${t('cafeteriaPolicy.scopeNetwork')}: ${label(r.network)}` : t('cafeteriaPolicy.scopeProvider');

    const columns: ColumnDef<Row, unknown>[] = [
        { id: 'organization', header: t('cafeteriaPolicy.organization'), cell: ({ row }) => <span className="font-medium">{label(row.original.organization)}</span> },
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => label(row.original.provider) },
        { id: 'scope', header: t('cafeteriaPolicy.scope'), cell: ({ row }) => scopeOf(row.original) },
        { id: 'policies', header: t('cafeteriaPolicy.navPolicies'), cell: ({ row }) => (
            <Link href={route('cafeteria.policies.create', { assignment_id: row.original.id })} className="text-[color:var(--color-primary)] hover:underline">{row.original.policy_count}</Link>
        ) },
        { id: 'from', header: t('cafeteriaPolicy.effectiveFrom'), cell: ({ row }) => <LocalizedDateDisplay value={row.original.effective_from} /> },
        { id: 'to', header: t('cafeteriaPolicy.effectiveTo'), cell: ({ row }) => row.original.effective_to ? <LocalizedDateDisplay value={row.original.effective_to} /> : t('cafeteriaPolicy.openEnded') },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
        { id: 'actions', header: t('cafeteriaPolicy.actions'), cell: ({ row }) => (
            <div className="flex flex-wrap gap-2">
                {can.update && row.original.status === 'pending_approval' && <Button as={Link} href={route('cafeteria.assignments.edit', row.original.id)} size="sm" variant="ghost">{t('cafeteriaPolicy.edit')}</Button>}
                {can.approve && row.original.status === 'pending_approval' && <ActionButton primary label={t('cafeteriaPolicy.approve')} url={route('cafeteria.assignments.approve', row.original.id)} />}
                {can.end && ['pending_approval', 'active'].includes(row.original.status) && <ActionButton destructive field="effective_to" label={t('cafeteriaPolicy.endAssignment')} url={route('cafeteria.assignments.end', row.original.id)} />}
            </div>
        ) },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.navAssignments')} description={t('cafeteriaPolicy.assignmentsDescription')}
            actions={can.create ? <Button as={Link} href={route('cafeteria.assignments.create')} variant="primary" size="sm">{t('cafeteriaPolicy.createAssignment')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.navAssignments')} />
            <div className="space-y-4">
                <FilterBar onSubmit={apply} hasActiveFilters={Boolean(f.organization_id || f.provider_id || f.status)} onReset={() => router.get(route('cafeteria.assignments.index'))}
                    actions={<Button type="submit" size="sm">{t('cafeteriaPolicy.search')}</Button>}>
                    <select aria-label={t('cafeteriaPolicy.organization')} className={`${inputCls} w-auto`} value={f.organization_id} onChange={(e) => setF({ ...f, organization_id: e.target.value })}>
                        <SelectOptions items={organizations} placeholder={t('cafeteriaPolicy.organization')} />
                    </select>
                    <select aria-label={t('cafeteriaPolicy.provider')} className={`${inputCls} w-auto`} value={f.provider_id} onChange={(e) => setF({ ...f, provider_id: e.target.value })}>
                        <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={t('cafeteriaPolicy.provider')} />
                    </select>
                    <select aria-label={t('cafeteriaPolicy.status')} className={`${inputCls} w-auto`} value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                        <option value="">{t('cafeteriaPolicy.status')}</option>
                        {['pending_approval', 'active', 'ended', 'cancelled'].map((s) => <option key={s} value={s}>{t(`cafeteriaPolicy.statuses.${s}`)}</option>)}
                    </select>
                </FilterBar>
                <DataTable data={rows} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')} pagination={meta} onPageChange={pageTo(route('cafeteria.assignments.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
