import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import type { ParameterSchema } from './MethodologyForm';
import { nameOf, useDesignText } from './ui';

export type Named = { id: string; code: string; name_en: string; name_am: string | null };
export type OccupationOption = Named & { isco_code: string | null; professions: Named[] };
export type TaskOption = Named & { process_code: string | null };
export type ProposalOption = { id: string; name_en: string; name_am: string | null; status: string };

export type LineRecord = {
    id?: string; organization_unit_id: string | null; unit_design_proposal_id: string | null; task_id: string | null; occupation_id: string; profession_id: string | null;
    inputs: Record<string, string> | null; recommended_headcount: number | null; approved_headcount: number | null; adjustment_reason: string | null; notes: string | null; sequence: number;
};

/** One plan line: the subject (unit or proposed unit), the occupation, an optional task, the inputs, and the planner's headcount override. */
export default function PlanLineForm({ planId, record, units, proposals, tasks, occupations, inputSchema, onClose }: {
    planId: string; record: LineRecord | null; units: Named[]; proposals: ProposalOption[]; tasks: TaskOption[]; occupations: OccupationOption[]; inputSchema: ParameterSchema; onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm({
        subject_kind: record?.unit_design_proposal_id ? 'proposal' : 'unit', organization_unit_id: record?.organization_unit_id ?? '', unit_design_proposal_id: record?.unit_design_proposal_id ?? '',
        task_id: record?.task_id ?? '', occupation_id: record?.occupation_id ?? '', profession_id: record?.profession_id ?? '',
        inputs: { ...(record?.inputs ?? {}) } as Record<string, string>, approved_headcount: record?.approved_headcount == null ? '' : String(record.approved_headcount),
        adjustment_reason: record?.adjustment_reason ?? '', notes: record?.notes ?? '', sequence: String(record?.sequence ?? 0),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const occupation = occupations.find((o) => o.id === form.data.occupation_id);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const { subject_kind, ...rest } = data;
            const out: Record<string, unknown> = {
                ...rest,
                organization_unit_id: subject_kind === 'unit' ? data.organization_unit_id || null : null,
                unit_design_proposal_id: subject_kind === 'proposal' ? data.unit_design_proposal_id || null : null,
                task_id: data.task_id || null, profession_id: data.profession_id || null, notes: data.notes || null,
                inputs: Object.fromEntries(Object.entries(data.inputs).filter(([, v]) => v !== '')), sequence: Number(data.sequence || 0),
            };
            if (record?.id) { out.approved_headcount = data.approved_headcount === '' ? null : Number(data.approved_headcount); out.adjustment_reason = data.adjustment_reason || null; }
            else { delete out.approved_headcount; delete out.adjustment_reason; }
            return out;
        });
        const options = { preserveScroll: true, onSuccess: onClose };
        if (record?.id) form.put(route('organization-design.manpower-plans.lines.update', record.id), options);
        else form.post(route('organization-design.manpower-plans.lines.store', planId), options);
    }
    const field = (key: string, label: string, required = false) => ({ id: `line-${key}`, label, required, error: errors[key] });

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{record?.id ? tt('plans.editLine') : tt('plans.addLine')}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="line-subject" label={tt('plans.unitOrProposal')}>
                        {({ id }) => (
                            <Select id={id} value={form.data.subject_kind} onChange={(e) => form.setData('subject_kind', e.target.value)}>
                                <option value="unit">{tt('fields.organizationUnit')}</option>
                                {proposals.length > 0 && <option value="proposal">{tt('plans.proposedUnit')}</option>}
                            </Select>
                        )}
                    </FormField>
                    {form.data.subject_kind === 'unit' ? (
                        <FormField {...field('organization_unit_id', tt('fields.organizationUnit'), true)}>
                            {({ id }) => <Select id={id} value={form.data.organization_unit_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)}><option value="">—</option>{units.map((u) => <option key={u.id} value={u.id}>{u.code} · {nameOf(u, locale)}</option>)}</Select>}
                        </FormField>
                    ) : (
                        <FormField {...field('unit_design_proposal_id', tt('plans.proposedUnit'), true)}>
                            {({ id }) => <Select id={id} value={form.data.unit_design_proposal_id} onChange={(e) => form.setData('unit_design_proposal_id', e.target.value)}><option value="">—</option>{proposals.map((p) => <option key={p.id} value={p.id}>{nameOf(p, locale)}</option>)}</Select>}
                        </FormField>
                    )}
                    <FormField {...field('occupation_id', tt('fields.occupation'), true)}>
                        {({ id }) => <Select id={id} value={form.data.occupation_id} onChange={(e) => { form.setData('occupation_id', e.target.value); form.setData('profession_id', ''); }}><option value="">—</option>{occupations.map((o) => <option key={o.id} value={o.id}>{o.isco_code ?? o.code} · {nameOf(o, locale)}</option>)}</Select>}
                    </FormField>
                    <FormField {...field('profession_id', tt('fields.profession'))}>
                        {({ id }) => <Select id={id} value={form.data.profession_id} disabled={!occupation || occupation.professions.length === 0} onChange={(e) => form.setData('profession_id', e.target.value)}><option value="">—</option>{(occupation?.professions ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} · {nameOf(p, locale)}</option>)}</Select>}
                    </FormField>
                    <FormField {...field('task_id', tt('fields.task'))} className="sm:col-span-2">
                        {({ id }) => <Select id={id} value={form.data.task_id} onChange={(e) => form.setData('task_id', e.target.value)}><option value="">—</option>{tasks.map((t) => <option key={t.id} value={t.id}>{t.process_code ? `${t.process_code} · ` : ''}{t.code} · {nameOf(t, locale)}</option>)}</Select>}
                    </FormField>

                    <fieldset className="sm:col-span-2">
                        <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{tt('plans.inputs')}</legend>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">
                            {Object.entries(inputSchema).map(([key, rule]) => (
                                <FormField key={key} id={`line-input-${key}`} label={tt(`methodologies.inputNames.${key}`)} error={errors[`inputs.${key}`]} description={`${rule.min ?? '−∞'} – ${rule.max ?? '∞'}`}>
                                    {({ id }) => <Input id={id} inputMode="decimal" value={form.data.inputs[key] ?? ''} onChange={(e) => form.setData('inputs', { ...form.data.inputs, [key]: e.target.value })} />}
                                </FormField>
                            ))}
                        </div>
                    </fieldset>

                    {record?.id && (
                        <>
                            <FormField {...field('approved_headcount', tt('plans.approvedHeadcount'))} description={record.recommended_headcount != null ? `${tt('plans.recommended')}: ${record.recommended_headcount}` : undefined}>
                                {({ id }) => <Input id={id} type="number" min={0} value={form.data.approved_headcount} onChange={(e) => form.setData('approved_headcount', e.target.value)} />}
                            </FormField>
                            <FormField {...field('adjustment_reason', tt('plans.adjustmentReason'))}>{({ id }) => <Input id={id} value={form.data.adjustment_reason} maxLength={2000} onChange={(e) => form.setData('adjustment_reason', e.target.value)} />}</FormField>
                        </>
                    )}
                    <FormField {...field('sequence', tt('fields.sequence'))}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}</FormField>
                    <FormField {...field('notes', tt('fields.notes'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />}</FormField>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
