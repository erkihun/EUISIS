import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type InstrumentType = { id: string; code: string; name_en: string; name_am: string | null };

export type MandateFormValues = {
    organization_id: string;
    code: string;
    title_en: string;
    title_am: string;
    description_en: string;
    description_am: string;
    legal_instrument_type_id: string;
    legal_instrument_number: string;
    legal_reference: string;
    article_reference: string;
    issuing_authority: string;
    effective_from: string;
    effective_to: string;
};

export const emptyMandate = (organizationId = ''): MandateFormValues => ({
    organization_id: organizationId, code: '', title_en: '', title_am: '', description_en: '', description_am: '',
    legal_instrument_type_id: '', legal_instrument_number: '', legal_reference: '', article_reference: '', issuing_authority: '',
    effective_from: '', effective_to: '',
});

/**
 * One form for the three ways a mandate is written: a new draft (POST), a
 * draft edit (PUT) and a new version of a published mandate (POST versions),
 * where the code names the lineage and cannot change.
 */
export default function MandateForm({ show, onClose, title, help, initial, url, method, organizations, instrumentTypes, lockCode = false }: {
    show: boolean;
    onClose: () => void;
    title: string;
    help?: string;
    initial: MandateFormValues;
    url: string;
    method: 'post' | 'put';
    organizations?: { id: string; code: string; name_en: string; name_am: string | null }[];
    instrumentTypes: InstrumentType[];
    lockCode?: boolean;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<MandateFormValues>(initial);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }

    const field = (key: keyof MandateFormValues, label: string, required = false) => ({ id: `mandate-${key}`, label, required, error: form.errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                {help && <p className="mt-1 text-sm text-(--app-muted-foreground)">{help}</p>}

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), true)} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    <option value="">—</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('code', tt('fields.code'), true)}>
                        {({ id }) => <Input id={id} value={form.data.code} maxLength={40} disabled={lockCode} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField {...field('legal_instrument_type_id', tt('fields.instrumentType'), true)}>
                        {({ id }) => (
                            <Select id={id} value={form.data.legal_instrument_type_id} onChange={(e) => form.setData('legal_instrument_type_id', e.target.value)}>
                                <option value="">—</option>
                                {instrumentTypes.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('title_en', tt('fields.titleEn'), true)}>
                        {({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('title_am', tt('fields.titleAm'))}>
                        {({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}
                    </FormField>
                    <FormField {...field('legal_instrument_number', tt('fields.instrumentNumber'), true)}>
                        {({ id }) => <Input id={id} value={form.data.legal_instrument_number} maxLength={100} onChange={(e) => form.setData('legal_instrument_number', e.target.value)} />}
                    </FormField>
                    <FormField {...field('issuing_authority', tt('fields.issuingAuthority'))}>
                        {({ id }) => <Input id={id} value={form.data.issuing_authority} onChange={(e) => form.setData('issuing_authority', e.target.value)} />}
                    </FormField>
                    <FormField {...field('legal_reference', tt('fields.legalReference'))}>
                        {({ id }) => <Input id={id} value={form.data.legal_reference} onChange={(e) => form.setData('legal_reference', e.target.value)} />}
                    </FormField>
                    <FormField {...field('article_reference', tt('fields.articleReference'))}>
                        {({ id }) => <Input id={id} value={form.data.article_reference} onChange={(e) => form.setData('article_reference', e.target.value)} />}
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
                </div>
                <FieldError>{(form.errors as Record<string, string | undefined>).status}</FieldError>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
