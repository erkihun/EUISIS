import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import PageHeader from '@/Components/PageHeader';
import { FilterField, FilterPanel, ListPagination } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, Notice, RequestTable } from '@/modules/leave/components/Common';
import { usePopupRecord } from '@/modules/leave/components/Popup';
import { RequestPopup, type OpenRequest } from '@/modules/leave/components/RequestPopup';
import { inputCls, useLeave } from '@/modules/leave/lib/helpers';
import type { Bilingual, LeaveRow, Paginated } from '@/modules/leave/types';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Filters {
    status: string;
    type: string;
    search: string;
    from: string;
    to: string;
}

interface Props {
    leaveRequests: Paginated<LeaveRow>;
    filters: Filters;
    leaveTypes: (Bilingual & { id: string })[];
    waiting: number;
    leaveOpenRequest: OpenRequest | null;
    today: string;
}

const STATUSES = ['pending_supervisor', 'pending_hr', 'approved', 'rejected', 'cancelled'];

/** The institution's leave register: every request of the employees in the officer's scope. */
export default function Requests({ leaveRequests, filters, leaveTypes, waiting, leaveOpenRequest, today }: Props): JSX.Element {
    const { lt, name } = useLeave();
    const [draft, setDraft] = useState<Filters>(filters);
    const request = usePopupRecord('request', 'leaveOpenRequest', ['leaveRequests', 'waiting'], leaveOpenRequest?.detail.id ?? null);
    const active = Object.values(filters).some((value) => value !== '');
    const apply = (next: Filters) => router.get(route('leave.requests.index'), Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '')), { preserveState: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('requests.title')} description={lt('requests.description')} />}>
            <Head title={lt('requests.title')} />
            {request.openId === null && <ActionErrors />}
            <div className="space-y-4 pb-4">
                {waiting > 0 && filters.status !== 'pending_hr' && (
                    <Notice tone="info">
                        {lt('requests.waiting', { n: waiting })}{' '}
                        <button type="button" className="font-medium underline" onClick={() => apply({ ...filters, status: 'pending_hr' })}>{lt('requests.showWaiting')}</button>
                    </Notice>
                )}
                <FilterPanel onSubmit={() => apply(draft)} onReset={() => router.get(route('leave.requests.index'))} hasActiveFilters={active}>
                    <FilterField label={lt('requests.search')} wide><input type="search" className={inputCls} value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} /></FilterField>
                    <FilterField label={lt('table.status')}>
                        <select className={inputCls} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{lt('common.all')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{lt(`status.${status}`)}</option>)}
                        </select>
                    </FilterField>
                    <FilterField label={lt('table.type')}>
                        <select className={inputCls} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
                            <option value="">{lt('common.all')}</option>
                            {leaveTypes.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                        </select>
                    </FilterField>
                    <FilterField label={lt('requests.from')}><LocalizedDatePicker value={draft.from} onChange={(v) => setDraft({ ...draft, from: v })} /></FilterField>
                    <FilterField label={lt('requests.to')}><LocalizedDatePicker value={draft.to} min={draft.from || undefined} onChange={(v) => setDraft({ ...draft, to: v })} /></FilterField>
                </FilterPanel>

                <RequestTable title={lt('requests.tableTitle')} toolsId="leave-requests" rows={leaveRequests.data}
                    hrefFor={(row) => route('leave.requests.show', row.id)} onOpen={(row) => request.open(row.id)}
                    emptyTitle={lt(active ? 'requests.noMatches' : 'requests.emptyTitle')} emptyDescription={active ? undefined : lt('requests.emptyDescription')} />
                <ListPagination meta={leaveRequests} />
            </div>

            <RequestPopup openId={request.openId} record={leaveOpenRequest} staff today={today} fullHref={(id) => route('leave.requests.show', id)} onClose={request.close} />
        </AuthenticatedLayout>
    );
}
