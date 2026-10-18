import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { buttonClassName, cx, inputClassName, FormLabel } from '@/Components/ui';
export default function HolidaysCreate() {
    const { t } = useLocale();
    const form = useForm({
        name_en: '', name_am: '',
        holiday_date: '', is_recurring: false,
        recurrence_type: '', is_active: true, description: '',
    });

    const inputCls = cx(inputClassName, 'w-full');

    function submit(e: FormEvent) { e.preventDefault(); form.post(route('cafeteria.holidays.store')); }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('cafeteria.addHoliday')} />}>
            <Head title={t('cafeteria.addHoliday')} />
            <form onSubmit={submit} className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface)">
                <div className="border-b border-(--app-border) px-6 py-4">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('cafeteria.holiday')}</h2>
                </div>
                <div className="grid gap-5 p-6 md:grid-cols-2">
                    <Field label={t('cafeteria.holidayDate')} error={form.errors.holiday_date}><LocalizedDatePicker className={inputCls} value={form.data.holiday_date} onChange={(iso) => form.setData('holiday_date', iso)} required /></Field>
                    <Field label={t('cafeteria.nameEn')} error={form.errors.name_en}><input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required /></Field>
                    <Field label={t('cafeteria.nameAm')} error={form.errors.name_am}><input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
                    <div className="flex items-center gap-4 pt-6">
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_recurring} onChange={(e) => form.setData('is_recurring', e.target.checked)} className="h-4 w-4 rounded-sm" />{t('cafeteria.isRecurring')}</label>
                    </div>
                    {form.data.is_recurring && (
                        <Field label={t('cafeteria.recurrenceType')} error={form.errors.recurrence_type}>
                            <select className={inputCls} value={form.data.recurrence_type} onChange={(e) => form.setData('recurrence_type', e.target.value)}>
                                <option value="">{t('common.select')}</option>
                                <option value="gregorian">{t('cafeteria.gregorian')}</option>
                                <option value="ethiopian">{t('cafeteria.ethiopian')}</option>
                            </select>
                        </Field>
                    )}
                    <div className="md:col-span-2">
                        <Field label={t('cafeteria.description')} error={form.errors.description}><textarea className={`${inputCls} min-h-20 resize-y`} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-(--app-border) px-6 py-4">
                    <Link href={route('cafeteria.holidays.index')} className="rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)">{t('common.cancel')}</Link>
                    <button type="submit" disabled={form.processing} className={buttonClassName({ variant: 'primary' })}>{t('common.save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ children, error, label }: { children: ReactNode; error?: string; label: string }) {
    return <div className="space-y-1.5"><FormLabel>{label}</FormLabel>{children}{error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}</div>;
}
