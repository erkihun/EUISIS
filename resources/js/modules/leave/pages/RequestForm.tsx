import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors } from '@/modules/leave/components/Common';
import { LeaveRequestForm } from '@/modules/leave/components/LeaveRequestForm';
import { secondaryBtn, useLeave } from '@/modules/leave/lib/helpers';
import type { EmployeeRef, LeaveSummary } from '@/modules/leave/types';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    formMode: 'own';
    leaveEmployee: EmployeeRef | null;
    leaveSummary: LeaveSummary | null;
    today: string;
}

/** Ask for leave (My Portal): the page around the one leave request form. Only the employee asks, for themself. */
export default function RequestForm({ leaveEmployee, leaveSummary, today }: Props): JSX.Element {
    const { lt } = useLeave();
    const back = route('employee.leave.index');

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('form.title')} description={lt('form.description')} backHref={back} />}>
            <Head title={lt('form.title')} />
            <ActionErrors except={['leave_type_id', 'starts_on', 'ends_on', 'is_half_day', 'expected_delivery_on', 'reason', 'contact_during_leave']} />
            <div className="mx-auto max-w-3xl space-y-5 pb-6">
                {leaveSummary && (
                    <LeaveRequestForm employee={leaveEmployee} summary={leaveSummary} today={today}
                        cancel={<Link href={back} className={secondaryBtn}>{lt('common.cancel')}</Link>} />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
