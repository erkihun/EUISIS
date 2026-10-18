import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Option = { id: string; code: string; name_en: string; name_am: string | null };

export type StudyFormValues = {
    organization_id: string; title_en: string; title_am: string; study_type: string; reason: string; scope_notes: string;
    started_at: string; target_effective_date: string; hierarchy_version_id: string;
};

export const emptyStudy = (organizationId = ''): StudyFormValues => ({
    organization_id: organizationId, title_en: '', title_am: '', study_type: 'restructure', reason: '', scope_notes: '', started_at: '', target_effective_date: '', hierarchy_version_id: '',
});

/** A design study: new (POST, with organization) or draft edit (PUT). */
export default function StudyForm({ show, onClose, title, initial, url, method, organizations, studyTypes, hierarchyVersions }: {
    show: boolean; onClose: () => void; title: string; initial: StudyFormValues; url: string; method: 'post' | 'put';
    organizations?: Option[]; studyTypes: string[]; hierarchyVersions?: { id: string; version_name: string; status: string }[];
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<StudyFormValues>(initial);
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const out: Record<string, unknown> = Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v]));
            if (method === 'put') delete out.organization_id;
            return out;
        });
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (method === 'put') form.put(url, options);
        else form.post(url, options);
    }
    const field = (key: keyof StudyFormValues, label: string, required = false) => ({ id: `study-${key}`, label, required, error: errors[key] });

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {organizations && (
                        <FormField {...field('organization_id', tt('fields.organization'), true)} className="sm:col-span-2">
                            {({ id }) => <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}><option value="">—</option>{organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}</Select>}
                        </FormField>
                    )}
                    <FormField {...field('title_en', tt('fields.titleEn'), true)}>{({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}</FormField>
                    <FormField {...field('title_am', tt('fields.titleAm'))}>{({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}</FormField>
                    <FormField {...field('study_type', tt('fields.studyType'), true)}>
                        {({ id }) => <Select id={id} value={form.data.study_type} onChange={(e) => form.setData('study_type', e.target.value)}>{studyTypes.map((s) => <option key={s} value={s}>{tt(`studies.types.${s}`)}</option>)}</Select>}
                    </FormField>
                    {hierarchyVersions && (
                        <FormField {...field('hierarchy_version_id', tt('fields.hierarchyVersion'))}>
                            {({ id }) => <Select id={id} value={form.data.hierarchy_version_id} onChange={(e) => form.setData('hierarchy_version_id', e.target.value)}><option value="">—</option>{hierarchyVersions.map((v) => <option key={v.id} value={v.id}>{v.version_name} ({v.status})</option>)}</Select>}
                        </FormField>
                    )}
                    <FormField {...field('started_at', tt('fields.startedAt'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.started_at} onChange={(iso) => form.setData('started_at', iso)} />}</FormField>
                    <FormField {...field('target_effective_date', tt('fields.targetEffectiveDate'))}>{({ id }) => <LocalizedDatePicker id={id} className="w-full" value={form.data.target_effective_date} onChange={(iso) => form.setData('target_effective_date', iso)} />}</FormField>
                    <FormField {...field('reason', tt('fields.reason'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />}</FormField>
                    <FormField {...field('scope_notes', tt('fields.scopeNotes'))} className="sm:col-span-2">{({ id }) => <Textarea id={id} rows={2} value={form.data.scope_notes} onChange={(e) => form.setData('scope_notes', e.target.value)} />}</FormField>
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
