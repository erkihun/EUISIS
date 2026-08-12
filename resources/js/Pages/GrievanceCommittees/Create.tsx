import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Organization = { id: string; name_en: string; name_am: string | null };
type Unit = { id: string; name_en: string; name_am: string | null; organization_id: string };

type Props = { organizations: Organization[]; units: Unit[]; committeeTypes: string[] };

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

export default function CommitteesCreate({ organizations, units, committeeTypes }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const { data, setData, post, processing, errors } = useForm({
        organization_id: '', organization_unit_id: '', committee_type: '', name_en: '', name_am: '',
    });

    const filteredUnits = units.filter(u => u.organization_id === data.organization_id);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.committees')} />}>
            <Head title={t('grievances.committees')} />

            <div className="mx-auto max-w-2xl">
                <form onSubmit={e => { e.preventDefault(); post(route('grievance-committees.store')); }} className="space-y-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <label className={labelCls}>{t('grievances.organization')} *</label>
                        <select className={inputCls} value={data.organization_id} onChange={e => setData('organization_id', e.target.value)}>
                            <option value="">— {t('common.select')} —</option>
                            {organizations.map(o => <option key={o.id} value={o.id}>{(am ? o.name_am : null) ?? o.name_en}</option>)}
                        </select>
                        {errors.organization_id && <p className={errorCls}>{errors.organization_id}</p>}
                    </div>

                    {filteredUnits.length > 0 && (
                        <div>
                            <label className={labelCls}>{t('grievances.organizationUnit')}</label>
                            <select className={inputCls} value={data.organization_unit_id} onChange={e => setData('organization_unit_id', e.target.value)}>
                                <option value="">— {t('common.none')} —</option>
                                {filteredUnits.map(u => <option key={u.id} value={u.id}>{(am ? u.name_am : null) ?? u.name_en}</option>)}
                            </select>
                        </div>
                    )}

                    <div>
                        <label className={labelCls}>{t('grievances.committeeType')} *</label>
                        <select className={inputCls} value={data.committee_type} onChange={e => setData('committee_type', e.target.value)}>
                            <option value="">— {t('common.select')} —</option>
                            {committeeTypes.map(ct => (
                                <option key={ct} value={ct}>{t(`grievances.committeeType${ct.charAt(0).toUpperCase() + ct.slice(1)}` as never)}</option>
                            ))}
                        </select>
                        {errors.committee_type && <p className={errorCls}>{errors.committee_type}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('common.nameEn')} *</label>
                        <input type="text" className={inputCls} value={data.name_en} onChange={e => setData('name_en', e.target.value)} />
                        {errors.name_en && <p className={errorCls}>{errors.name_en}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('common.nameAm')}</label>
                        <input type="text" className={inputCls} value={data.name_am} onChange={e => setData('name_am', e.target.value)} />
                    </div>

                    <div className="flex justify-end gap-3">
                        <a href={route('grievance-committees.index')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            {t('common.cancel')}
                        </a>
                        <button type="submit" disabled={processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            {t('common.create')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
