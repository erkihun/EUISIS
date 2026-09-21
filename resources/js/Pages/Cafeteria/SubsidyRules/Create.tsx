import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

export default function SubsidyRulesCreate({ defaults }: { defaults?: { subsidy_amount: number; currency: string } }) {
    const { t } = useLocale();
    const form = useForm({
        code: '', name_en: '', name_am: '',
        subsidy_amount: String(defaults?.subsidy_amount ?? ''), currency: defaults?.currency ?? 'ETB',
        effective_from: '', effective_to: '',
        applies_to: 'all_employees', organization_id: '',
        is_active: true, exclude_weekends: true, notes: '',
    });

    const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(e: FormEvent) { e.preventDefault(); form.post(route('cafeteria.subsidy-rules.store')); }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.addSubsidyRule')} />}>
            <Head title={t('cafeteria.addSubsidyRule')} />
            <form onSubmit={submit} className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                    <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.subsidyRule')}</h2>
                </div>
                <div className="grid gap-5 p-6 md:grid-cols-2">
                    <Field label={t('common.code')} error={form.errors.code}>
                        <input className={inputCls} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} required />
                    </Field>
                    <Field label={`${t('cafeteria.subsidyAmount')} (${form.data.currency})`} error={form.errors.subsidy_amount}>
                        <input type="number" step="0.01" min="0" className={inputCls} value={form.data.subsidy_amount} onChange={(e) => form.setData('subsidy_amount', e.target.value)} required />
                    </Field>
                    <Field label={t('cafeteria.nameEn')} error={form.errors.name_en}>
                        <input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required />
                    </Field>
                    <Field label={t('cafeteria.nameAm')} error={form.errors.name_am}>
                        <input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />
                    </Field>
                    <Field label={t('cafeteria.effectiveFrom')} error={form.errors.effective_from}>
                        <LocalizedDatePicker className={inputCls} value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} required />
                    </Field>
                    <Field label={t('cafeteria.effectiveTo')} error={form.errors.effective_to}>
                        <LocalizedDatePicker className={inputCls} value={form.data.effective_to} onChange={(iso) => form.setData('effective_to', iso)} />
                    </Field>
                    <Field label={t('cafeteria.appliesTo')} error={form.errors.applies_to}>
                        <select className={inputCls} value={form.data.applies_to} onChange={(e) => form.setData('applies_to', e.target.value)}>
                            <option value="all_employees">{t('cafeteria.allEmployees')}</option>
                            <option value="organization">{t('cafeteria.byOrganization')}</option>
                        </select>
                    </Field>
                    <div className="flex items-center gap-4 pt-6">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.exclude_weekends} onChange={(e) => form.setData('exclude_weekends', e.target.checked)} className="h-4 w-4 rounded" />
                            {t('cafeteria.excludeWeekends')}
                        </label>
                    </div>
                    <div className="md:col-span-2">
                        <Field label={t('cafeteria.notes')} error={form.errors.notes}>
                            <textarea className={`${inputCls} min-h-20 resize-y`} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-slate-800">
                    <Link href={route('cafeteria.subsidy-rules.index')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">{t('common.cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">{t('common.save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ children, error, label }: { children: ReactNode; error?: string; label: string }) {
    return <div className="space-y-1.5"><InputLabel value={label} />{children}{error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}</div>;
}
