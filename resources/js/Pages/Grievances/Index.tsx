import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Grievance = {
    id: string;
    reference_number: string;
    subject: string;
    status: string;
    origin_level: string;
    submitted_at: string | null;
    organization: { name_en: string; name_am: string | null } | null;
    category: { name_en: string; name_am: string | null } | null;
    employee: { display_name: string } | null;
};

type Props = {
    grievances: { data: Grievance[]; links: unknown[] };
    filters: { status?: string; origin_level?: string };
    statuses: string[];
    originLevels: string[];
    can: { create: boolean };
};

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function GrievancesIndex({ grievances, filters, statuses, originLevels, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    function applyFilter(key: string, val: string) {
        router.get(route('grievances.index'), { ...filters, [key]: val || undefined }, { preserveState: true });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('grievances.grievances')}
                    actions={
                        can.create ? (
                            <Link href={route('grievances.create')} className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {t('grievances.submitGrievance')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('grievances.grievances')} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <select className={inputCls} value={filters.status ?? ''} onChange={e => applyFilter('status', e.target.value)}>
                        <option value="">{t('grievances.filterByStatus')} — {t('common.all')}</option>
                        {statuses.map(s => (
                            <option key={s} value={s}>{t(`grievances.status${s.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never)}</option>
                        ))}
                    </select>
                    <select className={inputCls} value={filters.origin_level ?? ''} onChange={e => applyFilter('origin_level', e.target.value)}>
                        <option value="">{t('grievances.filterByOrigin')} — {t('common.all')}</option>
                        {originLevels.map(l => (
                            <option key={l} value={l}>{t(`grievances.originLevel${l.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never)}</option>
                        ))}
                    </select>
                    {(filters.status || filters.origin_level) && (
                        <button type="button" onClick={() => router.get(route('grievances.index'), {}, { preserveState: true })} className="text-xs text-blue-600 hover:underline dark:text-blue-400">
                            {t('common.clear')}
                        </button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('grievances.referenceNumber'),
                                    t('grievances.subject'),
                                    t('grievances.category'),
                                    t('grievances.organization'),
                                    t('grievances.originLevel'),
                                    t('grievances.submittedAt'),
                                    t('grievances.status'),
                                ].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {grievances.data.map(g => (
                                <tr key={g.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3">
                                        <Link href={route('grievances.show', g.id)} className="font-mono text-blue-600 hover:underline dark:text-blue-400">
                                            {g.reference_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200 max-w-xs truncate">{g.subject}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(am ? g.category?.name_am : null) ?? g.category?.name_en ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(am ? g.organization?.name_am : null) ?? g.organization?.name_en ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {t(`grievances.originLevel${g.origin_level.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never)}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={g.submitted_at} fallback="-" /></td>
                                    <td className="px-4 py-3"><StatusBadge status={g.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {grievances.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('grievances.noGrievances')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
