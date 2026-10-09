import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { Button, DataTable, FilterBar, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { pageTo, StatusPill, useNames, type Meta } from '@/Components/Cafeteria/PolicyUi';

type Row = { id: string; code: string; name_en: string; name_am: string | null; contact_person: string | null; email: string | null; status: string; network_count: number; cafeteria_count: number };

export default function PayeesIndex({ rows, meta, filters, can }: { rows: Row[]; meta: Meta; filters: { search?: string }; can: { manage: boolean } }) {
    const { t } = useLocale();
    const { label } = useNames();
    const [search, setSearch] = useState(filters.search ?? '');

    const columns: ColumnDef<Row, unknown>[] = [
        { id: 'provider', header: t('cafeteriaPolicy.provider'), cell: ({ row }) => <span className="font-medium">{label(row.original)} <span className="text-xs text-[color:var(--app-muted-foreground)]">{row.original.code}</span></span> },
        { id: 'contact', header: t('cafeteriaPolicy.contactPerson'), cell: ({ row }) => row.original.contact_person ?? '—' },
        { id: 'networks', header: t('cafeteriaPolicy.networks'), cell: ({ row }) => <Link href={route('cafeteria.networks.index', { provider_id: row.original.id })} className="text-[color:var(--color-primary)] hover:underline">{row.original.network_count}</Link> },
        { id: 'cafeterias', header: t('cafeteriaPolicy.cafeterias'), cell: ({ row }) => row.original.cafeteria_count },
        { id: 'status', header: t('cafeteriaPolicy.status'), cell: ({ row }) => <StatusPill status={row.original.status} /> },
        { id: 'actions', header: t('cafeteriaPolicy.actions'), cell: ({ row }) => can.manage ? (
            <div className="flex gap-2">
                <Button as={Link} href={route('cafeteria.payees.edit', row.original.id)} size="sm" variant="ghost">{t('cafeteriaPolicy.edit')}</Button>
                <Button as={Link} href={route('cafeteria.networks.create', { provider_id: row.original.id })} size="sm" variant="ghost">{t('cafeteriaPolicy.createNetwork')}</Button>
            </div>
        ) : null },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteriaPolicy.providers')}
            actions={can.manage ? <Button as={Link} href={route('cafeteria.payees.create')} variant="primary" size="sm">{t('cafeteriaPolicy.create')}</Button> : undefined} />}>
            <Head title={t('cafeteriaPolicy.providers')} />
            <div className="space-y-4">
                <FilterBar search={search} onSearchChange={setSearch} onSubmit={() => router.get(route('cafeteria.payees.index'), { search }, { preserveState: true })}
                    hasActiveFilters={Boolean(search)} onReset={() => router.get(route('cafeteria.payees.index'))}
                    actions={<Button type="submit" size="sm">{t('cafeteriaPolicy.search')}</Button>} />
                <DataTable data={rows} columns={columns} emptyTitle={t('cafeteriaPolicy.noResults')} pagination={meta} onPageChange={pageTo(route('cafeteria.payees.index'), filters)} />
            </div>
        </AuthenticatedLayout>
    );
}
