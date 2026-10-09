import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, router } from '@inertiajs/react';
import PreciseLocationCapture from '@/Components/fieldWork/PreciseLocationCapture';
import type { JSX } from 'react';

type Row = { id: string; reference_number: string; type: string | null; destination: string | null; purpose: string; starts_at: string; expected_return_at: string; status: string; overdue: boolean };
type Props = { requests: { data: Row[]; meta: { total: number } } | null };

export default function FieldWorkIndex({ requests }: Props): JSX.Element {
    return <PortalPage title="Field Work">
        <div className="space-y-4">
            <div className="flex justify-end"><Link href={route('employee.field-work.create')} className="rounded-md bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white">New Field Work</Link></div>
            {!requests ? <p className="rounded-panel border border-gray-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900">Your account is not linked to an employee record.</p> : requests.data.length === 0 ? <p className="rounded-panel border border-gray-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900">You have no Field Work requests.</p> : <div className="overflow-x-auto rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900"><table className="min-w-full text-sm"><thead><tr className="border-b text-left text-gray-500 dark:border-slate-800"><th className="p-3">Reference</th><th className="p-3">Purpose / destination</th><th className="p-3">Schedule</th><th className="p-3">Status</th><th className="p-3" /></tr></thead><tbody>{requests.data.map((row) => <tr key={row.id} className="border-b last:border-0 dark:border-slate-800"><td className="p-3 font-medium">{row.reference_number}<br /><span className="text-xs text-gray-500">{row.type}</span></td><td className="p-3">{row.purpose}<br /><span className="text-xs text-gray-500">{row.destination}</span></td><td className="p-3"><LocalizedDateDisplay value={row.starts_at} withTime /><br /><LocalizedDateDisplay value={row.expected_return_at} withTime /></td><td className="p-3"><span className={row.overdue ? 'font-semibold text-amber-700' : ''}>{row.overdue ? 'Overdue / not closed' : row.status.replaceAll('_', ' ')}</span></td><td className="p-3">{row.status === 'draft' || row.status === 'returned_for_correction' ? <button onClick={() => router.post(route('employee.field-work.submit', row.id))} className="text-[color:var(--color-primary)] hover:underline">Submit</button> : row.status === 'approved' ? <div className="space-y-1"><PreciseLocationCapture endpoint={route('employee.field-work.check-in', row.id)} label="Capture Precise Location & Check In" /><PreciseLocationCapture endpoint={route('employee.field-work.check-out', row.id)} label="Capture Precise Location & Check Out" /><button onClick={() => { const actual_return_at = window.prompt('Actual return (YYYY-MM-DD HH:MM)'); if (actual_return_at) router.post(route('employee.field-work.complete', row.id), { actual_return_at }); }} className="text-[color:var(--color-primary)] hover:underline">Complete</button></div> : null}</td></tr>)}</tbody></table></div>}
        </div>
    </PortalPage>;
}
