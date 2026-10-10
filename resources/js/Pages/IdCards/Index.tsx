import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

import { buttonClassName, cx, inputClassName, selectClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            links: Array<{ url: string | null; label: string; active: boolean }>;
        };
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

    const selectCls = selectClassName;
    const inputCls = inputClassName;

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
                                    className="rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-gray-50 transition-colors"
                                >
                                    {t('idCards.createPrintBatch')}
                                </Link>
                            )}
                            <Link
                                href={route('card-requests.index')}
                                className="rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-gray-50 transition-colors"
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
                    ['expiredCards', summary.expired, 'bg-slate-100 text-[color:var(--app-foreground)] dark:bg-slate-700'],
                    ['revokedCards', summary.revoked, 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                    ['lostCards', summary.lost, 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
                ].map(([key, value, color]) => (
                    <div key={String(key)} className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                        <span className={`inline-flex rounded-lg px-2 py-1 text-lg font-bold ${color}`}>{value}</span>
                        <p className="mt-2 text-xs font-medium text-[color:var(--app-muted-foreground)]">{t(`idCards.${key}`)}</p>
                    </div>
                ))}
            </div>

            {/* Filter bar */}
            <div className="mb-4 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                <div className="flex flex-wrap gap-3 items-end">
                    <div className="flex-1 min-w-[180px]">
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                            {t('common.search')}
                        </label>
                        <input
                            className={inputCls + ' w-full'}
                            placeholder={t('idCards.searchCards')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') applyFilters(); }}
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                            {t('common.status')}
                        </label>
                        <select className={selectCls} value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="">{t('common.filter')}…</option>
                            {CARD_STATUSES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`idCards.status_${s}`) || s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="min-w-[190px]">
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('idCards.organization')}</label>
                        <select className={selectCls + ' w-full'} value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>{locale === 'am' ? organization.name_am ?? organization.name_en : organization.name_en}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('idCards.issueDate')}</label>
                        <input type="date" className={inputCls} value={issuedFrom} onChange={(e) => setIssuedFrom(e.target.value)} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('idCards.expiryDate')}</label>
                        <input type="date" className={inputCls} value={expiresTo} onChange={(e) => setExpiresTo(e.target.value)} />
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={applyFilters}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {t('common.filter')}
                        </button>
                        <button
                            type="button"
                            onClick={resetFilters}
                            className={buttonClassName({ variant: 'outline' })}
                        >
                            {t('common.reset')}
                        </button>
                    </div>
                </div>
            </div>

            <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                {cards.data.length === 0 ? (
                    <div className="p-8">
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
                    </div>
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
                                                <div><p className="font-medium text-[color:var(--app-foreground)]">{card.employee?.full_name ?? t('common.notAvailable')}</p><p className="font-mono text-xs text-gray-500">{card.employee?.employee_number}</p></div>
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
                                                <div className="flex justify-end gap-3"><Link href={route('id-cards.show', card.id)} className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">{t('common.view')}</Link><Link href={route('id-cards.preview', card.id)} className="text-xs font-medium text-[color:var(--app-muted-foreground)] hover:text-gray-900">{t('idCards.previewCard')}</Link></div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {cards.meta.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[color:var(--app-border)] px-4 py-3 text-xs text-[color:var(--app-muted-foreground)]">
                        <span>
                            {t('common.page')} {cards.meta.current_page} {t('common.of')} {cards.meta.last_page}
                        </span>
                        <div className="flex gap-1">{cards.meta.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg border px-3 py-1.5 ${link.active ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary)] text-white' : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-[color:var(--app-border)] px-3 py-1.5 opacity-50" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
