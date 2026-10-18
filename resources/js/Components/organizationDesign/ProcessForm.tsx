import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Option = { id: string; code: string; name_en: string; name_am: string | null };
export type ServiceOption = Option & { organization_id?: string; version_no?: number };

export type ProcessFormValues = {
    organization_id: string;
    service_id: string;
    code: string;
    name_en: string;
    name_am: string;
    description_en: string;
    description_am: string;
    process_type: string;
    owner_organization_unit_id: string;
    effective_from: string;
    effective_to: string;
};

export const emptyProcess = (organizationId = ''): ProcessFormValues => ({
    organization_id: organizationId, service_id: '', code: '', name_en: '', name_am: '', description_en: '', description_am: '',
    process_type: 'core', owner_organization_unit_id: '', effective_from: '', effective_to: '',
});

/** One form for a new draft (POST), a draft edit (PUT) and a new version (POST versions, code locked). */
export default function ProcessForm({ show, onClose, title, help, initial, url, method, organizations, services, units, processTypes, lockCode = false }: {
    show: boolean;
    onClose: () => void;
    title: string;
    help?: string;
    initial: ProcessFormValues;
    url: string;
    method: 'post' | 'put';
    organizations?: Option[];
    services: ServiceOption[];
    units: Option[];
    processTypes: string[];
    lockCode?: boolean;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<ProcessFormValues>(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const visibleServices = organizations ? services.filter((s) => !s.organization_id || s.organization_id === form.data.organization_id) : services;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }

    const field = (key: keyof ProcessFormValues, label: string, required = false) => ({ id: `process-${key}`, label, required, error: errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                {help && <p className="mt-1 text-sm text-(--app-muted-foreground)">{help}</p>}

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), true)} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={form.data.organization_id} onChange={(e) => { form.setData('organization_id', e.target.value); form.setData('service_id', ''); }}>
                                    <option value="">—</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('service_id', tt('fields.service'), true)} className="sm:col-span-2">
                        {({ id }) => (
                            <Select id={id} value={form.data.service_id} onChange={(e) => form.setData('service_id', e.target.value)}>
                                <option value="">—</option>
                                {visibleServices.map((s) => <option key={s.id} value={s.id}>{s.code}{s.version_no ? ` v${s.version_no}` : ''} · {nameOf(s, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('code', tt('fields.code'), true)}>
                        {({ id }) => <Input id={id} value={form.data.code} maxLength={40} disabled={lockCode} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField {...field('process_type', tt('fields.processType'), true)}>
                        {({ id }) => (
                            <Select id={id} value={form.data.process_type} onChange={(e) => form.setData('process_type', e.target.value)}>
                                {processTypes.map((p) => <option key={p} value={p}>{tt(`processes.types.${p}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('name_en', tt('fields.nameEn'), true)}>
                        {({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('name_am', tt('fields.nameAm'))}>
                        {({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}
                    </FormField>
                    {!organizations && (
                        <FormField {...field('owner_organization_unit_id', tt('fields.ownerUnit'))} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={form.data.owner_organization_unit_id} onChange={(e) => form.setData('owner_organization_unit_id', e.target.value)}>
                                    <option value="">—</option>
                                    {units.map((u) => <option key={u.id} value={u.id}>{u.code} · {nameOf(u, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
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
