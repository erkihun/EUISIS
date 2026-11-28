import { Plus } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BalanceCards } from '@/modules/leave/components/BalanceCards';
import { ActionErrors, Notice, RequestTable } from '@/modules/leave/components/Common';
import { LeaveRequestForm } from '@/modules/leave/components/LeaveRequestForm';
import { LeavePopup, usePopupRecord } from '@/modules/leave/components/Popup';
import { RequestPopup, type OpenRequest } from '@/modules/leave/components/RequestPopup';
import { ScheduleCard, type LeaveSchedule, type PlanRow } from '@/modules/leave/components/ScheduleCard';
import { primaryBtn, secondaryBtn, useLeave } from '@/modules/leave/lib/helpers';
import type { EmployeeRef, LeaveRow, LeaveSummary, Paginated } from '@/modules/leave/types';
import { Head } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    hasEmployee: boolean;
    leaveSummary: LeaveSummary | null;
    leaveRequests: Paginated<LeaveRow> | null;
    leaveEmployee: EmployeeRef | null;
    leaveSchedule: LeaveSchedule | null;
    leaveOpenRequest: OpenRequest | null;
    today: string;
    can: { request: boolean };
}

/** My Portal > My leave: what I may still take this budget year, when I mean to take it, and what I asked for. */
export default function MyLeave({ hasEmployee, leaveSummary, leaveRequests, leaveEmployee, leaveSchedule, leaveOpenRequest, today, can }: Props): JSX.Element {
    const { lt, name } = useLeave();
    // The ask-for-leave popup: empty, or started from a period of the schedule.
    const [asking, setAsking] = useState<{ plan: PlanRow | null } | null>(null);
    const request = usePopupRecord('request', 'leaveOpenRequest', ['leaveRequests', 'leaveSummary', 'leaveSchedule'], leaveOpenRequest?.detail.id ?? null);
    const popupOpen = asking !== null || request.openId !== null;
    const ask = can.request && (
        <button type="button" className={cx(primaryBtn, 'gap-2')} onClick={() => setAsking({ plan: null })}>
            <Plus className="h-4 w-4" aria-hidden />{lt('my.ask')}
        </button>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('my.title')} description={lt('my.description')} actions={ask} />}>
            <Head title={lt('my.title')} />
            {!popupOpen && <ActionErrors except={['starts_on', 'ends_on', 'note']} />}
            {!hasEmployee || !leaveSummary || !leaveRequests ? <Notice tone="warning">{lt('my.noEmployee')}</Notice> : (
                <div className="space-y-4 pb-4">
                    <BalanceCards summary={leaveSummary} />
                    {leaveSchedule && leaveSchedule.rows.length === 0 && can.request && <Notice tone="info">{lt('schedule.reminder', { year: leaveSchedule.budget_year })}</Notice>}
                    {leaveSchedule && (
                        <ScheduleCard schedule={leaveSchedule} today={today} canPlan={can.request} onAsk={(plan) => setAsking({ plan })} onOpenRequest={request.open} />
                    )}
                    <RequestTable title={lt('my.requests')} toolsId="leave-my-requests" rows={leaveRequests.data} showEmployee={false}
                        hrefFor={(row) => route('employee.leave.show', row.id)} onOpen={(row) => request.open(row.id)}
                        emptyTitle={lt('my.emptyTitle')} emptyDescription={lt('my.emptyDescription')} />
                    <ListPagination meta={leaveRequests} />
                </div>
            )}

            <LeavePopup show={asking !== null} onClose={() => setAsking(null)} title={lt('form.title')} subtitle={leaveEmployee ? `${name(leaveEmployee)} · ${leaveEmployee.employee_number ?? ''}` : null}>
                <ActionErrors except={['leave_type_id', 'starts_on', 'ends_on', 'is_half_day', 'expected_delivery_on', 'reason', 'contact_during_leave']} />
                {asking?.plan && <Notice tone="info">{lt('schedule.askingFromPlan')}</Notice>}
                {leaveSummary && asking && (
                    <LeaveRequestForm key={asking.plan?.id ?? 'new'} employee={leaveEmployee} summary={leaveSummary} today={today} stay onSuccess={() => setAsking(null)}
                        initial={asking.plan ? { plan_id: asking.plan.id, leave_type_id: asking.plan.leave_type.id, starts_on: asking.plan.starts_on < today ? today : asking.plan.starts_on, ends_on: asking.plan.ends_on } : undefined}
                        cancel={<button type="button" className={secondaryBtn} onClick={() => setAsking(null)}>{lt('common.close')}</button>} />
                )}
            </LeavePopup>

            <RequestPopup openId={request.openId} record={leaveOpenRequest} staff={false} today={today} fullHref={(id) => route('employee.leave.show', id)} onClose={request.close} />
        </AuthenticatedLayout>
    );
}
