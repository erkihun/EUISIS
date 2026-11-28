import ConfirmationDialog from '@/Components/ConfirmationDialog';
import EmptyState from '@/Components/EmptyState';
import { AlertTriangle, CalendarIcon, CheckCircle, ClockIcon, Users, XCircle } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel, ListPagination, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, Period } from '@/modules/leave/components/Common';
import { PlanState, PlanStatus, type PlanRow } from '@/modules/leave/components/ScheduleCard';
import { inputCls, mutedCls, useLeave } from '@/modules/leave/lib/helpers';
import type { Bilingual, EmployeeRef, Paginated } from '@/modules/leave/types';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type StaffPlan = PlanRow & { employee: EmployeeRef | null; unit: (Bilingual & { id: string }) | null; can_decide: boolean };

interface Props {
    leavePlans: Paginated<StaffPlan>;
    filters: { year: number; search: string; status: string };
    planYears: number[];
    planSummary: { year: number; starts_on: string; ends_on: string; employees_with_plan: number; waiting: number; employees_without_plan: number | null };
}

/**
 * The leave schedule of the team (supervisor) or of the institution (HR):
 * the periods employees said, at the start of the budget year, they mean to
 * take their annual leave, in date order, so the work can be planned.
 */
export default function Plans({ leavePlans, filters, planYears, planSummary }: Props): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const [draft, setDraft] = useState({ year: String(filters.year), search: filters.search, status: filters.status });
    const [sendingBack, setSendingBack] = useState<StaffPlan | null>(null);
    const apply = (next: { year: string; search: string; status: string }) => router.get(route('leave.plans.index'),
        { year: next.year, ...(next.search.trim() === '' ? {} : { search: next.search.trim() }), ...(next.status === '' ? {} : { status: next.status }) }, { preserveState: true });
    const decide = (plan: StaffPlan, decision: 'approve' | 'reject', comment = '') =>
        router.post(route('leave.plans.decide', plan.id), { decision, comment }, { preserveScroll: true, onFinish: () => setSendingBack(null) });
    const without = planSummary.employees_without_plan;

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('plans.title')} description={lt('plans.description')} />}>
            <Head title={lt('plans.title')} />
            <ActionErrors />
            <div className="space-y-4 pb-4">
                <section aria-label={lt('plans.title')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                    <AppMetricCard label={lt('plans.waiting')} value={planSummary.waiting} variant={planSummary.waiting > 0 ? 'warning' : 'success'} icon={<ClockIcon className="h-5 w-5" />} />
                    <AppMetricCard label={lt('plans.periods')} value={leavePlans.total} variant="primary" icon={<CalendarIcon className="h-5 w-5" />} />
                    <AppMetricCard label={lt('plans.withPlan')} value={planSummary.employees_with_plan} variant="success" icon={<Users className="h-5 w-5" />} />
                    {without !== null && <AppMetricCard label={lt('plans.withoutPlan')} value={without} detail={lt('plans.withoutPlanHelp')} variant={without > 0 ? 'warning' : 'success'} icon={<AlertTriangle className="h-5 w-5" />} />}
                </section>

                <FilterPanel onSubmit={() => apply(draft)} onReset={() => router.get(route('leave.plans.index'))} hasActiveFilters={filters.search !== '' || filters.status !== ''}>
                    <FilterField label={lt('plans.search')} wide><input type="search" className={inputCls} value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} /></FilterField>
                    <FilterField label={lt('schedule.approval')}>
                        <select className={inputCls} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{lt('common.all')}</option>
                            {['pending', 'approved', 'rejected'].map((status) => <option key={status} value={status}>{lt(`schedule.status.${status}`)}</option>)}
                        </select>
                    </FilterField>
                    <FilterField label={lt('show.budgetYear')}>
                        <select className={inputCls} value={draft.year} onChange={(e) => setDraft({ ...draft, year: e.target.value })}>
                            {planYears.map((year) => <option key={year} value={year}>{lt('balance.budgetYear', { year })}</option>)}
                        </select>
                    </FilterField>
                </FilterPanel>

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{lt('plans.tableTitle', { year: planSummary.year })}</h2>
                        <p className={cx(mutedCls, 'mt-0.5 text-xs')}><LocalizedDateDisplay value={planSummary.starts_on} /> – <LocalizedDateDisplay value={planSummary.ends_on} /></p>
                    </div>
                    {leavePlans.data.length === 0 ? <EmptyState title={lt(filters.search !== '' || filters.status !== '' ? 'plans.noMatches' : 'plans.emptyTitle')} description={filters.search !== '' || filters.status !== '' ? undefined : lt('plans.emptyDescription')} /> : (
                        <TableTools id="leave-schedule"><Table>
                            <THead>
                                <Tr>
                                    <Th>{lt('table.employee')}</Th><Th>{lt('table.period')}</Th><Th>{lt('table.days')}</Th>
                                    <Th>{lt('schedule.note')}</Th><Th>{lt('schedule.approval')}</Th><Th>{lt('schedule.request')}</Th><Th className="text-end">{lt('table.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {leavePlans.data.map((plan) => (
                                    <Tr key={plan.id}>
                                        <Td>
                                            <div className="font-medium text-(--app-foreground)">{name(plan.employee)}</div>
                                            <div className={cx(mutedCls, 'text-xs')}>{plan.employee?.employee_number}{plan.unit ? ` · ${name(plan.unit)}` : ''}</div>
                                        </Td>
                                        <Td><Period row={{ starts_on: plan.starts_on, ends_on: plan.ends_on, is_half_day: false, half_day_part: null }} /></Td>
                                        <Td>{dayCount(plan.days_counted, 'working')}</Td>
                                        <Td>{plan.note ?? '—'}</Td>
                                        <Td><PlanStatus plan={plan} /></Td>
                                        <Td><PlanState plan={plan} /></Td>
                                        <Td className="whitespace-nowrap text-end">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[
                                                    { label: lt('plans.approve'), onClick: () => decide(plan, 'approve'), show: plan.can_decide, icon: <CheckCircle className="h-4 w-4" /> },
                                                    { label: lt('plans.sendBack'), onClick: () => setSendingBack(plan), show: plan.can_decide, variant: 'danger', icon: <XCircle className="h-4 w-4" /> },
                                                ]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table></TableTools>
                    )}
                </TableCard>
                <ListPagination meta={leavePlans} />
            </div>

            <ConfirmationDialog open={sendingBack !== null} onClose={() => setSendingBack(null)} variant="danger" requireReason reasonLabel={lt('form.reason')}
                title={lt('plans.sendBackTitle')} description={lt('plans.sendBackDescription')} confirmLabel={lt('plans.sendBack')} cancelLabel={lt('common.cancel')}
                onConfirm={(reason) => sendingBack && decide(sendingBack, 'reject', reason ?? '')} />
        </AuthenticatedLayout>
    );
}
