import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors } from '@/modules/leave/components/Common';
import { RequestDetail } from '@/modules/leave/components/RequestDetail';
import { useLeave } from '@/modules/leave/lib/helpers';
import type { LeaveDetail } from '@/modules/leave/types';
import { Head } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    leaveRequest: LeaveDetail;
    viewContext: 'own' | 'staff';
    leaveBalance?: { available: number; pending: number; free: number } | null;
    today: string;
}

/** One leave request on a page of its own (a notification link, or "open the full page" from a list). */
export default function RequestShow({ leaveRequest, viewContext, leaveBalance, today }: Props): JSX.Element {
    const { lt, name } = useLeave();
    const title = `${lt('show.title')} ${leaveRequest.reference_number}`;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={name(leaveRequest.leave_type)} backHref={viewContext === 'own' ? route('employee.leave.index') : route('leave.requests.index')} />}>
            <Head title={title} />
            <ActionErrors />
            <div className="mx-auto max-w-4xl pb-6">
                <RequestDetail leave={leaveRequest} staff={viewContext === 'staff'} balance={leaveBalance} today={today} />
            </div>
        </AuthenticatedLayout>
    );
}
