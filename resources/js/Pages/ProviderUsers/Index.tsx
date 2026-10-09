import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { ActionMenu, Button, Card, DataTable, FilterBar, Select, StatusBadge, type ActionItem, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { AccountActionDialog, AccountStatus, useLabel, type AccountAction, type AccountCan, type AccountSummary, type NamedItem } from '@/Components/ProviderUsers/AccountUi';

type Row = AccountSummary & { can: AccountCan };
type Meta = { currentPage: number; lastPage: number; total: number; perPage: number };
type Filters = { search?: string; provider_id?: string; status?: string; role?: string; service?: string; trashed?: string };

const ROLES = ['owner', 'manager', 'operator'] as const;
const STATUSES = ['active', 'inactive', 'suspended'] as const;

export default function ProviderUsersIndex({ rows, meta, filters, stats, providers, services, can }: {
    rows: Row[];
    meta: Meta;
    filters: Filters;
    stats: { total: number; active: number; suspended: number; inactive: number; must_change_password: number; deleted: number };
    providers: NamedItem[];
    services: Array<{ code: string; name_en: string | null; name_am: string | null }>;
    can: { create: boolean };
}) {
    const { t } = useLocale();
    const label = useLabel();
    const [form, setForm] = useState<Required<Filters>>({
        search: filters.search ?? '',
        provider_id: filters.provider_id ?? '',
        status: filters.status ?? '',
        role: filters.role ?? '',
        service: filters.service ?? '',
        trashed: filters.trashed ?? '',
    });
    const [pending, setPending] = useState<{ action: AccountAction; account: Row } | null>(null);

    const query = (values: Filters) => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== undefined));
    const apply = (values: Filters = form) => router.get(route('provider-users.index'), query(values), { preserveState: true, preserveScroll: true });
    const deleted = form.trashed === 'only';

    const columns: ColumnDef<Row, unknown>[] = [
        {
            id: 'account',
            header: t('providerUsers.account'),
            cell: ({ row }) => (
                <div className="min-w-0">
                    <Link href={route('provider-users.show', row.original.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{row.original.name}</Link>
                    <p className="truncate text-xs text-[color:var(--app-muted-foreground)]">{[row.original.email, row.original.username].filter(Boolean).join(' · ')}</p>
                    {row.original.must_change_password && !row.original.deleted_at && (
                        <StatusBadge tone="warning" className="mt-1">{t('providerUsers.statMustChange')}</StatusBadge>
                    )}
                </div>
            ),
        },
        {
            id: 'provider',
            header: t('providerUsers.provider'),
            cell: ({ row }) => row.original.provider ? (
                <div>
                    <p>{label(row.original.provider)}</p>
                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{[row.original.provider.code, label(row.original.provider.type, '')].filter(Boolean).join(' · ')}</p>
                </div>
            ) : '—',
        },
        { id: 'role', header: t('providerUsers.role'), cell: ({ row }) => t(`providerUsers.roles.${row.original.provider_role}`) },
        { id: 'status', header: t('providerUsers.status'), cell: ({ row }) => <AccountStatus account={row.original} /> },
        {
            id: 'lastLogin',
            header: t('providerUsers.lastLogin'),
            cell: ({ row }) => <LocalizedDateDisplay value={row.original.last_login_at} withTime fallback={t('providerUsers.never')} />,
        },
        {
            id: 'actions',
            header: t('providerUsers.actions'),
            cell: ({ row }) => {
                const account = row.original;
                const open = (action: AccountAction) => () => setPending({ action, account });
                const items: ActionItem[] = [
                    { label: t('providerUsers.view'), href: route('provider-users.show', account.id), show: account.can.view },
                    { label: t('providerUsers.edit'), href: route('provider-users.edit', account.id), show: account.can.update },
                    { label: t('providerUsers.resetPassword'), onClick: open('resetPassword'), show: account.can.resetPassword },
                    { label: t('providerUsers.activate'), onClick: open('activate'), show: account.can.activate },
                    { label: t('providerUsers.suspend'), onClick: open('suspend'), show: account.can.suspend, danger: true },
                    { label: t('providerUsers.restore'), onClick: open('restore'), show: account.can.restore },
                    { label: t('providerUsers.delete'), onClick: open('delete'), show: account.can.delete, danger: true, separatorBefore: true },
                ];
                return <ActionMenu items={items} renderLink={(item, className) => <Link href={item.href!} className={className}>{item.label}</Link>} />;
            },
        },
    ];

    const statCards: Array<{ key: string; value: number; onClick?: () => void }> = [
        { key: 'statTotal', value: stats.total, onClick: () => apply({ ...form, status: '', trashed: '' }) },
        { key: 'statActive', value: stats.active, onClick: () => apply({ ...form, status: 'active', trashed: '' }) },
        { key: 'statSuspended', value: stats.suspended, onClick: () => apply({ ...form, status: 'suspended', trashed: '' }) },
        { key: 'statInactive', value: stats.inactive, onClick: () => apply({ ...form, status: 'inactive', trashed: '' }) },
        { key: 'statMustChange', value: stats.must_change_password },
        { key: 'statDeleted', value: stats.deleted, onClick: () => apply({ ...form, status: '', trashed: 'only' }) },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('providerUsers.title')} description={t('providerUsers.subtitle')}
            actions={can.create ? <Button as={Link} href={route('provider-users.create')} variant="primary" size="sm">{t('providerUsers.create')}</Button> : undefined} />}>
            <Head title={t('providerUsers.title')} />

            <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {statCards.map((card) => (
                        <Card key={card.key} className="p-3">
                            {card.onClick ? (
                                <button type="button" onClick={card.onClick} className="w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]">
                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{t(`providerUsers.${card.key}`)}</p>
                                    <p className="text-xl font-semibold tabular-nums text-[color:var(--app-foreground)]">{card.value}</p>
                                </button>
                            ) : (
                                <>
                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{t(`providerUsers.${card.key}`)}</p>
                                    <p className="text-xl font-semibold tabular-nums text-[color:var(--app-foreground)]">{card.value}</p>
                                </>
                            )}
                        </Card>
                    ))}
                </div>

                <FilterBar
                    search={form.search}
                    onSearchChange={(search) => setForm({ ...form, search })}
                    searchPlaceholder={t('providerUsers.searchPlaceholder')}
                    onSubmit={() => apply()}
                    hasActiveFilters={Object.values(form).some(Boolean)}
                    onReset={() => router.get(route('provider-users.index'))}
                    actions={<Button type="submit" size="sm">{t('providerUsers.search')}</Button>}
                >
                    <Select aria-label={t('providerUsers.provider')} className="w-auto" value={form.provider_id} onChange={(e) => setForm({ ...form, provider_id: e.target.value })}>
                        <option value="">{t('providerUsers.allProviders')}</option>
                        {providers.map((p) => <option key={p.id} value={p.id}>{label(p)}{p.code ? ` (${p.code})` : ''}</option>)}
                    </Select>
                    <Select aria-label={t('providerUsers.status')} className="w-auto" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                        <option value="">{t('providerUsers.allStatuses')}</option>
                        {STATUSES.map((s) => <option key={s} value={s}>{t(`providerUsers.statuses.${s}`)}</option>)}
                    </Select>
                    <Select aria-label={t('providerUsers.role')} className="w-auto" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                        <option value="">{t('providerUsers.allRoles')}</option>
                        {ROLES.map((r) => <option key={r} value={r}>{t(`providerUsers.roles.${r}`)}</option>)}
                    </Select>
                    <Select aria-label={t('providerUsers.providerServices')} className="w-auto" value={form.service} onChange={(e) => setForm({ ...form, service: e.target.value })}>
                        <option value="">{t('providerUsers.allServices')}</option>
                        {services.map((s) => <option key={s.code} value={s.code}>{label(s)}</option>)}
                    </Select>
                    <Select aria-label={t('providerUsers.statDeleted')} className="w-auto" value={form.trashed} onChange={(e) => setForm({ ...form, trashed: e.target.value })}>
                        <option value="">{t('providerUsers.currentAccounts')}</option>
                        <option value="only">{t('providerUsers.deletedAccounts')}</option>
                    </Select>
                </FilterBar>

                <DataTable
                    data={rows}
                    columns={columns}
                    emptyTitle={Object.values(filters).some(Boolean) || deleted ? t('providerUsers.noResults') : t('providerUsers.noAccounts')}
                    emptyAction={can.create && !Object.values(filters).some(Boolean)
                        ? <Button as={Link} href={route('provider-users.create')} variant="primary" size="sm">{t('providerUsers.create')}</Button>
                        : undefined}
                    pagination={meta}
                    onPageChange={(page) => router.get(route('provider-users.index'), { ...query(filters), page }, { preserveState: true, preserveScroll: true })}
                />
            </div>

            <AccountActionDialog action={pending?.action ?? null} account={pending?.account ?? null} onClose={() => setPending(null)} />
        </AuthenticatedLayout>
    );
}
