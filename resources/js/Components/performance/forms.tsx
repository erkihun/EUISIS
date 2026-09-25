import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Field, formatScore, inputCls, smallBtn } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

/** Empty strings become null so optional numeric fields validate as absent. */
export const nullify = <T extends Record<string, unknown>>(data: T): Record<string, unknown> => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]));

/** Record a measured actual for a period. Shared by plan targets and agreement items. */
export function ActualForm({ url, period, milestones, onDone }: { url: string; period?: [string | null, string | null]; milestones?: { key: string; label_en: string; label_am?: string | null }[] | null; onDone: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({ period_start: period?.[0] ?? '', period_end: period?.[1] ?? '', actual_value: '', actual_numerator: '', actual_denominator: '', milestone_key: '', comment: '' });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform(nullify);
        form.post(url, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="my-2 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-slate-800/50">
            <Field label={`${t('performance.fields.period')} — ${t('performance.fields.from')}`} error={errors.period_start}><LocalizedDatePicker value={form.data.period_start} onChange={(v) => form.setData('period_start', v)} required /></Field>
            <Field label={`${t('performance.fields.period')} — ${t('performance.fields.to')}`} error={errors.period_end}><LocalizedDatePicker value={form.data.period_end} onChange={(v) => form.setData('period_end', v)} required /></Field>
            {milestones?.length ? (
                <Field label={t('performance.fields.milestone')} error={errors.milestone_key} className="sm:col-span-2">
                    <select className={inputCls} value={form.data.milestone_key} onChange={(e) => form.setData('milestone_key', e.target.value)} required>
                        <option value="">—</option>
                        {milestones.map((m) => <option key={m.key} value={m.key}>{(locale === 'am' && m.label_am) || m.label_en}</option>)}
                    </select>
                </Field>
            ) : (
                <>
                    <Field label={t('performance.fields.actual')} error={errors.actual_value}><input className={inputCls} inputMode="decimal" value={form.data.actual_value} onChange={(e) => form.setData('actual_value', e.target.value)} /></Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label={t('performance.fields.numerator')} error={errors.actual_numerator}><input className={inputCls} inputMode="decimal" value={form.data.actual_numerator} onChange={(e) => form.setData('actual_numerator', e.target.value)} /></Field>
                        <Field label={t('performance.fields.denominator')} error={errors.actual_denominator}><input className={inputCls} inputMode="decimal" value={form.data.actual_denominator} onChange={(e) => form.setData('actual_denominator', e.target.value)} /></Field>
                    </div>
                </>
            )}
            <Field label={t('performance.fields.comment')} error={errors.comment} className="sm:col-span-2"><input className={inputCls} value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} /></Field>
            <div className="flex justify-end gap-2 sm:col-span-2">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.save')}</button>
            </div>
        </form>
    );
}

/** "field: old → new" for each proposed change. */
export function AmendmentDiff({ original, proposed }: { original: Record<string, unknown> | null; proposed: Record<string, unknown> | null }) {
    return (
        <ul className="text-xs">
            {Object.entries(proposed ?? {}).map(([key, value]) => (
                <li key={key}><span className="text-gray-500">{key}:</span> <span className="tabular-nums">{formatScore(original?.[key] as string | null)} → {formatScore(value as string | null)}</span></li>
            ))}
        </ul>
    );
}

