import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Employee = { id: string; full_name: string; employee_number: string };
type Member = {
    id: string; role: string; status: string; effective_from: string; effective_to: string | null;
    employee: Employee | null;
};
type Committee = {
    id: string; name_en: string; name_am: string | null; committee_type: string; status: string;
    organization: { name_en: string; name_am: string | null } | null;
    members: Member[];
};

type Props = {
    committee: Committee;
    activeMembersCount: number;
    availableEmployees: Employee[];
    can: { update: boolean; addMember: boolean };
};

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';

export default function CommitteeShow({ committee, activeMembersCount, availableEmployees, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const { data, setData, post, processing } = useForm({
        employee_id: '', role: 'member', effective_from: new Date().toISOString().split('T')[0],
    });

    const memberCountColor = activeMembersCount >= 3 && activeMembersCount <= 5 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400';

    return (
        <AuthenticatedLayout header={<PageHeader title={(am ? committee.name_am : null) ?? committee.name_en} />}>
            <Head title={(am ? committee.name_am : null) ?? committee.name_en} />

            <div className="space-y-6">
                <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('grievances.committeeType')}</dt>
                            <dd className="mt-0.5 text-sm">{t(`grievances.committeeType${committee.committee_type.charAt(0).toUpperCase() + committee.committee_type.slice(1)}` as never)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('grievances.organization')}</dt>
                            <dd className="mt-0.5 text-sm">{(am ? committee.organization?.name_am : null) ?? committee.organization?.name_en ?? '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('grievances.activeMembersCount')}</dt>
                            <dd className={`mt-0.5 text-sm font-semibold ${memberCountColor}`}>{activeMembersCount} / 5</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('grievances.status')}</dt>
                            <dd className="mt-0.5"><StatusBadge status={committee.status} /></dd>
                        </div>
                    </dl>
                </div>

                {/* Members list */}
                <div className="rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 p-4 dark:border-slate-800">
                        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">{t('grievances.members')}</h3>
                    </div>
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('common.employee'), t('grievances.memberRole'), t('grievances.effectiveFrom'), t('grievances.effectiveTo'), t('grievances.status'), ''].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {committee.members.map(m => (
                                <tr key={m.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{m.employee?.full_name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${m.role === 'chairperson' ? 'bg-purple-100 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300' : 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-300'}`}>
                                            {t(`grievances.memberRole${m.role.charAt(0).toUpperCase() + m.role.slice(1)}` as never)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={m.effective_from} /></td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={m.effective_to} fallback="-" /></td>
                                    <td className="px-4 py-3"><StatusBadge status={m.status} /></td>
                                    <td className="px-4 py-3">
                                        {can.update && m.status === 'active' && (
                                            <button
                                                type="button"
                                                onClick={() => router.delete(route('grievance-committees.members.remove', { grievanceCommittee: committee.id, member: m.id }))}
                                                className="text-xs text-red-600 hover:underline dark:text-red-400"
                                            >
                                                {t('grievances.removeMember')}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Add member */}
                {can.addMember && activeMembersCount < 5 && (
                    <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">{t('grievances.addMember')}</h3>
                        <form onSubmit={e => { e.preventDefault(); post(route('grievance-committees.members.add', committee.id)); }} className="flex flex-wrap items-end gap-4">
                            <div className="flex-1 min-w-48">
                                <label className={labelCls}>{t('common.employee')}</label>
                                <select className={inputCls} value={data.employee_id} onChange={e => setData('employee_id', e.target.value)}>
                                    <option value="">— {t('common.select')} —</option>
                                    {availableEmployees.map(e => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_number})</option>)}
                                </select>
                            </div>
                            <div className="min-w-36">
                                <label className={labelCls}>{t('grievances.memberRole')}</label>
                                <select className={inputCls} value={data.role} onChange={e => setData('role', e.target.value)}>
                                    {['chairperson', 'secretary', 'member'].map(r => (
                                        <option key={r} value={r}>{t(`grievances.memberRole${r.charAt(0).toUpperCase() + r.slice(1)}` as never)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="min-w-36">
                                <label className={labelCls}>{t('grievances.effectiveFrom')}</label>
                                <input type="date" className={inputCls} value={data.effective_from} onChange={e => setData('effective_from', e.target.value)} />
                            </div>
                            <button type="submit" disabled={!data.employee_id || processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {t('grievances.addMember')}
                            </button>
                        </form>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
