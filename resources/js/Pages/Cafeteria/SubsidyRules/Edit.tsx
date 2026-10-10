import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
type Rule = {
    id: string; code: string; name_en: string; name_am: string | null;
    subsidy_amount: number; currency: string;
    effective_from: string; effective_to: string | null;
    applies_to: string; organization_id: string | null;
    is_active: boolean; exclude_weekends: boolean; notes: string | null;
};

export default function SubsidyRulesEdit({ rule }: { rule: Rule }) {
    const { t } = useLocale();
    const form = useForm({
        name_en: rule.name_en, name_am: rule.name_am ?? '',
        subsidy_amount: String(rule.subsidy_amount),
        currency: rule.currency,
        effective_from: rule.effective_from,
        effective_to: rule.effective_to ?? '',
        applies_to: rule.applies_to,
        organization_id: rule.organization_id ?? '',
        is_active: rule.is_active,
        exclude_weekends: rule.exclude_weekends,
        notes: rule.notes ?? '',
    });

    const inputCls = cx(inputClassName, 'w-full');

    function submit(e: FormEvent) { e.preventDefault(); form.patch(route('cafeteria.subsidy-rules.update', rule.id)); }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.editSubsidyRule')} />}>
            <Head title={t('cafeteria.editSubsidyRule')} />
            <form onSubmit={submit} className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="border-b border-[color:var(--app-border)] px-6 py-4">
                    <p className="font-mono text-sm text-gray-500">{rule.code}</p>
                </div>
                <div className="grid gap-5 p-6 md:grid-cols-2">
                    <Field label={t('cafeteria.nameEn')} error={form.errors.name_en}><input className={inputCls} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} required /></Field>
                    <Field label={t('cafeteria.nameAm')} error={form.errors.name_am}><input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
                    <Field label={`${t('cafeteria.subsidyAmount')} (${rule.currency})`} error={form.errors.subsidy_amount}><input type="number" step="0.01" min="0" className={inputCls} value={form.data.subsidy_amount} onChange={(e) => form.setData('subsidy_amount', e.target.value)} required /></Field>
                    <Field label={t('cafeteria.effectiveFrom')} error={form.errors.effective_from}><LocalizedDatePicker className={inputCls} value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} required /></Field>
                    <Field label={t('cafeteria.effectiveTo')} error={form.errors.effective_to}><LocalizedDatePicker className={inputCls} value={form.data.effective_to} onChange={(iso) => form.setData('effective_to', iso)} /></Field>
                    <div className="flex items-center gap-4 pt-6">
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.exclude_weekends} onChange={(e) => form.setData('exclude_weekends', e.target.checked)} className="h-4 w-4 rounded" />{t('cafeteria.excludeWeekends')}</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded" />{t('cafeteria.isActive')}</label>
                    </div>
                    <div className="md:col-span-2"><Field label={t('cafeteria.notes')} error={form.errors.notes}><textarea className={`${inputCls} min-h-20 resize-y`} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field></div>
                </div>
                <div className="flex justify-end gap-3 border-t border-[color:var(--app-border)] px-6 py-4">
                    <Link href={route('cafeteria.subsidy-rules.index')} className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">{t('common.cancel')}</Link>
                    <button type="submit" disabled={form.processing} className={buttonClassName({ variant: 'primary' })}>{t('common.save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ children, error, label }: { children: ReactNode; error?: string; label: string }) {
    return <div className="space-y-1.5"><InputLabel value={label} />{children}{error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}</div>;
}
