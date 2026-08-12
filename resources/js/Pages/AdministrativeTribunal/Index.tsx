import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type TribunalCase = {
    id: string; case_number: string; status: string; hearing_date: string | null; decision_date: string | null;
    grievance: {
        reference_number: string; subject: string;
        organization: { name_en: string; name_am: string | null } | null;
        category: { name_en: string; name_am: string | null } | null;
    } | null;
    assigned_to_user: { name: string } | null;
};

type Props = {
    cases: { data: TribunalCase[]; links: unknown[] };
    filters: { status?: string };
    statuses: string[];
};

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function TribunalIndex({ cases, filters, statuses }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.tribunalCases')} />}>
            <Head title={t('grievances.tribunalCases')} />

            <div className="space-y-4">
                <div className="flex items-center gap-2">
                    <select className={inputCls} value={filters.status ?? ''} onChange={e => router.get(route('tribunal-cases.index'), e.target.value ? { status: e.target.value } : {}, { preserveState: true })}>
                        <option value="">{t('grievances.filterByStatus')} — {t('common.all')}</option>
                        {statuses.map(s => (
                            <option key={s} value={s}>{t(`grievances.tribunalStatus${s.charAt(0).toUpperCase() + s.slice(1)}` as never)}</option>
                        ))}
                    </select>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('grievances.caseNumber'), t('grievances.grievance'), t('grievances.organization'), t('grievances.hearingDate'), t('grievances.decisionDate'), t('grievances.status')].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {cases.data.map(c => (
                                <tr key={c.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3">
                                        <Link href={route('tribunal-cases.show', c.id)} className="font-mono text-blue-600 hover:underline dark:text-blue-400">{c.case_number}</Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200 max-w-xs truncate">
                                        {c.grievance?.subject ?? '-'}
                                        <div className="text-xs text-gray-400">{c.grievance?.reference_number}</div>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{(am ? c.grievance?.organization?.name_am : null) ?? c.grievance?.organization?.name_en ?? '-'}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {c.hearing_date ? <LocalizedDateDisplay value={c.hearing_date} /> : '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {c.decision_date ? <LocalizedDateDisplay value={c.decision_date} /> : '-'}
                                    </td>
                                    <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {cases.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noResults')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
