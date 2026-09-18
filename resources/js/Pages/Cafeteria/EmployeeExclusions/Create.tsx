import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type ExclusionTypeOption = { value: string; label: string };
type EmployeeOption = { id: string; name: string; number: string };

export default function EmployeeExclusionCreate({ exclusion_types, employees }: { exclusion_types: ExclusionTypeOption[]; employees: EmployeeOption[] }) {
    const { t } = useLocale();
    const { data, setData, post, processing, errors } = useForm({
        employee_id: '',
        exclusion_type: exclusion_types[0]?.value ?? 'leave',
        starts_on: '',
        ends_on: '',
        return_to_work_on: '',
        is_open_ended: false,
        reason_en: '',
        reason_am: '',
    });

    const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
    const errorCls = 'mt-1 text-xs text-red-500';

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('cafeteria.employee-exclusions.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.addExclusion')} />}>
            <Head title={t('cafeteria.addExclusion')} />
            <div className="mx-auto max-w-2xl">
                <div className="rounded-card border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label className={labelCls}>{t('common.employee')} *</label>
                            <select className={inputCls} value={data.employee_id} onChange={e => setData('employee_id', e.target.value)}>
                                <option value="">— select employee —</option>
                                {employees.map(e => (
                                    <option key={e.id} value={e.id}>{e.name} ({e.number})</option>
                                ))}
                            </select>
                            {errors.employee_id && <p className={errorCls}>{errors.employee_id}</p>}
                        </div>

                        <div>
                            <label className={labelCls}>{t('cafeteria.exclusionType')} *</label>
                            <select className={inputCls} value={data.exclusion_type} onChange={e => setData('exclusion_type', e.target.value)}>
                                {exclusion_types.map(et => <option key={et.value} value={et.value}>{et.label}</option>)}
                            </select>
                            {errors.exclusion_type && <p className={errorCls}>{errors.exclusion_type}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.startsOn')} *</label>
                                <LocalizedDatePicker className={inputCls} value={data.starts_on} onChange={iso => setData('starts_on', iso)} />
                                {errors.starts_on && <p className={errorCls}>{errors.starts_on}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.endsOn')}</label>
                                <LocalizedDatePicker className={inputCls} value={data.ends_on} onChange={iso => setData('ends_on', iso)} disabled={data.is_open_ended} />
                            </div>
                        </div>

                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                            <input type="checkbox" className="h-4 w-4 rounded" checked={data.is_open_ended} onChange={e => { setData('is_open_ended', e.target.checked); if (e.target.checked) setData('ends_on', ''); }} />
                            {t('cafeteria.isOpenEnded')}
                        </label>

                        <div>
                            <label className={labelCls}>{t('cafeteria.returnToWorkOn')}</label>
                            <LocalizedDatePicker className={inputCls} value={data.return_to_work_on} onChange={iso => setData('return_to_work_on', iso)} />
                        </div>

                        <div>
                            <label className={labelCls}>{t('cafeteria.reasonEn')}</label>
                            <textarea rows={2} className={inputCls} value={data.reason_en} onChange={e => setData('reason_en', e.target.value)} />
                        </div>
                        <div>
                            <label className={labelCls}>{t('cafeteria.reasonAm')}</label>
                            <textarea rows={2} className={inputCls} value={data.reason_am} onChange={e => setData('reason_am', e.target.value)} />
                        </div>

                        <div className="flex justify-end gap-3 pt-2">
                            <a href={route('cafeteria.employee-exclusions.index')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">{t('common.cancel')}</a>
                            <button type="submit" disabled={processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                                {processing ? t('common.saving') : t('common.save')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
