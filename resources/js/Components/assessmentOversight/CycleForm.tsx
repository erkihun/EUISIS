import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Field, inputCls, primaryBtn, secondaryBtn } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import { named } from './shell';

export type Option = { id: string; code?: string; name_en: string; name_am: string | null; version_no?: number; status?: string };

export type CycleData = {
    id: string; code: string; name_en: string; name_am: string | null; assessment_type_id: string; status: string; eligibility_status: string;
    result_band_policy_id: string | null; eligible_employee_statuses: string[] | null; population_rule: string; min_service_days: number | null;
    exclusion_reduces_denominator: boolean | null; small_group_threshold: number | null; reminder_days_before: number | null;
    period_start: string; period_end: string; reference_date: string; submission_deadline: string | null; verification_deadline: string | null;
    eligibility_snapshot_at: string | null; eligibility_finalized_at: string | null;
};

/**
 * Cycle settings. Policy switches left empty stay NEEDS_DECISION: the
 * system then applies the documented safe default and says so, never a guess.
 */
export function CycleForm({ cycle, types, policies, employeeStatuses, onCancel }: { cycle?: CycleData; types: Option[]; policies: Option[]; employeeStatuses: string[]; onCancel?: () => void }) {
    const { t, locale } = useLocale();
    const locked = cycle?.eligibility_status === 'finalized';
    const form = useForm({
        code: cycle?.code ?? '', name_en: cycle?.name_en ?? '', name_am: cycle?.name_am ?? '', assessment_type_id: cycle?.assessment_type_id ?? types[0]?.id ?? '',
        period_start: cycle?.period_start ?? '', period_end: cycle?.period_end ?? '', reference_date: cycle?.reference_date ?? '',
        submission_deadline: cycle?.submission_deadline ?? '', verification_deadline: cycle?.verification_deadline ?? '',
        result_band_policy_id: cycle?.result_band_policy_id ?? '', eligible_employee_statuses: cycle?.eligible_employee_statuses ?? [] as string[],
        population_rule: cycle?.population_rule ?? 'all_assigned', min_service_days: cycle?.min_service_days?.toString() ?? '',
        exclusion_reduces_denominator: cycle?.exclusion_reduces_denominator === null || cycle?.exclusion_reduces_denominator === undefined ? '' : cycle.exclusion_reduces_denominator ? '1' : '0',
        small_group_threshold: cycle?.small_group_threshold?.toString() ?? '', reminder_days_before: cycle?.reminder_days_before?.toString() ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            result_band_policy_id: d.result_band_policy_id || null,
            submission_deadline: d.submission_deadline || null, verification_deadline: d.verification_deadline || null,
            min_service_days: d.min_service_days === '' ? null : Number(d.min_service_days),
            small_group_threshold: d.small_group_threshold === '' ? null : Number(d.small_group_threshold),
            reminder_days_before: d.reminder_days_before === '' ? null : Number(d.reminder_days_before),
            exclusion_reduces_denominator: d.exclusion_reduces_denominator === '' ? null : d.exclusion_reduces_denominator === '1',
        }));
        if (cycle) form.put(route('assessment-oversight.cycles.update', cycle.id), { preserveScroll: true });
        else form.post(route('assessment-oversight.cycles.store'));
    }

    const date = (key: 'period_start' | 'period_end' | 'reference_date' | 'submission_deadline' | 'verification_deadline', disabled = false) => (
        <Field label={t(`assessmentOversight.cycleForm.${key}`)} error={form.errors[key]}>
            <LocalizedDatePicker className={inputCls} value={form.data[key]} onChange={(iso) => form.setData(key, iso ?? '')} disabled={disabled} />
        </Field>
    );

    return (
        <form onSubmit={submit} className="grid gap-3 md:grid-cols-3">
            <Field label={t('assessmentOversight.setup.code')} error={form.errors.code}><input className={inputCls} required maxLength={50} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
            <Field label={t('assessmentOversight.setup.nameEn')} error={form.errors.name_en}><input className={inputCls} required value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} /></Field>
            <Field label={t('assessmentOversight.setup.nameAm')}><input className={inputCls} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} /></Field>
            <Field label={t('assessmentOversight.setup.type')} error={form.errors.assessment_type_id}>
                <select className={inputCls} disabled={locked} value={form.data.assessment_type_id} onChange={(e) => form.setData('assessment_type_id', e.target.value)}>
                    {types.map((type) => <option key={type.id} value={type.id}>{named(type, locale)}</option>)}
                </select>
            </Field>
            {date('period_start', locked)}
            {date('period_end', locked)}
            {date('reference_date', locked)}
            {date('submission_deadline')}
            {date('verification_deadline')}
            <Field label={t('assessmentOversight.cycleForm.bandPolicy')} error={form.errors.result_band_policy_id} help={t('assessmentOversight.cycleForm.bandPolicyHelp')}>
                <select className={inputCls} value={form.data.result_band_policy_id} onChange={(e) => form.setData('result_band_policy_id', e.target.value)}>
                    <option value="">{t('assessmentOversight.cycleForm.noPolicy')}</option>
                    {policies.map((p) => <option key={p.id} value={p.id}>{named(p, locale)} · {p.code} v{p.version_no}</option>)}
                </select>
            </Field>
            <Field label={t('assessmentOversight.cycleForm.populationRule')} error={form.errors.population_rule} help={t(`assessmentOversight.cycleForm.populationHelp.${form.data.population_rule}`)}>
                <select className={inputCls} disabled={locked} value={form.data.population_rule} onChange={(e) => form.setData('population_rule', e.target.value)}>
                    <option value="all_assigned">{t('assessmentOversight.cycleForm.population.all_assigned')}</option>
                    <option value="target_rules">{t('assessmentOversight.cycleForm.population.target_rules')}</option>
                </select>
            </Field>
            <Field label={t('assessmentOversight.cycleForm.minServiceDays')} error={form.errors.min_service_days} help={t('assessmentOversight.cycleForm.minServiceHelp')}>
                <input type="number" min={0} className={inputCls} disabled={locked} value={form.data.min_service_days} onChange={(e) => form.setData('min_service_days', e.target.value)} />
            </Field>
            <Field label={t('assessmentOversight.cycleForm.exclusionsReduce')} error={form.errors.exclusion_reduces_denominator} help={t('assessmentOversight.cycleForm.exclusionsReduceHelp')}>
                <select className={inputCls} disabled={locked} value={form.data.exclusion_reduces_denominator} onChange={(e) => form.setData('exclusion_reduces_denominator', e.target.value)}>
                    <option value="">{t('assessmentOversight.cycleForm.undecided')}</option>
                    <option value="0">{t('assessmentOversight.cycleForm.no')}</option>
                    <option value="1">{t('assessmentOversight.cycleForm.yes')}</option>
                </select>
            </Field>
            <Field label={t('assessmentOversight.cycleForm.smallGroup')} error={form.errors.small_group_threshold} help={t('assessmentOversight.cycleForm.smallGroupHelp')}>
                <input type="number" min={1} className={inputCls} value={form.data.small_group_threshold} onChange={(e) => form.setData('small_group_threshold', e.target.value)} />
            </Field>
            <Field label={t('assessmentOversight.cycleForm.reminderDays')} error={form.errors.reminder_days_before} help={t('assessmentOversight.cycleForm.reminderHelp')}>
                <input type="number" min={0} className={inputCls} value={form.data.reminder_days_before} onChange={(e) => form.setData('reminder_days_before', e.target.value)} />
            </Field>
            <fieldset className="md:col-span-3" disabled={locked}>
                <legend className="mb-1 text-sm font-medium text-gray-700 dark:text-slate-300">{t('assessmentOversight.cycleForm.eligibleStatuses')}</legend>
                <div className="flex flex-wrap gap-3">
                    {employeeStatuses.map((s) => (
                        <label key={s} className="flex items-center gap-1.5 text-sm">
                            <input type="checkbox" checked={form.data.eligible_employee_statuses.includes(s)}
                                onChange={(e) => form.setData('eligible_employee_statuses', e.target.checked ? [...form.data.eligible_employee_statuses, s] : form.data.eligible_employee_statuses.filter((x) => x !== s))} />
                            {t(`assessmentOversight.employeeStatuses.${s}`)}
                        </label>
                    ))}
                </div>
                <p className="mt-1 text-xs text-gray-500">{t('assessmentOversight.cycleForm.eligibleStatusesHelp')}</p>
            </fieldset>
            {locked && <p className="text-xs text-amber-700 md:col-span-3">{t('assessmentOversight.cycleForm.locked')}</p>}
            {Object.keys(form.errors).filter((k) => k === 'cycle').map((k) => <p key={k} className="text-sm text-red-600 md:col-span-3">{form.errors[k as keyof typeof form.errors]}</p>)}
            <div className="flex gap-2 md:col-span-3">
                <button className={primaryBtn} disabled={form.processing}>{t('assessmentOversight.actions.save')}</button>
                {onCancel && <button type="button" className={secondaryBtn} onClick={onCancel}>{t('assessmentOversight.actions.cancel')}</button>}
            </div>
        </form>
    );
}
