import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import SearchPicker from '@/Components/fieldWork/SearchPicker';
import { useState } from 'react';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
type ExclusionTypeOption = { value: string; label: string };
type EmployeeOption = { id: string; employee_number: string; full_name: string };

export default function EmployeeExclusionCreate({ exclusion_types }: { exclusion_types: ExclusionTypeOption[] }) {
    const { t } = useLocale();
    const [selected, setSelected] = useState<EmployeeOption | null>(null);
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

    const inputCls = cx(inputClassName, 'w-full');
    const labelCls = 'block text-sm font-medium text-[color:var(--app-foreground)] mb-1';
    const errorCls = 'mt-1 text-xs text-red-500';

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('cafeteria.employee-exclusions.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.addExclusion')} />}>
            <Head title={t('cafeteria.addExclusion')} />
            <div className="mx-auto max-w-2xl">
                <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            {selected ? (
                                <>
                                    <span className={labelCls}>{t('common.employee')} *</span>
                                    <div className="flex items-center justify-between gap-2 rounded-lg border border-[color:var(--app-border-strong)] px-3 py-2 text-sm">
                                        <span>{selected.full_name} ({selected.employee_number})</span>
                                        <button type="button" className="text-xs font-medium text-[color:var(--color-primary)] hover:underline" onClick={() => { setSelected(null); setData('employee_id', ''); }}>
                                            {t('common.change')}
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <SearchPicker<EmployeeOption>
                                    url={route('lookup.employees')}
                                    label={`${t('common.employee')} *`}
                                    placeholder={t('common.searchEmployee')}
                                    render={(e) => `${e.full_name} (${e.employee_number})`}
                                    onPick={(e) => { setSelected(e); setData('employee_id', e.id); }}
                                />
                            )}
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

                        <label className="flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
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
                            <a href={route('cafeteria.employee-exclusions.index')} className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-gray-50">{t('common.cancel')}</a>
                            <button type="submit" disabled={processing} className={buttonClassName({ variant: 'primary' })}>
                                {processing ? t('common.saving') : t('common.save')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
