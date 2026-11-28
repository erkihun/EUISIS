import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ConfirmationDialog from '@/Components/ConfirmationDialog';
import EmptyState from '@/Components/EmptyState';
import { CalendarIcon, Plus, TrashIcon } from '@/Components/Icons';
import { AppActionMenu, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import { ActionErrors, Notice, Period, StatusBadge } from '@/modules/leave/components/Common';
import { LeavePopup } from '@/modules/leave/components/Popup';
import { badge, errorCls, formatDays, inputCls, labelCls, mutedCls, primaryBtn, secondaryBtn, useLeave } from '@/modules/leave/lib/helpers';
import type { Bilingual, LeaveStatus } from '@/modules/leave/types';
import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

export interface PlanRow {
    id: string;
    budget_year: number;
    starts_on: string;
    ends_on: string;
    days_counted: number;
    note: string | null;
    status: 'pending' | 'approved' | 'rejected';
    decision_comment: string | null;
    auto_error: string | null;
    leave_type: Bilingual & { id: string };
    request: { id: string; reference_number: string; status: LeaveStatus } | null;
}

export interface LeaveSchedule {
    /** Settings page: the system counts an approved period as leave on its first day. */
    auto_count: boolean;
    budget_year: number;
    year_starts_on: string;
    year_ends_on: string;
    planned_days: number;
    rows: PlanRow[];
}

const PLAN_TONE = { pending: 'warning', approved: 'success', rejected: 'danger' } as const;

/** Where a planned period stands with the institution, and why it was sent back if it was. */
export function PlanStatus({ plan }: { plan: PlanRow }): JSX.Element {
    const { lt } = useLeave();

    return (
        <span>
            <span className={badge(PLAN_TONE[plan.status])}>{lt(`schedule.status.${plan.status}`)}</span>
            {plan.status === 'rejected' && plan.decision_comment && <span className={cx(mutedCls, 'mt-1 block text-xs')}>{plan.decision_comment}</span>}
            {plan.auto_error && <span className="mt-1 block text-xs text-red-700 dark:text-red-400">{lt('schedule.notCounted')} {plan.auto_error}</span>}
        </span>
    );
}

/** One period of a schedule, in the words every schedule table uses. */
export function PlanState({ plan, onOpenRequest }: { plan: PlanRow; onOpenRequest?: (id: string) => void }): JSX.Element {
    const { lt } = useLeave();
    if (plan.request === null) return <span className={mutedCls}>{lt('schedule.notAsked')}</span>;

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {onOpenRequest
                ? <button type="button" className="font-medium text-primary hover:underline" onClick={() => onOpenRequest(plan.request!.id)}>{plan.request.reference_number}</button>
                : <span className="font-medium">{plan.request.reference_number}</span>}
            <StatusBadge status={plan.request.status} />
        </span>
    );
}

/**
 * My leave schedule for the budget year: the periods I told the institution
 * I mean to take, so my unit can plan around them. A period is added in a
 * popup; "ask for this leave" turns one into a leave request.
 */
