import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardRequestStatusBadge from '@/Components/IdCards/CardRequestStatusBadge';
import CardRequestTypeBadge from '@/Components/IdCards/CardRequestTypeBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

type CardRequestRow = {
    id: string;
    request_type?: string | null;
    status: string;
    request_reason?: string | null;
    submitted_at?: string | null;
    employee?: {
        id: string;
        employee_number: string;
        full_name: string;
        current_assignment?: { organization?: { name_en: string } | null } | null;
    } | null;
    can?: {
        view?: boolean;
        verify?: boolean;
        approve?: boolean;
        reject?: boolean;
        cancel?: boolean;
    };
};

type PageProps = {
    cardRequests: {
        data: CardRequestRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    can?: { create?: boolean };
    filters?: {
        search?: string;
        status?: string;
        request_type?: string;
    };
};

const REQUEST_STATUSES = ['draft', 'submitted', 'verified', 'approved', 'rejected', 'cancelled'];
const REQUEST_TYPES    = ['new', 'renewal', 'replacement', 'lost', 'damaged', 'correction'];

export default function CardRequestsIndex({ cardRequests, can, filters = {} }: PageProps) {
    const { t } = useLocale();

    const [search, setSearch]   = useState(filters.search ?? '');
    const [status, setStatus]   = useState(filters.status ?? '');
    const [reqType, setReqType] = useState(filters.request_type ?? '');

    function applyFilters() {
        router.get(route('card-requests.index'), { search, status, request_type: reqType }, {
            preserveState: true,
            replace: true,
        });
    }

    function resetFilters() {
        setSearch(''); setStatus(''); setReqType('');
        router.get(route('card-requests.index'), {}, { preserveState: false, replace: true });
    }

    const selectCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200';
    const inputCls  = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:placeholder:text-slate-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('idCards.cardRequests')}
                    description=""
                    actions={
                        can?.create && (
                            <Link
                                href={route('card-requests.create')}
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
                            >
                                {t('idCards.newRequest')}
                            </Link>
                        )
                    }
                />
            }
        >
            <Head title={t('idCards.cardRequests')} />

            {/* Filter bar */}
            <div className="mb-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-wrap gap-3 items-end">
                    <div className="flex-1 min-w-[180px]">
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('common.search')}
                        </label>
                        <input
                            className={inputCls + ' w-full'}
                            placeholder={t('idCards.searchRequests')}
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
                            {REQUEST_STATUSES.map((s) => (
                                <option key={s} value={s}>
                                    {t(`idCards.requestStatus_${s}`) || s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('idCards.requestType')}
                        </label>
                        <select className={selectCls} value={reqType} onChange={(e) => setReqType(e.target.value)}>
                            <option value="">{t('common.filter')}…</option>
                            {REQUEST_TYPES.map((rt) => (
                                <option key={rt} value={rt}>
                                    {t(`idCards.requestType_${rt}`) || rt}
                                </option>
                            ))}
                        </select>
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
                {cardRequests.data.length === 0 ? (
                    <div className="p-8">
                        <EmptyState
                            title={t('idCards.noCardRequestsFound')}
                            description={t('idCards.noRequestsDescription')}
                            action={
                                can?.create ? (
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
                                        {t('employees.employeeNumber')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('organizations.organization')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('idCards.requestType')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.status')}
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.createdAt')}
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                {cardRequests.data.map((req) => (
                                    <tr
                                        key={req.id}
                                        className="text-gray-700 transition-colors hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/50"
                                    >
                                        <td className="px-4 py-3">
                                            <p className="font-mono text-sm font-medium text-gray-900 dark:text-slate-100">
                                                {req.employee?.employee_number}
                                            </p>
                                            <p className="text-xs text-gray-400 dark:text-slate-500">
                                                {req.employee?.full_name}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-400 max-w-[200px] truncate">
                                            {req.employee?.current_assignment?.organization?.name_en ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {req.request_type ? (
                                                <CardRequestTypeBadge type={req.request_type} />
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <CardRequestStatusBadge status={req.status} />
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-400 dark:text-slate-500">
                                            <LocalizedDateDisplay value={req.submitted_at} withTime />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {req.can?.view !== false && (
                                                <Link
                                                    href={route('card-requests.show', req.id)}
                                                    className="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    {t('common.view')}
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {cardRequests.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-slate-800 dark:text-slate-500">
                        <span>
                            {t('common.page')} {cardRequests.current_page} {t('common.of')} {cardRequests.last_page}
                        </span>
                        <span>{cardRequests.total} {t('common.total').toLowerCase()}</span>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
