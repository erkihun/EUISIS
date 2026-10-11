import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Option = { id: string; code: string; name_en: string; name_am: string | null };
export type ProposalOption = { id: string; name_en: string; name_am: string | null; depth: number };

export type ProposalRecord = {
    id?: string; proposal_type: string; existing_unit_id: string | null; parent_existing_unit_id: string | null; parent_proposed_unit_id: string | null;
    proposed_unit_type_id: string | null; unit_type: string | null; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null;
    design_rationale: string | null; effective_from: string | null; effective_to: string | null; sequence: number;
};

/** One unit proposal: a new unit under an existing or proposed parent, or a change / move / deactivation of an existing unit. */
export default function ProposalForm({ studyId, record, parentProposedId, units, unitTypes, proposals, proposalTypes, onClose }: {
    studyId: string; record: ProposalRecord | null; parentProposedId: string | null; units: Option[]; unitTypes: Option[]; proposals: ProposalOption[]; proposalTypes: string[]; onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm({
        proposal_type: record?.proposal_type ?? 'create', existing_unit_id: record?.existing_unit_id ?? '', parent_kind: record?.parent_proposed_unit_id || parentProposedId ? 'proposed' : record?.parent_existing_unit_id ? 'existing' : 'none',
        parent_existing_unit_id: record?.parent_existing_unit_id ?? '', parent_proposed_unit_id: record?.parent_proposed_unit_id ?? parentProposedId ?? '',
        proposed_unit_type_id: record?.proposed_unit_type_id ?? '', unit_type: record?.unit_type ?? '', name_en: record?.name_en ?? '', name_am: record?.name_am ?? '',
        description_en: record?.description_en ?? '', description_am: record?.description_am ?? '', design_rationale: record?.design_rationale ?? '',
        effective_from: record?.effective_from ?? '', effective_to: record?.effective_to ?? '', sequence: String(record?.sequence ?? 0),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const isCreate = form.data.proposal_type === 'create';
    const needsParent = isCreate || form.data.proposal_type === 'move';
    const candidates = proposals.filter((p) => p.id !== record?.id);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const { parent_kind, ...rest } = data;
            const out: Record<string, unknown> = Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, v === '' ? null : v]));
            out.sequence = Number(data.sequence || 0);
            if (parent_kind !== 'existing') out.parent_existing_unit_id = null;
            if (parent_kind !== 'proposed') out.parent_proposed_unit_id = null;
            if (isCreate) out.existing_unit_id = null;
            return out;
        });
        const options = { preserveScroll: true, onSuccess: onClose };
        if (record?.id) form.put(route('organization-design.proposals.update', record.id), options);
        else form.post(route('organization-design.studies.proposals.store', studyId), options);
    }
    const field = (key: string, label: string, required = false) => ({ id: `prop-${key}`, label, required, error: errors[key] });

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{record?.id ? tt('studies.editProposal') : tt('studies.addProposal')}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField {...field('proposal_type', tt('fields.proposalType'), true)}>
                        {({ id }) => <Select id={id} value={form.data.proposal_type} onChange={(e) => form.setData('proposal_type', e.target.value)}>{proposalTypes.map((p) => <option key={p} value={p}>{tt(`studies.proposalTypes.${p}`)}</option>)}</Select>}
                    </FormField>
                    {!isCreate && (
                        <FormField {...field('existing_unit_id', tt('fields.existingUnit'), true)}>
                            {({ id }) => <Select id={id} value={form.data.existing_unit_id} onChange={(e) => form.setData('existing_unit_id', e.target.value)}><option value="">—</option>{units.map((u) => <option key={u.id} value={u.id}>{u.code} · {nameOf(u, locale)}</option>)}</Select>}
                        </FormField>
                    )}
                    {isCreate && (
                        <FormField {...field('proposed_unit_type_id', tt('fields.unitType'), true)}>
                            {({ id }) => <Select id={id} value={form.data.proposed_unit_type_id} onChange={(e) => form.setData('proposed_unit_type_id', e.target.value)}><option value="">—</option>{unitTypes.map((u) => <option key={u.id} value={u.id}>{nameOf(u, locale)}</option>)}</Select>}
                        </FormField>
                    )}
                    {needsParent && (
                        <>
                            <FormField id="prop-parent-kind" label={tt('fields.parentUnit')}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.parent_kind} onChange={(e) => form.setData('parent_kind', e.target.value)}>
                                        <option value="none">{tt('studies.topLevel')}</option>
                                        <option value="existing">{tt('studies.parentExisting')}</option>
                                        <option value="proposed">{tt('studies.parentProposed')}</option>
                                    </Select>
                                )}
                            </FormField>
                            {form.data.parent_kind === 'existing' && (
                                <FormField {...field('parent_existing_unit_id', tt('studies.parentExisting'), true)}>
                                    {({ id }) => <Select id={id} value={form.data.parent_existing_unit_id} onChange={(e) => form.setData('parent_existing_unit_id', e.target.value)}><option value="">—</option>{units.map((u) => <option key={u.id} value={u.id}>{u.code} · {nameOf(u, locale)}</option>)}</Select>}
                                </FormField>
                            )}
                            {form.data.parent_kind === 'proposed' && (
                                <FormField {...field('parent_proposed_unit_id', tt('studies.parentProposed'), true)}>
                                    {({ id }) => <Select id={id} value={form.data.parent_proposed_unit_id} onChange={(e) => form.setData('parent_proposed_unit_id', e.target.value)}><option value="">—</option>{candidates.map((p) => <option key={p.id} value={p.id}>{'— '.repeat(p.depth)}{nameOf(p, locale)}</option>)}</Select>}
                                </FormField>
                            )}
                        </>
                    )}
                    <FormField {...field('name_en', tt('fields.nameEn'), isCreate)}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField {...field('name_am', tt('fields.nameAm'))}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>
                    <FormField {...field('sequence', tt('fields.sequence'))}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}</FormField>
                    {form.data.proposal_type === 'deactivate'
                        ? <FormField {...field('effective_to', tt('fields.effectiveTo'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} onChange={(iso) => form.setData('effective_to', iso)} />}</FormField>
                        : <FormField {...field('effective_from', tt('fields.effectiveFrom'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}</FormField>}
                    <FormField {...field('design_rationale', tt('studies.rationale'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.design_rationale} onChange={(e) => form.setData('design_rationale', e.target.value)} />}</FormField>
                    <FormField {...field('description_en', tt('fields.descriptionEn'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}</FormField>
                    <FormField {...field('description_am', tt('fields.descriptionAm'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}</FormField>
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
