import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, FilterBar, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, pageTo, SelectOptions, StatusPill, useNames, type Meta, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type Row = {
    id: string; code: string; name_en: string; name_am: string | null; status: string;
    provider: NamePair; main_cafeteria: NamePair;
    branch_count: number; service_point_count: number; access_count: number;
};

export default function NetworksIndex({ networks, meta, filters, providers, can }: {
    networks: Row[]; meta: Meta; filters: { search?: string; provider_id?: string };
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const [search, setSearch] = useState(filters.search ?? '');
    const [providerId, setProviderId] = useState(filters.provider_id ?? '');
    const apply = () => router.get(route('cafeteria.networks.index'), { search, provider_id: providerId }, { preserveState: true });

    const columns: ColumnDef<Row, unknown>[] = [
        { id: 'network', header: t('cafeteriaPolicy.network'), cell: ({ row }) => (
            <Link href={route('cafeteria.networks.show', row.original.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">
                {label(row.original)} <span className="text-xs text-[color:var(--app-muted-foreground)]">{row.original.code}</span>
            </Link>
        ) },
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => label(row.original.provider) },
        { id: 'main', header: t('cafeteriaPolicy.mainCafeteria'), cell: ({ row }) => label(row.original.main_cafeteria) },
        { id: 'branches', header: t('cafeteriaPolicy.branches'), cell: ({ row }) => row.original.branch_count },
        { id: 'points', header: t('cafeteriaPolicy.servicePoints'), cell: ({ row }) => row.original.service_point_count },
        { id: 'access', header: t('cafeteriaPolicy.organizationsWithAccess'), cell: ({ row }) => row.original.access_count },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.networks')} description={t('cafeteriaPolicy.networksDescription')}
            actions={can.manage ? <Button as={Link} href={route('cafeteria.networks.create')} variant="primary" size="sm">{t('cafeteriaPolicy.createNetwork')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.networks')} />
            <div className="space-y-4">
                <FilterBar search={search} onSearchChange={setSearch} onSubmit={apply}
                    hasActiveFilters={Boolean(search || providerId)} onReset={() => router.get(route('cafeteria.networks.index'))}
                    actions={<Button type="submit" size="sm">{t('cafeteriaPolicy.search')}</Button>}>
                    <select aria-label={t('cafeteriaPolicy.provider')} className={`${inputCls} w-auto`} value={providerId} onChange={(e) => setProviderId(e.target.value)}>
                        <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={t('cafeteriaPolicy.all')} />
                    </select>
                </FilterBar>
                <DataTable data={networks} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')}
                    pagination={meta} onPageChange={pageTo(route('cafeteria.networks.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
