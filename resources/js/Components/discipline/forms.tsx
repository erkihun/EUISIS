import { inputCls, primaryBtn } from '@/Components/dailyActivity/helpers';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import { Field, TextArea, type Charge } from './shared';

/**
 * Art. 20 defence: preliminary objection and answer together, with an
 * explicit plea on every charge (no default — silence is not an admission).
 */
export function ResponseForm({ action, charges, objectionGrounds }: { action: string; charges: Charge[]; objectionGrounds: string[] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm<{
        pleas: Record<string, { plea: string; statement: string }>;
        objection_ground: string;
        objection_text: string;
        defense_evidence_list: string;
        acknowledged_name: string;
    }>({
        pleas: Object.fromEntries(charges.map((c) => [c.id, { plea: '', statement: '' }])),
        objection_ground: '',
        objection_text: '',
        defense_evidence_list: '',
        acknowledged_name: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((d) => ({ ...d, objection_ground: d.objection_ground || null, objection_text: d.objection_ground ? d.objection_text : null }));
        form.post(action, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    const setPlea = (id: string, key: 'plea' | 'statement', value: string) =>
        form.setData('pleas', { ...form.data.pleas, [id]: { ...form.data.pleas[id], [key]: value } });

    return (
        <form onSubmit={submit} className="grid gap-3">
            {charges.map((c) => (
                <fieldset key={c.id} className="grid gap-2 rounded-control border border-gray-100 p-3 sm:grid-cols-3 dark:border-slate-800">
                    <legend className="px-1 text-xs font-medium text-gray-700 dark:text-slate-300">{c.sequence}. {c.title}</legend>
                    <Field id={`plea-${c.id}`} label={t('discipline.response.plea')} error={errors[`pleas.${c.id}.plea`]}>
                        <select id={`plea-${c.id}`} className={inputCls} value={form.data.pleas[c.id]?.plea ?? ''} onChange={(e) => setPlea(c.id, 'plea', e.target.value)} required>
                            <option value="" disabled>—</option>
                            <option value="admitted">{t('discipline.pleas.admitted')}</option>
                            <option value="denied">{t('discipline.pleas.denied')}</option>
                        </select>
                    </Field>
                    <div className="sm:col-span-2">
                        <Field id={`stmt-${c.id}`} label={t('discipline.response.statement')}>
                            <TextArea id={`stmt-${c.id}`} rows={2} value={form.data.pleas[c.id]?.statement ?? ''} onChange={(v) => setPlea(c.id, 'statement', v)} />
                        </Field>
                    </div>
                </fieldset>
            ))}
            {errors.pleas && <p className="text-xs text-red-700 dark:text-red-400">{errors.pleas}</p>}
            <Field id="objection_ground" label={t('discipline.response.objectionGround')} error={errors.objection_ground}>
                <select id="objection_ground" className={inputCls} value={form.data.objection_ground} onChange={(e) => form.setData('objection_ground', e.target.value)}>
                    <option value="">—</option>
                    {objectionGrounds.map((g) => <option key={g} value={g}>{t(`discipline.objectionGrounds.${g}`)}</option>)}
                </select>
            </Field>
            {form.data.objection_ground && (
                <Field id="objection_text" label={t('discipline.response.objectionText')} error={errors.objection_text}>
                    <TextArea id="objection_text" value={form.data.objection_text} onChange={(v) => form.setData('objection_text', v)} />
                </Field>
            )}
            <Field id="defense_evidence_list" label={t('discipline.response.defenseEvidence')} error={errors.defense_evidence_list}>
                <TextArea id="defense_evidence_list" value={form.data.defense_evidence_list} onChange={(v) => form.setData('defense_evidence_list', v)} />
            </Field>
            <Field id="acknowledged_name" label={t('discipline.response.acknowledgedName')} error={errors.acknowledged_name}>
                <input id="acknowledged_name" className={inputCls} value={form.data.acknowledged_name} onChange={(e) => form.setData('acknowledged_name', e.target.value)} required />
                <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('discipline.response.acknowledgementHelp')}</p>
            </Field>
            <div><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.submit')}</button></div>
        </form>
    );
}

export function EvidenceForm({ action, sides, charges }: { action: string; sides: string[] | null; charges: Charge[] }): JSX.Element {
    const { t } = useLocale();
    const form = useForm<{ side: string; title: string; description: string; held_by: string; charge_id: string; file: File | null }>({
        side: sides?.[0] ?? 'accused', title: '', description: '', held_by: '', charge_id: '', file: null,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.transform((d) => ({ ...d, charge_id: d.charge_id || null }));
        form.post(action, { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            {sides && (
                <Field id="ev-side" label={t('discipline.evidence.side')} error={form.errors.side}>
                    <select id="ev-side" className={inputCls} value={form.data.side} onChange={(e) => form.setData('side', e.target.value)}>
                        {sides.map((s) => <option key={s} value={s}>{t(`discipline.sides.${s}`)}</option>)}
                    </select>
                </Field>
            )}
            <Field id="ev-charge" label={t('discipline.sections.charges')} error={form.errors.charge_id}>
                <select id="ev-charge" className={inputCls} value={form.data.charge_id} onChange={(e) => form.setData('charge_id', e.target.value)}>
                    <option value="">—</option>
                    {charges.map((c) => <option key={c.id} value={c.id}>{c.sequence}. {c.title}</option>)}
                </select>
            </Field>
            <Field id="ev-title" label={t('discipline.evidence.titleLabel')} error={form.errors.title}>
                <input id="ev-title" className={inputCls} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required />
            </Field>
            <Field id="ev-held" label={t('discipline.evidence.heldBy')} error={form.errors.held_by}>
                <input id="ev-held" className={inputCls} value={form.data.held_by} onChange={(e) => form.setData('held_by', e.target.value)} />
            </Field>
            <div className="sm:col-span-2">
                <Field id="ev-desc" label={t('discipline.evidence.descriptionLabel')} error={form.errors.description}>
                    <TextArea id="ev-desc" rows={2} value={form.data.description} onChange={(v) => form.setData('description', v)} />
                </Field>
            </div>
            <Field id="ev-file" label={t('discipline.evidence.file')} error={form.errors.file}>
                <input id="ev-file" type="file" accept=".pdf,.jpg,.jpeg,.png" className="text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
            </Field>
            <div className="sm:col-span-2"><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.addEvidence')}</button></div>
        </form>
    );
}

export function FinalStatementForm({ action }: { action: string }): JSX.Element {
    const { t } = useLocale();
    const form = useForm({ statement: '', declined: false });

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(action, { preserveScroll: true }); }} className="grid gap-3">
            <Field id="final_statement" label={t('discipline.sections.finalStatement')} error={form.errors.statement}>
                <TextArea id="final_statement" rows={4} value={form.data.statement} onChange={(v) => form.setData('statement', v)} />
            </Field>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                <input type="checkbox" checked={form.data.declined} onChange={(e) => form.setData('declined', e.target.checked)} />
                {t('discipline.actions.declined')}
            </label>
            <div><button type="submit" className={primaryBtn} disabled={form.processing}>{t('discipline.actions.recordStatement')}</button></div>
        </form>
    );
}