/** Request a target change on a published plan or active agreement; approval creates a new version. */
export function AmendmentForm({ url, onDone }: { url: string; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({ target_value: '', weight: '', reason: '', effective_date: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform(nullify);
        form.post(url, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="my-2 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-3 dark:bg-slate-800/50">
            <Field label={t('performance.fields.target')} error={form.errors.target_value}><input className={inputCls} inputMode="decimal" value={form.data.target_value} onChange={(e) => form.setData('target_value', e.target.value)} /></Field>
            <Field label={t('performance.fields.weight')} error={form.errors.weight}><input className={inputCls} inputMode="decimal" value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} /></Field>
            <Field label={t('performance.fields.effectiveDate')} error={form.errors.effective_date}><LocalizedDatePicker value={form.data.effective_date} onChange={(v) => form.setData('effective_date', v)} required /></Field>
            <Field label={t('performance.fields.reason')} error={form.errors.reason} className="sm:col-span-3"><textarea rows={2} className={inputCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required /></Field>
            <div className="flex justify-end gap-2 sm:col-span-3">
                <button type="button" className={smallBtn} onClick={onDone}>{t('performance.actions.cancel')}</button>
                <button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.requestAmendment')}</button>
            </div>
        </form>
    );
}

/** Evidence upload (private file store; server re-checks own/manager access). */
export function EvidenceForm({ url, items, onDone }: { url: string; items: { id: string; label: string }[]; onDone?: () => void }) {
    const { t } = useLocale();
    const form = useForm<{ title: string; description: string; employee_performance_item_id: string; file: File | null }>({ title: '', description: '', employee_performance_item_id: '', file: null });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...nullify({ ...data, file: undefined }), file: data.file }));
        form.post(url, { preserveScroll: true, forceFormData: true, onSuccess: () => { form.reset(); onDone?.(); } });
    }

    return (
        <form onSubmit={submit} className="grid gap-2 sm:grid-cols-2">
            <Field label={t('performance.fields.title')} error={form.errors.title}><input className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field>
            <Field label={t('performance.fields.kpi')} error={form.errors.employee_performance_item_id}>
                <select className={inputCls} value={form.data.employee_performance_item_id} onChange={(e) => form.setData('employee_performance_item_id', e.target.value)}>
                    <option value="">—</option>
                    {items.map((i) => <option key={i.id} value={i.id}>{i.label}</option>)}
                </select>
            </Field>
            <Field label={t('performance.fields.description')} error={form.errors.description} className="sm:col-span-2"><textarea rows={2} className={inputCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
            <Field label={t('performance.fields.file')} error={form.errors.file} help={t('performance.my.evidenceHelp')} className="sm:col-span-2">
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" className="block w-full text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
            </Field>
            <div className="flex justify-end sm:col-span-2"><button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.upload')}</button></div>
        </form>
    );
}

/** Individual development plan entry (employee draft or manager-activated). */
export function DevelopmentPlanForm({ url, competencies, onDone }: { url: string; competencies: { competency_id: string; code: string | null; name_en: string | null; name_am: string | null }[]; onDone?: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({ competency_id: '', development_objective: '', training: '', coaching: '', expected_outcome: '', due_date: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform(nullify);
        form.post(url, { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } });
    }

    return (
        <form onSubmit={submit} className="grid gap-2 sm:grid-cols-2">
            <Field label={t('performance.fields.developmentObjective')} error={form.errors.development_objective} className="sm:col-span-2"><textarea rows={2} className={inputCls} value={form.data.development_objective} onChange={(e) => form.setData('development_objective', e.target.value)} required /></Field>
            <Field label={t('performance.fields.competency')} error={form.errors.competency_id}>
                <select className={inputCls} value={form.data.competency_id} onChange={(e) => form.setData('competency_id', e.target.value)}>
                    <option value="">—</option>
                    {competencies.map((c) => <option key={c.competency_id} value={c.competency_id}>{c.code} — {(locale === 'am' && c.name_am) || c.name_en}</option>)}
                </select>
            </Field>
            <Field label={t('performance.fields.dueDate')} error={form.errors.due_date}><LocalizedDatePicker value={form.data.due_date} onChange={(v) => form.setData('due_date', v)} /></Field>
            <Field label={t('performance.fields.training')} error={form.errors.training}><input className={inputCls} value={form.data.training} onChange={(e) => form.setData('training', e.target.value)} /></Field>
            <Field label={t('performance.fields.coaching')} error={form.errors.coaching}><input className={inputCls} value={form.data.coaching} onChange={(e) => form.setData('coaching', e.target.value)} /></Field>
            <Field label={t('performance.fields.expectedOutcome')} error={form.errors.expected_outcome} className="sm:col-span-2"><input className={inputCls} value={form.data.expected_outcome} onChange={(e) => form.setData('expected_outcome', e.target.value)} /></Field>
            <div className="flex justify-end sm:col-span-2"><button type="submit" className={smallBtn} disabled={form.processing}>{t('performance.actions.save')}</button></div>
        </form>
    );
}
