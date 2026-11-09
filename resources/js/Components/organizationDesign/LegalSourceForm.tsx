import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type SourceType = { id: string; code: string; name_en: string; name_am: string | null };

export type LegalSourceFormValues = {
    organization_id: string;
    code: string;
    title_en: string;
    title_am: string;
    source_type_id: string;
    issuing_authority: string;
    document_number: string;
    issue_date: string;
    effective_from: string;
    effective_to: string;
    description_en: string;
    description_am: string;
    source_url: string;
};

export const emptyLegalSource = (organizationId = ''): LegalSourceFormValues => ({
    organization_id: organizationId, code: '', title_en: '', title_am: '', source_type_id: '', issuing_authority: '', document_number: '',
    issue_date: '', effective_from: '', effective_to: '', description_en: '', description_am: '', source_url: '',
});

/**
 * One form for recording and editing a legal source. The organization is
 * chosen only when recording: a source never moves between institutions.
 * An empty organization means a city-level source, offered only to the
 * catalog manager.
 */
export default function LegalSourceForm({ show, onClose, title, initial, url, method, organizations, sourceTypes, allowCityLevel = false }: {
    show: boolean;
    onClose: () => void;
    title: string;
    initial: LegalSourceFormValues;
    url: string;
    method: 'post' | 'put';
    organizations?: { id: string; code: string; name_en: string; name_am: string | null }[];
    sourceTypes: SourceType[];
    allowCityLevel?: boolean;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<LegalSourceFormValues>(initial);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }

    const field = (key: keyof LegalSourceFormValues, label: string, required = false) => ({ id: `legal-source-${key}`, label, required, error: form.errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('legalSources.formHelp')}</p>

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), !allowCityLevel)} className="sm:col-span-2">
                            {({ id }) => (
                                <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    <option value="">{allowCityLevel ? tt('legalSources.cityLevel') : '—'}</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('code', tt('fields.code'), true)}>
                        {({ id }) => <Input id={id} value={form.data.code} maxLength={60} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField {...field('source_type_id', tt('fields.sourceType'), true)}>
                        {({ id }) => (
                            <Select id={id} value={form.data.source_type_id} onChange={(e) => form.setData('source_type_id', e.target.value)}>
                                <option value="">—</option>
                                {sourceTypes.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField {...field('title_en', tt('fields.titleEn'), true)}>
                        {({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('title_am', tt('fields.titleAm'))}>
                        {({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}
                    </FormField>
                    <FormField {...field('document_number', tt('fields.documentNumber'))}>
                        {({ id }) => <Input id={id} value={form.data.document_number} maxLength={100} onChange={(e) => form.setData('document_number', e.target.value)} />}
                    </FormField>
                    <FormField {...field('issuing_authority', tt('fields.issuingAuthority'))}>
                        {({ id }) => <Input id={id} value={form.data.issuing_authority} onChange={(e) => form.setData('issuing_authority', e.target.value)} />}
                    </FormField>
                    <FormField {...field('issue_date', tt('fields.issueDate'))}>
                        {({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.issue_date} onChange={(iso) => form.setData('issue_date', iso)} />}
                    </FormField>
                    <FormField {...field('source_url', tt('fields.sourceUrl'))}>
                        {({ id }) => <Input id={id} type="url" value={form.data.source_url} placeholder="https://" onChange={(e) => form.setData('source_url', e.target.value)} />}
                    </FormField>
                    <FormField {...field('effective_from', tt('fields.effectiveFrom'))}>
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
                <FieldError>{(form.errors as Record<string, string | undefined>).source}</FieldError>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
