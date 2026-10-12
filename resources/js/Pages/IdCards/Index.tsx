import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
type CardRow = {
    id: string;
    card_number: string;
    status: string;
    issued_at?: string | null;
    expires_at?: string | null;
    previous_card_id?: string | null;
    employee?: {
        full_name: string;
        employee_number: string;
        photo_url?: string | null;
        current_assignment?: {
            organization?: { name_en: string; name_am?: string | null } | null;
            organization_unit?: { name_en: string; name_am?: string | null } | null;
            position?: { title_en: string; title_am?: string | null } | null;
        } | null;
    } | null;
    can?: {
        view?: boolean;
        issue?: boolean;
        activate?: boolean;
        reportLost?: boolean;
        reportDamaged?: boolean;
        replace?: boolean;
        revoke?: boolean;
    };
};

type PageProps = {
    cards: {
        data: CardRow[];
        meta: LaravelPageMeta;
    };
    can?: {
        submitRequest?: boolean;
        createPrintBatch?: boolean;
    };
    filters?: {
        search?: string;
        status?: string;
        organization_id?: string;
        issued_from?: string;
        expires_to?: string;
    };
    summary: { total: number; active: number; pending: number; expired: number; revoked: number; lost: number };
    organizations: Array<{ id: string; name_en: string; name_am?: string | null }>;
};

const CARD_STATUSES = [
    'pending_print', 'printed', 'issued', 'active',
    'expired', 'lost', 'damaged', 'suspended', 'revoked', 'replaced',
];

export default function IdCardsIndex({ cards, can, filters = {}, summary, organizations }: PageProps) {
    const { t, locale } = useLocale();

    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [organizationId, setOrganizationId] = useState(filters.organization_id ?? '');
    const [issuedFrom, setIssuedFrom] = useState(filters.issued_from ?? '');
    const [expiresTo, setExpiresTo] = useState(filters.expires_to ?? '');

    function applyFilters() {
        router.get(route('id-cards.index'), { search, status, organization_id: organizationId, issued_from: issuedFrom, expires_to: expiresTo }, {
            preserveState: true,
            replace: true,
        });
    }

    function resetFilters() {
        setSearch(''); setStatus(''); setOrganizationId(''); setIssuedFrom(''); setExpiresTo('');
        router.get(route('id-cards.index'), {}, { preserveState: false, replace: true });
    }

    const dateCls = cx(inputClassName, 'w-full');
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('idCards.title')}
                    description={t('idCards.indexDescription')}
                    actions={
                        <div className="flex gap-2">
                            {can?.submitRequest && (
                                <Link
                                    href={route('card-requests.create')}
                                    className={buttonClassName({ variant: 'primary' })}
                                >
                                    {t('idCards.newRequest')}
                                </Link>
                            )}
                            {can?.createPrintBatch && (
                                <Link
                                    href={route('print-batches.create')}
                                    className={buttonClassName({ variant: 'outline' })}
                                >
                                    {t('idCards.createPrintBatch')}
                                </Link>
                            )}
                            <Link
                                href={route('card-requests.index')}
                                className={buttonClassName({ variant: 'outline' })}
                            >
                                {t('idCards.smartButtons.requests')}
                            </Link>
                        </div>
                    }
                />
            }
        >
            <Head title={t('idCards.title')} />

            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                {[
                    ['totalCards', summary.total, 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'],
                    ['activeCards', summary.active, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
                    ['pendingApproval', summary.pending, 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
                    ['expiredCards', summary.expired, 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-foreground)]'],
                    ['revokedCards', summary.revoked, 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                    ['lostCards', summary.lost, 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
                ].map(([key, value, color]) => (
                    <div key={String(key)} className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                        <span className={`inline-flex rounded-lg px-2 py-1 text-lg font-bold ${color}`}>{value}</span>
                        <p className="mt-2 text-xs font-medium text-[color:var(--app-muted-foreground)]">{t(`idCards.${key}`)}</p>
                    </div>
                ))}
            </div>

            <FilterPanel onSubmit={applyFilters} onReset={resetFilters} hasActiveFilters={hasActiveFilters} className="mb-4">
                <FilterField label={t('common.search')} wide>
                    <Input type="search" placeholder={t('idCards.searchCards')} value={search} onChange={(e) => setSearch(e.target.value)} />
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">{t('common.allStatuses')}</option>
                        {CARD_STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`idCards.status_${s}`) || s}
                            </option>
                        ))}
                    </Select>
                </FilterField>
                <FilterField label={t('idCards.organization')}>
                    <Select value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {organizations.map((organization) => (
                            <option key={organization.id} value={organization.id}>{locale === 'am' ? organization.name_am ?? organization.name_en : organization.name_en}</option>
                        ))}
                    </Select>
                </FilterField>
                <FilterField label={t('idCards.issueDate')} htmlFor="cards-issued-from">
                    <LocalizedDatePicker id="cards-issued-from" className={dateCls} value={issuedFrom} onChange={setIssuedFrom} />
                </FilterField>
                <FilterField label={t('idCards.expiryDate')} htmlFor="cards-expires-to">
                    <LocalizedDatePicker id="cards-expires-to" className={dateCls} value={expiresTo} onChange={setExpiresTo} />
                </FilterField>
            </FilterPanel>

            <div className={tableContainerClassName}>
                {cards.data.length === 0 ? (
                    <EmptyState
                        title={t('idCards.noIdCardsFound')}
                        description={t('idCards.cardsAfterApproval')}
                        action={
                            can?.submitRequest ? (
                                <Link
                                    href={route('card-requests.create')}
                                    className={buttonClassName({ variant: 'primary' })}
                                >
                                    {t('idCards.newRequest')}
                                </Link>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('employees.employee')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.cardNumber')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('organizations.organization')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.organizationUnit')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.position')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('common.status')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.issueDate')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.expiryDate')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {cards.data.map((card) => (
                                    <tr
                                        key={card.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center gap-3">
                                                {card.employee?.photo_url ? <img src={card.employee.photo_url} alt="" className="h-9 w-9 rounded-lg object-cover" /> : <div className="h-9 w-9 rounded-lg bg-[color:var(--app-surface-muted)]" />}
                                                <div><p className="font-medium text-[color:var(--app-foreground)]">{card.employee?.full_name ?? t('common.notAvailable')}</p><p className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{card.employee?.employee_number}</p></div>
                                            </div>
                                        </td>
                                        <td className={tableCellClassName}>
                                            <span className="font-mono text-sm font-semibold text-[color:var(--app-foreground)]">{card.card_number}</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)] max-w-[200px] truncate')}>
                                            {locale === 'am' ? card.employee?.current_assignment?.organization?.name_am ?? card.employee?.current_assignment?.organization?.name_en : card.employee?.current_assignment?.organization?.name_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am ?? card.employee?.current_assignment?.organization_unit?.name_en : card.employee?.current_assignment?.organization_unit?.name_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>
                                            {locale === 'am' ? card.employee?.current_assignment?.position?.title_am ?? card.employee?.current_assignment?.position?.title_en : card.employee?.current_assignment?.position?.title_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <CardStatusBadge status={card.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={card.issued_at} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={card.expires_at} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            {card.can?.view !== false && (
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.view'), href: route('id-cards.show', card.id) },
                                                            { label: t('idCards.previewCard'), href: route('id-cards.preview', card.id) },
                                                        ]}
                                                    />
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <ListPagination meta={cards.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
