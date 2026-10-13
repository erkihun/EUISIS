import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Alert, Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, titleOf, useDesignText } from './ui';

export type Option = { id: string; code: string; name_en: string; name_am: string | null };
export type ClassificationOption = Option & { revision: string; level: number };
export type FunctionOption = { id: string; code: string; title_en: string; title_am: string | null; depth: number };
export type IsicOption = { id: string; isic_code: string; name_en: string; name_am: string | null; level: string };

export type ServiceFormValues = {
    organization_id: string;
    code: string;
    name_en: string;
    name_am: string;
    description_en: string;
    description_am: string;
    service_type_id: string;
    service_classification_id: string;
    cpc_classification_id: string;
    beneficiary_type: string;
    delivery_channel: string;
    legal_basis: string;
    owner_organization_unit_id: string;
    effective_from: string;
    effective_to: string;
    function_ids: string[];
    isic_activity_ids: string[];
};

export const emptyService = (organizationId = ''): ServiceFormValues => ({
    organization_id: organizationId, code: '', name_en: '', name_am: '', description_en: '', description_am: '',
    service_type_id: '', service_classification_id: '', cpc_classification_id: '', beneficiary_type: '', delivery_channel: '',
    legal_basis: '', owner_organization_unit_id: '', effective_from: '', effective_to: '', function_ids: [], isic_activity_ids: [],
});

export type ServiceFormLookups = {
    organizations?: Option[];
    functions: FunctionOption[];
    units: Option[];
    classifications: ClassificationOption[];
    cpcClassifications: ClassificationOption[];
    isicOptions: IsicOption[];
    serviceTypes: Option[];
    beneficiaryTypes: string[];
    deliveryChannels: string[];
};

/**
 * One form for the three ways a service is written: a new draft (POST), a
 * draft edit (PUT) and a new version of a published service (POST versions),
 * where the code names the lineage and cannot change. On a new draft from
 * the index, functions and units are chosen after the organization is known,
 * so those pickers appear only when the form has an organization's lookups.
 */
