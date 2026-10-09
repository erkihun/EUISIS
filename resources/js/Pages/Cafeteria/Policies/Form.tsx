import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Head, Link, useForm } from '@inertiajs/react';
import { Alert, FormField } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, SelectOptions, useNames, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type AssignmentOption = {
    id: string; organization: NamePair; provider: NamePair; provider_id: string; network: NamePair;
    cafeteria_service_network_id: string | null; cafeteria: NamePair; cafeteria_id: string | null; status: string; effective_from: string | null;
};
type CafeteriaOption = { id: string; code: string; name_en: string; name_am: string | null; provider_id: string | null; cafeteria_service_network_id: string | null };

const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
type Weekday = typeof WEEKDAYS[number];

type PolicyData = {
    cafeteria_service_assignment_id: string; cafeteria_id: string;
    daily_subsidy_amount: string; employee_contribution_amount: string; provider_price: string; currency_code: string;
    max_daily_uses: number; allow_advance_usage: boolean; advance_max_days: string; extra_scan_policy: string;
    exclude_public_holidays: boolean; block_employee_leave: boolean;
    effective_from: string; effective_to: string; notes: string;
} & Record<`${Weekday}_enabled`, boolean>;

export default function PolicyForm({ policy, assignments, cafeterias, defaults }: {
    policy: (Partial<PolicyData> & { id: string; version_no: number; advance_max_days?: number | string | null }) | null;
    assignments: AssignmentOption[];
    cafeterias: CafeteriaOption[];
    defaults: Partial<PolicyData> & { advance_max_days?: number | string | null };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const source = { ...defaults, ...(policy ?? {}) };
    const form = useForm<PolicyData>({
        cafeteria_service_assignment_id: source.cafeteria_service_assignment_id ?? '',
        cafeteria_id: source.cafeteria_id ?? '',
        daily_subsidy_amount: source.daily_subsidy_amount ?? '',
        employee_contribution_amount: source.employee_contribution_amount ?? '0.00',
        provider_price: source.provider_price ?? '',
        currency_code: source.currency_code ?? 'ETB',
        max_daily_uses: Number(source.max_daily_uses ?? 1),
        allow_advance_usage: Boolean(source.allow_advance_usage),
        advance_max_days: source.advance_max_days === null || source.advance_max_days === undefined ? '' : String(source.advance_max_days),
        extra_scan_policy: source.extra_scan_policy ?? 'block',
        exclude_public_holidays: source.exclude_public_holidays ?? true,
        block_employee_leave: source.block_employee_leave ?? true,
        effective_from: source.effective_from ?? '',
        effective_to: source.effective_to ?? '',
        notes: source.notes ?? '',
        ...Object.fromEntries(WEEKDAYS.map((d) => [`${d}_enabled`, Boolean(source[`${d}_enabled`] ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].includes(d))])) as Record<`${Weekday}_enabled`, boolean>,
    });
    const d = form.data;
    const assignment = assignments.find((a) => a.id === d.cafeteria_service_assignment_id);
    // A specific cafeteria may narrow a network or provider-wide assignment.
    const scopeCafeterias = cafeterias.filter((c) => assignment && c.provider_id === assignment.provider_id
        && (!assignment.cafeteria_service_network_id || c.cafeteria_service_network_id === assignment.cafeteria_service_network_id));
    const expectedPrice = (Number(d.daily_subsidy_amount || 0) + Number(d.employee_contribution_amount || 0)).toFixed(2);
    const priceMismatch = d.provider_price !== '' && Number(d.provider_price).toFixed(2) !== expectedPrice;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => {
            const payload: Record<string, unknown> = { ...data, cafeteria_id: data.cafeteria_id || null, effective_to: data.effective_to || null, advance_max_days: data.advance_max_days === '' ? null : Number(data.advance_max_days) };
            if (policy) { delete payload.cafeteria_service_assignment_id; delete payload.cafeteria_id; }
            return payload;
        });
        if (policy) form.patch(route('cafeteria.policies.update', policy.id)); else form.post(route('cafeteria.policies.store'));
    }

    const title = policy ? `${tt('editPolicy')} · v${policy.version_no}` : tt('createPolicy');
    const check = (key: keyof PolicyData, text: string) => (
        <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={Boolean(d[key])} onChange={(e) => form.setData(key, e.target.checked as never)} />
            {text}
        </label>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={tt('policiesDescription')} backHref={policy ? route('cafeteria.policies.show', policy.id) : route('cafeteria.policies.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="space-y-6">
                <FormSection title={tt('sectionScope')}>
                    <FormField label={tt('serviceAssignment')} required error={form.errors.cafeteria_service_assignment_id} className="md:col-span-2">
                        {({ id }) => (
                            <select id={id} className={inputCls} value={d.cafeteria_service_assignment_id} disabled={Boolean(policy)} required
                                onChange={(e) => form.setData((prev) => ({ ...prev, cafeteria_service_assignment_id: e.target.value, cafeteria_id: '' }))}>
                                <option value="">{tt('selectPlaceholder')}</option>
                                {assignments.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {label(a.organization)} → {label(a.provider)} · {label(a.cafeteria ?? a.network, tt('scopeProvider'))}{a.status !== 'active' ? ` (${t(`cafeteriaPolicy.statuses.${a.status}`)})` : ''}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('specificCafeteria')} description={tt('specificCafeteriaHelp')} error={form.errors.cafeteria_id}>
                        {({ id, describedBy }) => (
                            <select id={id} aria-describedby={describedBy} className={inputCls} value={d.cafeteria_id} disabled={Boolean(policy) || Boolean(assignment?.cafeteria_id)} onChange={(e) => form.setData('cafeteria_id', e.target.value)}>
                                <SelectOptions items={scopeCafeterias} placeholder={tt('none')} />
                            </select>
                        )}
                    </FormField>
                </FormSection>

                <FormSection title={tt('sectionFinancial')} description={tt('providerPriceHelp')}>
                    <FormField label={tt('dailySubsidy')} required error={form.errors.daily_subsidy_amount}>
                        {({ id }) => <input id={id} type="number" min="0" step="0.01" className={inputCls} value={d.daily_subsidy_amount} required
                            onChange={(e) => form.setData((prev) => ({ ...prev, daily_subsidy_amount: e.target.value, provider_price: (Number(e.target.value || 0) + Number(prev.employee_contribution_amount || 0)).toFixed(2) }))} />}
                    </FormField>
                    <FormField label={tt('employeeContribution')} required error={form.errors.employee_contribution_amount}>
                        {({ id }) => <input id={id} type="number" min="0" step="0.01" className={inputCls} value={d.employee_contribution_amount} required
                            onChange={(e) => form.setData((prev) => ({ ...prev, employee_contribution_amount: e.target.value, provider_price: (Number(prev.daily_subsidy_amount || 0) + Number(e.target.value || 0)).toFixed(2) }))} />}
                    </FormField>
                    <FormField label={tt('providerPrice')} required error={form.errors.provider_price ?? (priceMismatch ? tt('providerPriceHelp') : undefined)}>
                        {({ id }) => <input id={id} type="number" min="0.01" step="0.01" className={inputCls} value={d.provider_price} required onChange={(e) => form.setData('provider_price', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('currency')} required error={form.errors.currency_code}>
                        {({ id }) => <input id={id} maxLength={3} className={inputCls} value={d.currency_code} required onChange={(e) => form.setData('currency_code', e.target.value.toUpperCase())} />}
                    </FormField>
                </FormSection>

                <FormSection title={tt('sectionUsage')}>
                    <FormField label={tt('maxDailyUses')} required error={form.errors.max_daily_uses}>
                        {({ id }) => <input id={id} type="number" min={1} max={5} className={inputCls} value={d.max_daily_uses} onChange={(e) => form.setData('max_daily_uses', Number(e.target.value))} />}
                    </FormField>
                    <FormField label={tt('extraScanPolicy')} required error={form.errors.extra_scan_policy}>
                        {({ id }) => (
                            <select id={id} className={inputCls} value={d.extra_scan_policy} onChange={(e) => form.setData('extra_scan_policy', e.target.value)}>
                                {['block', 'deduct_next_available', 'employee_paid'].map((p) => <option key={p} value={p}>{t(`cafeteriaPolicy.extraScan.${p}`)}</option>)}
                            </select>
                        )}
                    </FormField>
                    <FormField label={tt('allowAdvanceUsage')} description={tt('allowAdvanceUsageHelp')} error={form.errors.allow_advance_usage}>
                        {() => check('allow_advance_usage', tt('allowAdvanceUsage'))}
                    </FormField>
                    <FormField label={tt('advanceMaxDays')} description={tt('advanceMaxDaysHelp')} error={form.errors.advance_max_days}>
                        {({ id, describedBy }) => <input id={id} aria-describedby={describedBy} type="number" min={0} max={6} className={inputCls} value={d.advance_max_days} disabled={!d.allow_advance_usage} onChange={(e) => form.setData('advance_max_days', e.target.value)} />}
                    </FormField>
                </FormSection>

                <FormSection title={tt('sectionWorkingDays')} grid={false}>
                    <fieldset>
                        <legend className="sr-only">{tt('workingDays')}</legend>
                        <div className="flex flex-wrap gap-4">
                            {WEEKDAYS.map((day) => <div key={day}>{check(`${day}_enabled`, t(`cafeteriaPolicy.weekdays.${day}`))}</div>)}
                        </div>
                        {form.errors.monday_enabled && <p className="mt-1 text-xs text-red-600">{form.errors.monday_enabled}</p>}
                    </fieldset>
                </FormSection>

                <FormSection title={tt('sectionEligibility')}>
                    {check('exclude_public_holidays', tt('excludePublicHolidays'))}
                    {check('block_employee_leave', tt('blockEmployeeLeave'))}
                </FormSection>

                <FormSection title={tt('sectionPeriod')}>
                    <FormField label={tt('effectiveFrom')} required error={form.errors.effective_from}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={d.effective_from} onChange={(v) => form.setData('effective_from', v)} />}
                    </FormField>
                    <FormField label={tt('effectiveTo')} error={form.errors.effective_to}>
                        {({ id }) => <LocalizedDatePicker id={id} className={inputCls} value={d.effective_to} onChange={(v) => form.setData('effective_to', v)} />}
                    </FormField>
                    <FormField label={tt('notes')} error={form.errors.notes} className="md:col-span-2">
                        {({ id }) => <textarea id={id} rows={2} className={inputCls} value={d.notes} onChange={(e) => form.setData('notes', e.target.value)} />}
                    </FormField>
                </FormSection>

                <FormSection title={tt('sectionApproval')} grid={false}>
                    <Alert tone="info">{tt('approvalHelp')}</Alert>
                </FormSection>

                <div className="flex justify-end gap-3">
                    <Link href={policy ? route('cafeteria.policies.show', policy.id) : route('cafeteria.policies.index')} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium">{tt('cancel')}</Link>
                    <button type="submit" disabled={form.processing} className="rounded-[var(--radius-control)] bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60">{tt('save')}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
