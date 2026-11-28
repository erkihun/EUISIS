import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BalanceCards } from '@/modules/leave/components/BalanceCards';
import { AdjustForm, LedgerTable, PostponeForm } from '@/modules/leave/components/BalanceForms';
import { ActionErrors, Notice } from '@/modules/leave/components/Common';
import { mutedCls, panelCls, sectionTitleCls, useLeave } from '@/modules/leave/lib/helpers';
import type { EmployeeRef, LeaveSummary, LedgerLine } from '@/modules/leave/types';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    leaveEmployee: EmployeeRef;
    leaveSummary: LeaveSummary;
    ledger: LedgerLine[];
    can: { adjust: boolean; postpone: boolean };
}

/** One employee's leave: the balances, the ledger behind them, and the corrections an officer may make. */
export default function EmployeeBalance({ leaveEmployee, leaveSummary, ledger, can }: Props): JSX.Element {
    const { lt, name } = useLeave();
    const hasWaiting = leaveSummary.types.some((type) => (type.waiting ?? []).length > 0);

    return (
        <AuthenticatedLayout header={<PageHeader title={name(leaveEmployee)} description={`${leaveEmployee.employee_number ?? ''} · ${lt('employee.description')}`} backHref={route('leave.balances.index')} />}>
            <Head title={name(leaveEmployee)} />
            <ActionErrors except={['days', 'reason', 'until_year', 'entitlement_year', 'leave_type_id']} />
            <div className="space-y-6 pb-6">
                {!leaveEmployee.service_start_date && <Notice tone="warning">{lt('employee.noServiceStart')}</Notice>}
                <BalanceCards summary={leaveSummary} />

                {can.postpone && hasWaiting && (
                    <section className={cx(panelCls, 'space-y-3 p-5')}>
                        <h2 className={sectionTitleCls}>{lt('employee.postponeTitle')}</h2>
                        <PostponeForm employeeId={leaveEmployee.id} summary={leaveSummary} />
                    </section>
                )}

                {can.adjust && (
                    <section className={cx(panelCls, 'space-y-3 p-5')}>
                        <h2 className={sectionTitleCls}>{lt('employee.adjustTitle')}</h2>
                        <AdjustForm employeeId={leaveEmployee.id} summary={leaveSummary} />
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className={sectionTitleCls}>{lt('ledger.title')}</h2>
                    <p className={mutedCls}>{lt('ledger.description')}</p>
                    <LedgerTable ledger={ledger} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