export default function ServiceForm({ show, onClose, title, help, initial, url, method, lookups, lockCode = false }: {
    show: boolean;
    onClose: () => void;
    title: string;
    help?: string;
    initial: ServiceFormValues;
    url: string;
    method: 'post' | 'put';
    lookups: ServiceFormLookups;
    lockCode?: boolean;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<ServiceFormValues>(initial);
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }

    const field = (key: keyof ServiceFormValues, label: string, required = false) => ({ id: `service-${key}`, label, required, error: errors[key] });
    const toggle = (key: 'function_ids' | 'isic_activity_ids', id: string) => {
        const current = form.data[key];
        form.setData(key, current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    };
    const checkbox = 'mt-0.5 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]';

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                {help && <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{help}</p>}

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {lookups.organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), true)} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    <option value="">—</option>
                                    {lookups.organizations!.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('code', tt('fields.code'), true)}>
                        {({ id }) => <Input id={id} value={form.data.code} maxLength={40} disabled={lockCode} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField {...field('service_classification_id', tt('fields.serviceClassification'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.service_classification_id} onChange={(e) => form.setData('service_classification_id', e.target.value)}>
                                <option value="">—</option>
                                {lookups.classifications.map((c) => <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)} ({c.revision})</option>)}
                            </Select>
                        )}
                    </FormField>
                    {lookups.classifications.length === 0 && <Alert tone="warning" className="sm:col-span-2">{tt('services.classificationRequired')}</Alert>}
                    <FormField {...field('name_en', tt('fields.nameEn'), true)}>
                        {({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('name_am', tt('fields.nameAm'))}>
                        {({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField {...field('beneficiary_type', tt('fields.beneficiaryType'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.beneficiary_type} onChange={(e) => form.setData('beneficiary_type', e.target.value)}>
                                <option value="">—</option>
                                {lookups.beneficiaryTypes.map((b) => <option key={b} value={b}>{tt(`services.beneficiary.${b}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('delivery_channel', tt('fields.deliveryChannel'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.delivery_channel} onChange={(e) => form.setData('delivery_channel', e.target.value)}>
                                <option value="">—</option>
                                {lookups.deliveryChannels.map((c) => <option key={c} value={c}>{tt(`services.channel.${c}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('cpc_classification_id', tt('fields.cpcClassification'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.cpc_classification_id} disabled={lookups.cpcClassifications.length === 0} onChange={(e) => form.setData('cpc_classification_id', e.target.value)}>
                                <option value="">—</option>
                                {lookups.cpcClassifications.map((c) => <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)} ({c.revision})</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('service_type_id', tt('fields.serviceType'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.service_type_id} onChange={(e) => form.setData('service_type_id', e.target.value)}>
                                <option value="">—</option>
                                {lookups.serviceTypes.map((s) => <option key={s.id} value={s.id}>{nameOf(s, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    {!lookups.organizations && (
                        <FormField {...field('owner_organization_unit_id', tt('fields.ownerUnit'))}>
                            {({ id }) => (
                                <Select id={id} value={form.data.owner_organization_unit_id} onChange={(e) => form.setData('owner_organization_unit_id', e.target.value)}>
                                    <option value="">—</option>
                                    {lookups.units.map((u) => <option key={u.id} value={u.id}>{u.code} · {nameOf(u, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('legal_basis', tt('fields.legalBasis'))}>
                        {({ id }) => <Input id={id} value={form.data.legal_basis} onChange={(e) => form.setData('legal_basis', e.target.value)} />}
                    </FormField>
                    <FormField {...field('effective_from', tt('fields.effectiveFrom'), true)}>
                        {({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_from} onChange={(iso) => form.setData('effective_from', iso)} />}
                    </FormField>
                    <FormField {...field('effective_to', tt('fields.effectiveTo'))}>
                        {({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(iso) => form.setData('effective_to', iso)} />}
                    </FormField>
                    <FormField {...field('description_en', tt('fields.descriptionEn'))} className="sm:col-span-2">
                        {({ id }) => <Textarea id={id} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('description_am', tt('fields.descriptionAm'))} className="sm:col-span-2">
                        {({ id }) => <Textarea id={id} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
                    </FormField>

                    {!lookups.organizations && (
                        <fieldset className="sm:col-span-2">
                            <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{tt('services.justifiedBy')}</legend>
                            <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('services.justifiedByHelp')}</p>
                            {lookups.functions.length === 0 ? (
                                <Alert tone="warning" className="mt-2">{tt('services.noFunctionsAvailable')}</Alert>
                            ) : (
                                <ul className="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-2">
                                    {lookups.functions.map((f) => (
                                        <li key={f.id}>
                                            <label className="flex cursor-pointer items-start gap-2 rounded px-2 py-1 text-sm hover:bg-[color:var(--app-surface-muted)]" style={{ paddingLeft: `${8 + f.depth * 16}px` }}>
                                                <input type="checkbox" className={checkbox} checked={form.data.function_ids.includes(f.id)} onChange={() => toggle('function_ids', f.id)} />
                                                <span className="min-w-0"><span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{f.code}</span> {titleOf(f, locale)}</span>
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <FieldError>{errors.function_ids ?? errors['function_ids.0']}</FieldError>
                        </fieldset>
                    )}

                    {!lookups.organizations && lookups.isicOptions.length > 0 && (
                        <fieldset className="sm:col-span-2">
                            <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{tt('services.isic')}</legend>
                            <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('services.isicHelp')}</p>
                            <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-2">
                                {lookups.isicOptions.map((a) => (
                                    <li key={a.id}>
                                        <label className="flex cursor-pointer items-start gap-2 rounded px-2 py-1 text-sm hover:bg-[color:var(--app-surface-muted)]">
                                            <input type="checkbox" className={checkbox} checked={form.data.isic_activity_ids.includes(a.id)} onChange={() => toggle('isic_activity_ids', a.id)} />
                                            <span className="min-w-0"><span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{a.isic_code}</span> {nameOf(a, locale)}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                            <FieldError>{errors.isic_activity_ids ?? errors['isic_activity_ids.0']}</FieldError>
                        </fieldset>
                    )}
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
