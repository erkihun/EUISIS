import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import CardRequestStatusBadge from '@/Components/IdCards/CardRequestStatusBadge';
import CardRequestTypeBadge from '@/Components/IdCards/CardRequestTypeBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';

import { FilterField, FilterPanel, Input, ListPagination, Select, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
    cardRequests: LaravelPageMeta & { data: CardRequestRow[] };
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

    const hasActiveFilters = Object.values(filters).some(Boolean);

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

            <FilterPanel onSubmit={applyFilters} onReset={resetFilters} hasActiveFilters={hasActiveFilters} className="mb-4">
                <FilterField label={t('common.search')} wide>
                    <Input type="search" placeholder={t('idCards.searchRequests')} value={search} onChange={(e) => setSearch(e.target.value)} />
                </FilterField>
                <FilterField label={t('common.status')}>
                    <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">{t('common.allStatuses')}</option>
                        {REQUEST_STATUSES.map((s) => (
                            <option key={s} value={s}>
                                {t(`idCards.requestStatus_${s}`) || s}
                            </option>
                        ))}
                    </Select>
                </FilterField>
                <FilterField label={t('idCards.requestType')}>
                    <Select value={reqType} onChange={(e) => setReqType(e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {REQUEST_TYPES.map((rt) => (
                            <option key={rt} value={rt}>
                                {t(`idCards.requestType_${rt}`) || rt}
                            </option>
                        ))}
                    </Select>
                </FilterField>
            </FilterPanel>

            <div className={tableContainerClassName}>
                {cardRequests.data.length === 0 ? (
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
                                                <span className="text-[color:var(--app-muted-foreground)]">—</span>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <CardRequestStatusBadge status={req.status} />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                            <LocalizedDateDisplay value={req.submitted_at} withTime />
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('card-requests.show', req.id), show: req.can?.view !== false },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <ListPagination meta={cardRequests} />
            </div>
        </AuthenticatedLayout>
    );
}
