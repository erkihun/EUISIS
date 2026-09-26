import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Head, router, useForm } from '@inertiajs/react';
import { Button, FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, Money, SelectOptions, useNames, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type Line = { employee_organization: NamePair; cafeteria: NamePair; transaction_count: number; subsidy_amount: string; employee_amount: string; provider_amount: string };
type Preview = { lines: Line[]; totals: { transaction_count: number; total_subsidy_amount: string; total_employee_amount: string; total_provider_amount: string; currency_code: string } };

export default function SettlementCreate({ providers, filters, preview }: {
    providers: Array<{ id: string; provider_code: string; name_en: string; name_am: string | null }>;
    filters: { provider_id?: string; period_start?: string; period_end?: string };
    preview: Preview | null;
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const form = useForm({
        provider_id: filters.provider_id ?? '',
        period_start: filters.period_start ?? '',
        period_end: filters.period_end ?? '',
        notes: '',
    });
    const ready = Boolean(form.data.provider_id && form.data.period_start && form.data.period_end);

    function doPreview() {
        router.get(route('cafeteria.settlements.create'), { provider_id: form.data.provider_id, period_start: form.data.period_start, period_end: form.data.period_end }, { preserveState: true });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.settlements.store'));
    }

    const currency = preview?.totals.currency_code ?? 'ETB';

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('createSettlement')} description={tt('settlementsDescription')} backHref={route('cafeteria.settlements.index')} />}>
            <Head title={tt('createSettlement')} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('settlement')}>
                    <FormField label={tt('provider')} required error={form.errors.provider_id}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={form.data.provider_id} required onChange={(e) => form.setData('provider_id', e.target.value)}>
                                <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code }))} placeholder={tt('selectPlaceholder')} />
                            </select>
                        )}
                    </FormField>
                    <div />
                    <FormField label={tt('periodStart')} required error={form.errors.period_start}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={form.data.period_start} onChange={(v) => form.setData('period_start', v)} />}
                    </FormField>
                    <FormField label={tt('periodEnd')} required error={form.errors.period_end}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={form.data.period_end} onChange={(v) => form.setData('period_end', v)} />}
                    </FormField>
                    <FormField label={tt('notes')} error={form.errors.notes} className="md:col-span-2">
                        {({ id }) => <textarea id={id} rows={2} className={inputCls} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />}
                    </FormField>
                </FormSection>

                <div className="flex flex-wrap justify-end gap-3">
                    <Button type="button" variant="outline" disabled={!ready} onClick={doPreview}>{tt('previewSettlement')}</Button>
                    <Button type="submit" variant="primary" disabled={!ready || form.processing || !preview || preview.lines.length === 0}>{tt('createSettlement')}</Button>
                </div>

                {preview && (
                    <div className="overflow-x-auto rounded-[var(--radius-card)] border border-[color:var(--app-border)]">
                        <table className="w-full text-sm">
                            <thead className="bg-[color:var(--app-surface-muted)] text-xs">
                                <tr>
                                    <th className="px-3 py-2 text-start">{tt('employeeOrganization')}</th>
                                    <th className="px-3 py-2 text-start">{tt('serviceLocation')}</th>
                                    <th className="px-3 py-2 text-end">{tt('transactions')}</th>
                                    <th className="px-3 py-2 text-end">{tt('subsidyAmount')}</th>
                                    <th className="px-3 py-2 text-end">{tt('employeeAmount')}</th>
                                    <th className="px-3 py-2 text-end">{tt('providerAmount')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {preview.lines.length === 0 && <tr><td colSpan={6} className="px-3 py-4 text-center text-[color:var(--app-muted-foreground)]">{tt('noResults')}</td></tr>}
                                {preview.lines.map((line, index) => (
                                    <tr key={index} className="border-t border-[color:var(--app-border)]">
                                        <td className="px-3 py-2">{label(line.employee_organization, tt('unattributed'))}</td>
                                        <td className="px-3 py-2">{label(line.cafeteria)}</td>
                                        <td className="px-3 py-2 text-end">{line.transaction_count}</td>
                                        <td className="px-3 py-2 text-end"><Money value={line.subsidy_amount} currency={currency} /></td>
                                        <td className="px-3 py-2 text-end"><Money value={line.employee_amount} currency={currency} /></td>
                                        <td className="px-3 py-2 text-end font-medium"><Money value={line.provider_amount} currency={currency} /></td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot className="border-t border-[color:var(--app-border)] font-semibold">
                                <tr>
                                    <td className="px-3 py-2" colSpan={2}>{tt('total')}</td>
                                    <td className="px-3 py-2 text-end">{preview.totals.transaction_count}</td>
                                    <td className="px-3 py-2 text-end"><Money value={preview.totals.total_subsidy_amount} currency={currency} /></td>
                                    <td className="px-3 py-2 text-end"><Money value={preview.totals.total_employee_amount} currency={currency} /></td>
                                    <td className="px-3 py-2 text-end"><Money value={preview.totals.total_provider_amount} currency={currency} /></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </form>
        </AuthenticatedLayout>
    );
}
