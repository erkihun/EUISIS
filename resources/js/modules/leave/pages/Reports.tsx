import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { CalendarIcon, ClockIcon, Users } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import { AppMetricCard, Table, TableCard, TableTools, TBody, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, RequestTable } from '@/modules/leave/components/Common';
import { usePopupRecord } from '@/modules/leave/components/Popup';
import { RequestPopup, type OpenRequest } from '@/modules/leave/components/RequestPopup';
import { labelCls, panelCls, useLeave } from '@/modules/leave/lib/helpers';
import type { Bilingual, LeaveRow } from '@/modules/leave/types';
import { Head, router } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    reportDate: string;
    budgetYear: number;
    onLeave: LeaveRow[];
    upcoming: LeaveRow[];
    byType: (Bilingual & { id: string; day_unit: 'working' | 'calendar'; requests: number; days: number })[];
    waiting: { supervisor: number; hr: number };
    leaveOpenRequest: OpenRequest | null;
    today: string;
}

/** Leave across the institutions in scope: who is away on a day, who will be, and what was taken this budget year. */
export default function Reports({ reportDate, budgetYear, onLeave, upcoming, byType, waiting, leaveOpenRequest, today }: Props): JSX.Element {
    const { lt, name, dayCount } = useLeave();
    const hrefFor = (row: LeaveRow) => route('leave.requests.show', row.id);
    const request = usePopupRecord('request', 'leaveOpenRequest', ['onLeave', 'upcoming', 'byType', 'waiting'], leaveOpenRequest?.detail.id ?? null);

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('reports.title')} description={lt('reports.description')} />}>
            <Head title={lt('reports.title')} />
            {request.openId === null && <ActionErrors />}
            <div className="space-y-4 pb-4">
                <div className={cx(panelCls, 'flex flex-wrap items-end gap-4 p-4')}>
                    <div className="min-w-56">
                        <span className={labelCls}>{lt('reports.date')}</span>
                        <LocalizedDatePicker value={reportDate} onChange={(v) => v && router.get(route('leave.reports'), { date: v }, { preserveState: true, preserveScroll: true })} />
                    </div>
                </div>

                <section aria-label={lt('reports.title')} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                    <AppMetricCard label={lt('reports.onLeave')} value={onLeave.length} variant="primary" icon={<Users className="h-5 w-5" />} />
                    <AppMetricCard label={lt('reports.upcoming')} value={upcoming.length} variant="success" icon={<CalendarIcon className="h-5 w-5" />} />
                    <AppMetricCard label={lt('reports.waitingSupervisor')} value={waiting.supervisor} variant={waiting.supervisor > 0 ? 'warning' : 'success'} icon={<ClockIcon className="h-5 w-5" />} />
                    <AppMetricCard label={lt('reports.waitingHr')} value={waiting.hr} variant={waiting.hr > 0 ? 'warning' : 'success'} icon={<ClockIcon className="h-5 w-5" />} />
                </section>

                <RequestTable title={lt('reports.onLeave')} toolsId="leave-report-on-leave" rows={onLeave} hrefFor={hrefFor} onOpen={(row) => request.open(row.id)} emptyTitle={lt('reports.nobodyOnLeave')} />
                <RequestTable title={lt('reports.upcomingTitle')} toolsId="leave-report-upcoming" rows={upcoming} hrefFor={hrefFor} onOpen={(row) => request.open(row.id)} emptyTitle={lt('reports.nothingUpcoming')} />

                <TableCard>
                    <div className="border-b border-(--app-border) px-4 py-3">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{lt('reports.byType', { year: budgetYear })}</h2>
                    </div>
                    <TableTools id="leave-report-by-type"><Table>
                        <THead><Tr><Th>{lt('table.type')}</Th><Th>{lt('reports.requests')}</Th><Th>{lt('table.days')}</Th></Tr></THead>
                        <TBody>
                            {byType.map((type) => (
                                <Tr key={type.id}><Td>{name(type)}</Td><Td>{type.requests}</Td><Td>{dayCount(type.days, type.day_unit)}</Td></Tr>
                            ))}
                        </TBody>
                    </Table></TableTools>
                </TableCard>
            </div>

            <RequestPopup openId={request.openId} record={leaveOpenRequest} staff today={today} fullHref={(id) => route('leave.requests.show', id)} onClose={request.close} />
        </AuthenticatedLayout>
    );
}
