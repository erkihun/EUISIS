import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useDesignText } from './ui';

export type ParameterSchema = Record<string, { type: 'decimal' | 'integer'; min?: string | null; max?: string | null; min_exclusive?: boolean; max_exclusive?: boolean; required?: boolean }>;
export type Strategy = { type: string; parameters: ParameterSchema; inputs: ParameterSchema };
export type MethodologyRecord = {
    id?: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null;
    calculation_type: string; parameters: Record<string, string>; rounding_rule: string | null; effective_from: string | null; effective_to: string | null;
};

/** A methodology draft (POST), draft edit (PUT) or new version (POST versions; strategy and code locked). */
export default function MethodologyForm({ record, mode, strategies, roundingRules, onClose }: { record: MethodologyRecord | null; mode: 'create' | 'edit' | 'version'; strategies: Strategy[]; roundingRules: string[]; onClose: () => void }) {
    const { tt } = useDesignText();
    const form = useForm({
        code: record?.code ?? '', name_en: record?.name_en ?? '', name_am: record?.name_am ?? '', description_en: record?.description_en ?? '', description_am: record?.description_am ?? '',
        calculation_type: record?.calculation_type ?? strategies[0]?.type ?? '', parameters: { ...(record?.parameters ?? {}) } as Record<string, string>,
        rounding_rule: record?.rounding_rule ?? '', effective_from: mode === 'version' ? '' : (record?.effective_from ?? ''), effective_to: mode === 'version' ? '' : (record?.effective_to ?? ''),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const strategy = strategies.find((s) => s.type === form.data.calculation_type);
    const title = mode === 'create' ? tt('methodologies.create') : mode === 'edit' ? tt('methodologies.edit') : tt('methodologies.newVersion');

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const out: Record<string, unknown> = {
                name_en: data.name_en, name_am: data.name_am || null, description_en: data.description_en || null, description_am: data.description_am || null,
                parameters: Object.fromEntries(Object.entries(data.parameters).filter(([k, v]) => strategy?.parameters[k] && v !== '')),
                rounding_rule: data.rounding_rule || null, effective_from: data.effective_from || null, effective_to: data.effective_to || null,
            };
            if (mode !== 'version') { out.code = data.code; out.calculation_type = data.calculation_type; }
            return out;
        });
        const options = { preserveScroll: true, onSuccess: onClose };
        if (mode === 'edit' && record?.id) form.put(route('organization-design.methodologies.update', record.id), options);
        else if (mode === 'version' && record?.id) form.post(route('organization-design.methodologies.versions.store', record.id), options);
        else form.post(route('organization-design.methodologies.store'), options);
    }
    const field = (key: string, label: string, required = false) => ({ id: `meth-${key}`, label, required, error: errors[key] });

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                {mode === 'version' && <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('methodologies.newVersionHelp')}</p>}
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField {...field('code', tt('fields.code'), true)}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} disabled={mode === 'version'} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}</FormField>
                    <FormField {...field('calculation_type', tt('fields.calculationType'), true)}>
                        {({ id }) => (
                            <Select id={id} value={form.data.calculation_type} disabled={mode === 'version'} onChange={(e) => { form.setData('calculation_type', e.target.value); form.setData('parameters', {}); }}>
                                {strategies.map((s) => <option key={s.type} value={s.type}>{tt(`methodologies.strategies.${s.type}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    {strategy && <p className="text-xs text-(--app-muted-foreground) sm:col-span-2">{tt(`methodologies.strategyHelp.${strategy.type}`)}</p>}
                    <FormField {...field('name_en', tt('fields.nameEn'), true)}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField {...field('name_am', tt('fields.nameAm'))}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>

                    <fieldset className="sm:col-span-2">
                        <legend className="text-sm font-medium text-(--app-foreground)">{tt('methodologies.parameters')}</legend>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">
                            {Object.entries(strategy?.parameters ?? {}).map(([key, rule]) => (
                                <FormField key={key} id={`meth-param-${key}`} label={tt(`methodologies.parameterNames.${key}`)} required={rule.required !== false} error={errors[`parameters.${key}`]} description={`${rule.min ?? '−∞'} – ${rule.max ?? '∞'}`}>
                                    {({ id }) => <Input id={id} inputMode="decimal" value={form.data.parameters[key] ?? ''} onChange={(e) => form.setData('parameters', { ...form.data.parameters, [key]: e.target.value })} />}
                                </FormField>
                            ))}
                        </div>
                        <FieldError>{errors.parameters}</FieldError>
                    </fieldset>

                    <FormField {...field('rounding_rule', tt('methodologies.rounding'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.rounding_rule} onChange={(e) => form.setData('rounding_rule', e.target.value)}>
                                <option value="">{tt('methodologies.noRounding')}</option>
                                {roundingRules.map((r) => <option key={r} value={r}>{tt(`methodologies.roundingRules.${r}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <div />
                    <FormField {...field('effective_from', tt('fields.effectiveFrom'), true)}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}</FormField>
                    <FormField {...field('effective_to', tt('fields.effectiveTo'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(iso) => form.setData('effective_to', iso)} />}</FormField>
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
