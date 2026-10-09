import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, FilterBar, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, Money, pageTo, SelectOptions, StatusPill, useNames, type Meta, type NamePair } from '@/Components/Cafeteria/PolicyUi';

export type PolicySummary = {
    id: string; policy_group_id: string; version_no: number;
    organization: NamePair; provider: NamePair; network: NamePair; cafeteria: NamePair; scope_level: 'cafeteria' | 'network' | 'provider';
    daily_subsidy_amount: string; employee_contribution_amount: string; provider_price: string; currency_code: string;
    max_daily_uses: number; allow_advance_usage: boolean; advance_max_days: number | null; extra_scan_policy: string;
    working_days: string[]; effective_from: string | null; effective_to: string | null; status: string;
};

export function usageRule(p: PolicySummary, t: (k: string) => string): string {
    const days = p.working_days.map((d) => t(`cafeteriaPolicy.weekdays.${d}`)).join(' ');
    const advance = p.allow_advance_usage ? ` · ${t('cafeteriaPolicy.allowAdvanceUsage')}${p.advance_max_days !== null ? ` (${p.advance_max_days})` : ''}` : '';
    return `${days} · ${p.max_daily_uses}×${advance}`;
}

export default function PoliciesIndex({ rows, meta, filters, organizations, providers, can }: {
    rows: PolicySummary[]; meta: Meta; filters: { organization_id?: string; provider_id?: string; status?: string };
    organizations: Array<{ id: string; code: string; name_en: string; name_am: string | null }>;
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const [f, setF] = useState({ organization_id: filters.organization_id ?? '', provider_id: filters.provider_id ?? '', status: filters.status ?? '' });
    const apply = () => router.get(route('cafeteria.policies.index'), f, { preserveState: true });

    const columns: ColumnDef<PolicySummary, unknown>[] = [
        { id: 'organization', header: t('cafeteriaPolicy.organization'), cell: ({ row }) => (
            <Link href={route('cafeteria.policies.show', row.original.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{label(row.original.organization)}</Link>
        ) },
        { id: 'scope', header: `${t('cafeteriaPolicy.network')} / ${t('cafeteriaPolicy.cafeteria')}`, cell: ({ row }) => label(row.original.cafeteria ?? row.original.network, t('cafeteriaPolicy.scopeProvider')) },
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => label(row.original.provider) },
        { id: 'version', header: t('cafeteriaPolicy.version'), cell: ({ row }) => `v${row.original.version_no}` },
        { id: 'subsidy', header: t('cafeteriaPolicy.dailySubsidy'), cell: ({ row }) => <Money value={row.original.daily_subsidy_amount} currency={row.original.currency_code} /> },
        { id: 'contribution', header: t('cafeteriaPolicy.employeeContribution'), cell: ({ row }) => <Money value={row.original.employee_contribution_amount} currency={row.original.currency_code} /> },
        { id: 'price', header: t('cafeteriaPolicy.providerPrice'), cell: ({ row }) => <Money value={row.original.provider_price} currency={row.original.currency_code} /> },
        { id: 'usage', header: t('cafeteriaPolicy.usageRule'), cell: ({ row }) => <span className="text-xs">{usageRule(row.original, t)}</span> },
        { id: 'from', header: t('cafeteriaPolicy.effectiveFrom'), cell: ({ row }) => <LocalizedDateDisplay value={row.original.effective_from} /> },
        { id: 'to', header: t('cafeteriaPolicy.effectiveTo'), cell: ({ row }) => row.original.effective_to ? <LocalizedDateDisplay value={row.original.effective_to} /> : t('cafeteriaPolicy.openEnded') },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.navPolicies')} description={t('cafeteriaPolicy.policiesDescription')}
            actions={can.create ? <Button as={Link} href={route('cafeteria.policies.create')} variant="primary" size="sm">{t('cafeteriaPolicy.createPolicy')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.navPolicies')} />
            <div className="space-y-4">
                <FilterBar onSubmit={apply} hasActiveFilters={Boolean(f.organization_id || f.provider_id || f.status)} onReset={() => router.get(route('cafeteria.policies.index'))}
                    actions={<Button type="submit" size="sm">{t('cafeteriaPolicy.search')}</Button>}>
                    <select aria-label={t('cafeteriaPolicy.organization')} className={`${inputCls} w-auto`} value={f.organization_id} onChange={(e) => setF({ ...f, organization_id: e.target.value })}>
                        <SelectOptions items={organizations} placeholder={t('cafeteriaPolicy.organization')} />
                    </select>
                    <select aria-label={t('cafeteriaPolicy.provider')} className={`${inputCls} w-auto`} value={f.provider_id} onChange={(e) => setF({ ...f, provider_id: e.target.value })}>
                        <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={t('cafeteriaPolicy.provider')} />
                    </select>
                    <select aria-label={t('cafeteriaPolicy.status')} className={`${inputCls} w-auto`} value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                        <option value="">{t('cafeteriaPolicy.status')}</option>
                        {['draft', 'under_review', 'approved', 'active', 'superseded', 'expired', 'cancelled'].map((s) => <option key={s} value={s}>{t(`cafeteriaPolicy.statuses.${s}`)}</option>)}
                    </select>
                </FilterBar>
                <DataTable data={rows} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')} pagination={meta} onPageChange={pageTo(route('cafeteria.policies.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
