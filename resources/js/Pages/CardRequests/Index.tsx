import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardRequestStatusBadge from '@/Components/IdCards/CardRequestStatusBadge';
import CardRequestTypeBadge from '@/Components/IdCards/CardRequestTypeBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

import { buttonClassName, cx, selectClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

    const selectCls = selectClassName;
    const inputCls  = 'rounded-lg border border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-3 py-2 text-sm text-[color:var(--app-foreground)] placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:placeholder:text-slate-500';

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
                                className={buttonClassName({ variant: 'primary' })}
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
            <div className="mb-4 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                <div className="flex flex-wrap gap-3 items-end">
                    <div className="flex-1 min-w-[180px]">
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                {cardRequests.data.length === 0 ? (
                    <div className="p-8">
                        <EmptyState
                            title={t('idCards.noCardRequestsFound')}
                            description={t('idCards.noRequestsDescription')}
                            action={
                                can?.create ? (
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
                                        {t('employees.employeeNumber')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('organizations.organization')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('idCards.requestType')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('common.status')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>
                                        {t('common.createdAt')}
                                    </th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {cardRequests.data.map((req) => (
                                    <tr
                                        key={req.id}
                                        className={tableRowClassName}
                                    >
                                        <td className={tableCellClassName}>
                                            <p className="font-mono text-sm font-medium text-[color:var(--app-foreground)]">
                                                {req.employee?.employee_number}
                                            </p>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {req.employee?.full_name}
                                            </p>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)] max-w-[200px] truncate')}>
                                            {req.employee?.current_assignment?.organization?.name_en ?? '—'}
                                        </td>
                                        <td className={tableCellClassName}>
                                            {req.request_type ? (
                                                <CardRequestTypeBadge type={req.request_type} />
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <CardRequestStatusBadge status={req.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={req.submitted_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            {req.can?.view !== false && (
                                                <Link
                                                    href={route('card-requests.show', req.id)}
                                                    className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
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
                    <div className="flex items-center justify-between border-t border-[color:var(--app-border)] px-4 py-3 text-xs text-[color:var(--app-muted-foreground)]">
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
