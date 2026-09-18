import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type SpecialDay = {
    id: string; special_date: string; name_en: string; name_am: string | null;
    day_type: string; is_open: boolean; is_subsidy_day: boolean;
    open_time: string | null; close_time: string | null;
    reason_en: string | null; reason_am: string | null;
};
type DayTypeOption = { value: string; label: string };

export default function SpecialDayEdit({ day, day_types }: { day: SpecialDay; day_types: DayTypeOption[] }) {
    const { t } = useLocale();
    const { data, setData, patch, processing, errors } = useForm({
        special_date: day.special_date,
        name_en: day.name_en,
        name_am: day.name_am ?? '',
        day_type: day.day_type,
        is_open: day.is_open,
        is_subsidy_day: day.is_subsidy_day,
        open_time: day.open_time ?? '',
        close_time: day.close_time ?? '',
        reason_en: day.reason_en ?? '',
        reason_am: day.reason_am ?? '',
    });

    const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
    const errorCls = 'mt-1 text-xs text-red-500';

    function submit(e: React.FormEvent) {
        e.preventDefault();
        patch(route('cafeteria.special-days.update', day.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.editSpecialDay')} />}>
            <Head title={t('cafeteria.editSpecialDay')} />
            <div className="mx-auto max-w-2xl">
                <div className="rounded-card border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.specialDate')} *</label>
                                <LocalizedDatePicker className={inputCls} value={data.special_date} onChange={iso => setData('special_date', iso)} />
                                {errors.special_date && <p className={errorCls}>{errors.special_date}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.specialDayType')} *</label>
                                <select className={inputCls} value={data.day_type} onChange={e => setData('day_type', e.target.value)}>
                                    {day_types.map(dt => <option key={dt.value} value={dt.value}>{dt.label}</option>)}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.nameEn')} *</label>
                                <input className={inputCls} value={data.name_en} onChange={e => setData('name_en', e.target.value)} />
                                {errors.name_en && <p className={errorCls}>{errors.name_en}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.nameAm')}</label>
                                <input className={inputCls} value={data.name_am} onChange={e => setData('name_am', e.target.value)} />
                            </div>
                        </div>

                        <div className="flex gap-6">
                            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                                <input type="checkbox" className="h-4 w-4 rounded" checked={data.is_open} onChange={e => { setData('is_open', e.target.checked); if (!e.target.checked) setData('is_subsidy_day', false); }} />
                                {t('cafeteria.isOpen')}
                            </label>
                            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                                <input type="checkbox" className="h-4 w-4 rounded" disabled={!data.is_open} checked={data.is_subsidy_day} onChange={e => setData('is_subsidy_day', e.target.checked)} />
                                {t('cafeteria.isSubsidyDay')}
                            </label>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.openTime')}</label>
                                <input type="time" className={inputCls} value={data.open_time} onChange={e => setData('open_time', e.target.value)} disabled={!data.is_open} />
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.closeTime')}</label>
                                <input type="time" className={inputCls} value={data.close_time} onChange={e => setData('close_time', e.target.value)} disabled={!data.is_open} />
                            </div>
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
                            <a href={route('cafeteria.special-days.index')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300">{t('common.cancel')}</a>
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
