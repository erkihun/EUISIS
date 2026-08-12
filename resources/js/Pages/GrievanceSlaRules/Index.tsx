import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Rule = {
    id: string; origin_level: string; escalation_from_type: string; escalation_to_type: string;
    working_days_limit: number; status: string;
    organization: { name_en: string; name_am: string | null } | null;
};
type Organization = { id: string; name_en: string; name_am: string | null };

type Props = { rules: Rule[]; organizations: Organization[]; originLevels: string[] };

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';

export default function SlaRulesIndex({ rules, organizations, originLevels }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const { data, setData, post, processing, reset } = useForm({
        organization_id: '', origin_level: '', escalation_from_type: 'committee',
        escalation_to_type: 'administrative_tribunal', working_days_limit: 3,
    });

    function originLevelLabel(level: string): string {
        return t(`grievances.originLevel${level.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never);
    }

    function deleteRule(id: string) {
        if (!confirm(t('common.cannotUndo'))) return;
        router.delete(route('grievance-sla-rules.destroy', id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.slaRules')} />}>
            <Head title={t('grievances.slaRules')} />

            <div className="space-y-6">
                {/* Add new rule */}
                <div className="rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">+ {t('grievances.slaRule')}</h3>
                    <form onSubmit={e => { e.preventDefault(); post(route('grievance-sla-rules.store'), { onSuccess: () => reset() }); }} className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        <div>
                            <label className={labelCls}>{t('grievances.organization')}</label>
                            <select className={inputCls} value={data.organization_id} onChange={e => setData('organization_id', e.target.value)}>
                                <option value="">{t('common.all')}</option>
                                {organizations.map(o => <option key={o.id} value={o.id}>{(am ? o.name_am : null) ?? o.name_en}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={labelCls}>{t('grievances.originLevel')} *</label>
                            <select className={inputCls} value={data.origin_level} onChange={e => setData('origin_level', e.target.value)}>
                                <option value="">— {t('common.select')} —</option>
                                {originLevels.map(l => <option key={l} value={l}>{originLevelLabel(l)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className={labelCls}>{t('grievances.escalationFromType')} *</label>
                            <input type="text" className={inputCls} value={data.escalation_from_type} onChange={e => setData('escalation_from_type', e.target.value)} />
                        </div>
                        <div>
                            <label className={labelCls}>{t('grievances.escalationToType')} *</label>
                            <input type="text" className={inputCls} value={data.escalation_to_type} onChange={e => setData('escalation_to_type', e.target.value)} />
                        </div>
                        <div>
                            <label className={labelCls}>{t('grievances.workingDaysLimit')} *</label>
                            <input type="number" min={1} max={30} className={inputCls} value={data.working_days_limit} onChange={e => setData('working_days_limit', Number(e.target.value))} />
                        </div>
                        <div className="flex items-end">
                            <button type="submit" disabled={!data.origin_level || processing} className="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                {t('common.create')}
                            </button>
                        </div>
                    </form>
                </div>

                {/* Rules table */}
                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('grievances.organization'),
                                    t('grievances.originLevel'),
                                    t('grievances.escalationFromType'),
                                    t('grievances.escalationToType'),
                                    t('grievances.workingDaysLimit'),
                                    t('common.status'),
                                    '',
                                ].map(h => (
                                    <th key={h} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {rules.map(r => (
                                <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                        {(am ? r.organization?.name_am : null) ?? r.organization?.name_en ?? t('common.all')}
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{originLevelLabel(r.origin_level)}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{r.escalation_from_type}</td>
                                    <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{r.escalation_to_type}</td>
                                    <td className="px-4 py-3 font-semibold text-gray-700 dark:text-slate-200">{r.working_days_limit}</td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${r.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                                            {r.status === 'active' ? t('common.active') : t('common.inactive')}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <button
                                            type="button"
                                            onClick={() => deleteRule(r.id)}
                                            className="text-xs text-red-600 hover:underline dark:text-red-400"
                                        >
                                            {t('common.delete')}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rules.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">{t('common.noResults')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
