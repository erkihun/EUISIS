import PageHeader from '@/Components/PageHeader';
import { FilterField, FilterPanel, ListPagination } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, RequestTable } from '@/modules/leave/components/Common';
import { usePopupRecord } from '@/modules/leave/components/Popup';
import { RequestPopup, type OpenRequest } from '@/modules/leave/components/RequestPopup';
import { inputCls, useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveRow, Paginated } from '@/modules/leave/types';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    leaveRequests: Paginated<LeaveRow>;
    filters: { status: 'pending' | 'all' };
    leaveOpenRequest: OpenRequest | null;
    today: string;
}

/** The supervisor's inbox: the leave requests of the people who report to them. */
export default function Approvals({ leaveRequests, filters, leaveOpenRequest, today }: Props): JSX.Element {
    const { lt } = useLeave();
    const [status, setStatus] = useState(filters.status);
    const request = usePopupRecord('request', 'leaveOpenRequest', ['leaveRequests'], leaveOpenRequest?.detail.id ?? null);
    const apply = (next: 'pending' | 'all') => router.get(route('leave.approvals'), next === 'pending' ? {} : { status: next }, { preserveState: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('approvals.title')} description={lt('approvals.description')} />}>
            <Head title={lt('approvals.title')} />
            {request.openId === null && <ActionErrors />}
            <div className="space-y-4 pb-4">
                <FilterPanel onSubmit={() => apply(status)} onReset={() => { setStatus('pending'); apply('pending'); }} hasActiveFilters={filters.status !== 'pending'}>
                    <FilterField label={lt('table.status')}>
                        <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value as 'pending' | 'all')}>
                            <option value="pending">{lt('approvals.pending')}</option>
                            <option value="all">{lt('approvals.all')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>
                <RequestTable title={lt('approvals.tableTitle')} toolsId="leave-approvals" rows={leaveRequests.data}
                    hrefFor={(row) => route('leave.requests.show', row.id)} onOpen={(row) => request.open(row.id)}
                    emptyTitle={lt(filters.status === 'pending' ? 'approvals.emptyPending' : 'approvals.emptyAll')} emptyDescription={lt('approvals.emptyDescription')} />
                <ListPagination meta={leaveRequests} />
            </div>

            <RequestPopup openId={request.openId} record={leaveOpenRequest} staff today={today} fullHref={(id) => route('leave.requests.show', id)} onClose={request.close} />
        </AuthenticatedLayout>
    );
}
