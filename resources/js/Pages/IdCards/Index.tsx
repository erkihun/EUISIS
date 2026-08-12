import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

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

    const selectCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200';
    const inputCls  = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:placeholder:text-slate-500';

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
                                    className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
                                >
                                    {t('idCards.newRequest')}
                                </Link>
                            )}
                            {can?.createPrintBatch && (
                                <Link
                                    href={route('print-batches.create')}
                                    className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    {t('idCards.createPrintBatch')}
                                </Link>
                            )}
                            <Link
                                href={route('card-requests.index')}
                                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200"
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
                    ['expiredCards', summary.expired, 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200'],
                    ['revokedCards', summary.revoked, 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                    ['lostCards', summary.lost, 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
                ].map(([key, value, color]) => (
                    <div key={String(key)} className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <span className={`inline-flex rounded-lg px-2 py-1 text-lg font-bold ${color}`}>{value}</span>
                        <p className="mt-2 text-xs font-medium text-gray-500 dark:text-slate-400">{t(`idCards.${key}`)}</p>
                    </div>
                ))}
            </div>

            {/* Filter bar */}
            <div className="mb-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-wrap gap-3 items-end">
                    <div className="flex-1 min-w-[180px]">
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
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
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
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
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">{t('idCards.organization')}</label>
                        <select className={selectCls + ' w-full'} value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>{locale === 'am' ? organization.name_am ?? organization.name_en : organization.name_en}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">{t('idCards.issueDate')}</label>
                        <input type="date" className={inputCls} value={issuedFrom} onChange={(e) => setIssuedFrom(e.target.value)} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">{t('idCards.expiryDate')}</label>
                        <input type="date" className={inputCls} value={expiresTo} onChange={(e) => setExpiresTo(e.target.value)} />
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={applyFilters}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
                        >
                            {t('common.filter')}
                        </button>
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 transition-colors dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            {t('common.reset')}
                        </button>
                    </div>
                </div>
            </div>

            <div className="rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                {cards.data.length === 0 ? (
                    <div className="p-8">
                        <EmptyState
                            title={t('idCards.noIdCardsFound')}
                            description={t('idCards.cardsAfterApproval')}
                            action={
                                can?.submitRequest ? (
                                    <Link
                                        href={route('card-requests.create')}
                                        className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                                    >
                                        {t('idCards.newRequest')}
                                    </Link>
                                ) : undefined
                            }
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-200 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-800/50">
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('employees.employee')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.cardNumber')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('organizations.organization')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.organizationUnit')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.position')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.status')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.issueDate')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.expiryDate')}
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {cards.data.map((card) => (
                                    <tr
                                        key={card.id}
                                        className="transition-colors hover:bg-gray-50 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                {card.employee?.photo_url ? <img src={card.employee.photo_url} alt="" className="h-9 w-9 rounded-lg object-cover" /> : <div className="h-9 w-9 rounded-lg bg-gray-100 dark:bg-slate-800" />}
                                                <div><p className="font-medium text-gray-900 dark:text-slate-100">{card.employee?.full_name ?? t('common.notAvailable')}</p><p className="font-mono text-xs text-gray-500">{card.employee?.employee_number}</p></div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-sm font-semibold text-gray-900 dark:text-slate-100">{card.card_number}</span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-400 max-w-[200px] truncate">
                                            {locale === 'am' ? card.employee?.current_assignment?.organization?.name_am ?? card.employee?.current_assignment?.organization?.name_en : card.employee?.current_assignment?.organization?.name_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                            {locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am ?? card.employee?.current_assignment?.organization_unit?.name_en : card.employee?.current_assignment?.organization_unit?.name_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600 dark:text-slate-400">
                                            {locale === 'am' ? card.employee?.current_assignment?.position?.title_am ?? card.employee?.current_assignment?.position?.title_en : card.employee?.current_assignment?.position?.title_en ?? t('common.notAvailable')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <CardStatusBadge status={card.status} />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={card.issued_at} />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">
                                            <LocalizedDateDisplay value={card.expires_at} />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {card.can?.view !== false && (
                                                <div className="flex justify-end gap-3"><Link href={route('id-cards.show', card.id)} className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">{t('common.view')}</Link><Link href={route('id-cards.preview', card.id)} className="text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-slate-300">{t('idCards.previewCard')}</Link></div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {cards.meta.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-slate-800 dark:text-slate-400">
                        <span>
                            {t('common.page')} {cards.meta.current_page} {t('common.of')} {cards.meta.last_page}
                        </span>
                        <div className="flex gap-1">{cards.meta.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg border px-3 py-1.5 ${link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-800'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-gray-100 px-3 py-1.5 opacity-50 dark:border-slate-800" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