export function ScheduleCard({ schedule, today, canPlan, onAsk, onOpenRequest }: {
    schedule: LeaveSchedule;
    today: string;
    canPlan: boolean;
    onAsk: (plan: PlanRow) => void;
    onOpenRequest: (id: string) => void;
}): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<PlanRow | null>(null);
    const form = useForm({ starts_on: '', ends_on: '', note: '' });
    const fieldError = (field: 'starts_on' | 'ends_on' | 'note') => (form.errors[field] ? <p className={errorCls}>{form.errors[field]}</p> : null);
    const close = () => {
        setAdding(false);
        form.clearErrors();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.leave.plans.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setAdding(false); } });
    };

    return (
        <>
            <TableCard>
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{lt('schedule.title', { year: schedule.budget_year })}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}>
                            {lt('schedule.description')} <LocalizedDateDisplay value={schedule.year_starts_on} /> – <LocalizedDateDisplay value={schedule.year_ends_on} />
                            {schedule.rows.length > 0 && <> · {lt('schedule.planned', { days: formatDays(schedule.planned_days) })}</>}
                        </p>
                    </div>
                    {canPlan && (
                        <button type="button" className={cx(secondaryBtn, 'gap-2')} onClick={() => setAdding(true)}>
                            <Plus className="h-4 w-4" aria-hidden />{lt('schedule.add')}
                        </button>
                    )}
                </div>
                {schedule.rows.length === 0 ? <EmptyState title={lt('schedule.emptyTitle')} description={lt('schedule.emptyDescription')} /> : (
                    <TableTools id="leave-my-schedule"><Table>
                        <THead>
                            <Tr>
                                <Th>{lt('table.period')}</Th><Th>{lt('table.days')}</Th><Th>{lt('table.type')}</Th>
                                <Th>{lt('schedule.note')}</Th><Th>{lt('schedule.approval')}</Th><Th>{lt('schedule.request')}</Th><Th className="text-end">{lt('table.actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {schedule.rows.map((plan) => (
                                <Tr key={plan.id}>
                                    <Td><Period row={{ starts_on: plan.starts_on, ends_on: plan.ends_on, is_half_day: false, half_day_part: null }} /></Td>
                                    <Td>{dayCount(plan.days_counted, 'working')}</Td>
                                    <Td>{name(plan.leave_type)}</Td>
                                    <Td>{plan.note ?? '—'}</Td>
                                    <Td><PlanStatus plan={plan} /></Td>
                                    <Td><PlanState plan={plan} onOpenRequest={onOpenRequest} /></Td>
                                    <Td className="whitespace-nowrap text-end">
                                        <div className="flex justify-end">
                                            <AppActionMenu items={[
                                                // Asking again for an approved period would mean a second approval: the system counts it on its first day,
                                                // and a period that is still waiting or was sent back can be asked for as an ordinary request.
                                                { label: lt('schedule.ask'), onClick: () => onAsk(plan), show: canPlan && (plan.status !== 'approved' || !schedule.auto_count) && plan.request === null && plan.ends_on >= today, icon: <CalendarIcon className="h-4 w-4" /> },
                                                { label: lt('schedule.remove'), onClick: () => setRemoving(plan), show: canPlan && plan.request === null && (plan.status !== 'approved' || !schedule.auto_count || plan.starts_on > today), variant: 'danger', icon: <TrashIcon className="h-4 w-4" /> },
                                            ]} />
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table></TableTools>
                )}
            </TableCard>

            <LeavePopup show={adding} onClose={close} title={lt('schedule.addTitle')} subtitle={lt('schedule.addHelp')}>
                <ActionErrors except={['starts_on', 'ends_on', 'note']} />
                <form onSubmit={submit} className="space-y-4">
                    <Notice tone="info">{lt('schedule.notARequest')}</Notice>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span className={labelCls}>{lt('form.startsOn')}<span className="text-red-600"> *</span></span>
                            <LocalizedDatePicker value={form.data.starts_on} min={today} max={schedule.year_ends_on} onChange={(v) => form.setData('starts_on', v)} />
                            {fieldError('starts_on')}
                        </div>
                        <div>
                            <span className={labelCls}>{lt('form.endsOn')}<span className="text-red-600"> *</span></span>
                            <LocalizedDatePicker value={form.data.ends_on} min={form.data.starts_on || today} max={schedule.year_ends_on} onChange={(v) => form.setData('ends_on', v)} />
                            {fieldError('ends_on')}
                        </div>
                    </div>
                    <div>
                        <label htmlFor="lv-plan-note" className={labelCls}>{lt('schedule.note')}</label>
                        <input id="lv-plan-note" type="text" maxLength={500} className={inputCls} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                        {fieldError('note')}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" className={secondaryBtn} onClick={close}>{lt('common.close')}</button>
                        <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.starts_on || !form.data.ends_on}>{lt('schedule.save')}</button>
                    </div>
                </form>
            </LeavePopup>

            <ConfirmationDialog open={removing !== null} onClose={() => setRemoving(null)} variant="danger"
                title={lt('schedule.removeTitle')} description={lt('schedule.removeDescription')} confirmLabel={lt('schedule.remove')} cancelLabel={lt('common.keep')}
                onConfirm={() => removing && router.delete(route('employee.leave.plans.destroy', removing.id), { preserveScroll: true, onFinish: () => setRemoving(null) })} />
        </>
    );
}
