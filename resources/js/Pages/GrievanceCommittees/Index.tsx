import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Committee = {
    id: string; name_en: string; name_am: string | null; committee_type: string; status: string;
    active_members_count: number;
    organization: { name_en: string; name_am: string | null } | null;
    organization_unit: { name_en: string; name_am: string | null } | null;
};
type Organization = { id: string; name_en: string; name_am: string | null };

type Props = {
    committees: { data: Committee[]; links: unknown[] };
    organizations: Organization[];
    filters: { organization_id?: string };
    can: { create: boolean };
};

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function CommitteesIndex({ committees, organizations, filters, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('grievances.committees')}
                    actions={
                        can.create ? (
                            <Link href={route('grievance-committees.create')} className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                + {t('common.create')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('grievances.committees')} />

            <div className="space-y-4">
                <div className="flex items-center gap-2">
                    <select className={inputCls} value={filters.organization_id ?? ''} onChange={e => router.get(route('grievance-committees.index'), e.target.value ? { organization_id: e.target.value } : {}, { preserveState: true })}>
                        <option value="">{t('grievances.filterByOrganization')} — {t('common.all')}</option>
                        {organizations.map(o => <option key={o.id} value={o.id}>{(am ? o.name_am : null) ?? o.name_en}</option>)}
                    </select>
                </div>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[t('grievances.committee'), t('grievances.committeeType'), t('grievances.organization'), t('grievances.activeMembersCount'), t('grievances.status')].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {committees.data.map(c => (
                                <tr key={c.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3">
                                        <Link href={route('grievance-committees.show', c.id)} className="text-blue-600 hover:underline dark:text-blue-400">
                                            {(am ? c.name_am : null) ?? c.name_en}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {t(`grievances.committeeType${c.committee_type.charAt(0).toUpperCase() + c.committee_type.slice(1)}` as never)}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(am ? c.organization?.name_am : null) ?? c.organization?.name_en ?? '-'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`font-semibold ${c.active_members_count >= 3 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400'}`}>
                                            {c.active_members_count} / 5
                                        </span>
                                    </td>
                                    <td className="px-4 py-3"><StatusBadge status={c.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {committees.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('grievances.noCommittees')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
