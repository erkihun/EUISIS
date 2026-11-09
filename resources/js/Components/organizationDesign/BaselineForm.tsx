import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type BaselineFormValues = {
    organization_id: string;
    title_en: string;
    title_am: string;
    description: string;
    origin: string;
    effective_date: string;
    hierarchy_version_id: string;
    legal_source_id: string;
};

export type HierarchyVersionOption = { id: string; name: string; status: string; effective_from: string | null };
export type LegalSourceOption = { id: string; code: string; title_en: string; title_am: string | null };

export const emptyBaseline = (organizationId = ''): BaselineFormValues => ({
    organization_id: organizationId, title_en: '', title_am: '', description: '', origin: 'manual', effective_date: '', hierarchy_version_id: '', legal_source_id: '',
});

/**
 * Opening and editing a legacy baseline: its name, the date it describes,
 * where the records came from, and the hierarchy version and primary legal
 * source it rests on. The organization is chosen only when opening one.
 */
export default function BaselineForm({ show, onClose, title, initial, url, method, organizations, hierarchyVersions = [], legalSources = [], origins }: {
    show: boolean;
    onClose: () => void;
    title: string;
    initial: BaselineFormValues;
    url: string;
    method: 'post' | 'put';
    organizations?: { id: string; code: string; name_en: string; name_am: string | null }[];
    hierarchyVersions?: HierarchyVersionOption[];
    legalSources?: LegalSourceOption[];
    origins: string[];
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<BaselineFormValues>(initial);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }

    const field = (key: keyof BaselineFormValues, label: string, required = false, description?: string) => ({ id: `baseline-${key}`, label, required, description, error: form.errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.formHelp')}</p>

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
                    <FormField {...field('title_en', tt('fields.titleEn'), true)}>
                        {({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}
                    </FormField>
                    <FormField {...field('title_am', tt('fields.titleAm'))}>
                        {({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}
                    </FormField>
                    <FormField {...field('effective_date', tt('legacyBaselines.effectiveDate'), false, tt('legacyBaselines.effectiveDateHelp'))}>
                        {({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.effective_date} onChange={(iso) => form.setData('effective_date', iso)} />}
                    </FormField>
                    <FormField {...field('origin', tt('fields.origin'))}>
                        {({ id }) => (
                            <Select id={id} value={form.data.origin} onChange={(e) => form.setData('origin', e.target.value)}>
                                {origins.map((o) => <option key={o} value={o}>{tt(`legacyBaselines.origin.${o}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    {hierarchyVersions.length > 0 && (
                        <FormField {...field('hierarchy_version_id', tt('legacyBaselines.hierarchyVersion'))}>
                            {({ id }) => (
                                <Select id={id} value={form.data.hierarchy_version_id} onChange={(e) => form.setData('hierarchy_version_id', e.target.value)}>
                                    <option value="">—</option>
                                    {hierarchyVersions.map((v) => <option key={v.id} value={v.id}>{v.name} · {v.status}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    {legalSources.length > 0 && (
                        <FormField {...field('legal_source_id', tt('legacyBaselines.legalSource'))}>
                            {({ id }) => (
                                <Select id={id} value={form.data.legal_source_id} onChange={(e) => form.setData('legal_source_id', e.target.value)}>
                                    <option value="">—</option>
                                    {legalSources.map((s) => <option key={s.id} value={s.id}>{s.code} · {locale === 'am' && s.title_am ? s.title_am : s.title_en}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField {...field('description', tt('fields.description'))} className="sm:col-span-2">
                        {({ id }) => <Textarea id={id} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />}
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
