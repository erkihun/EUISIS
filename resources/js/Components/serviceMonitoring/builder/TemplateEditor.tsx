import { Alert, Button, Card, CardDescription, CardHeader, CardTitle, FieldError, FormField, Input, Textarea } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import FormBuilder, { type BuilderLimits } from './FormBuilder';
import { nameOf } from './helpers';
import type { FormSchema, Named } from './types';

export type BuiltInSection = '1' | '2' | '3' | '4';

export interface SectionSettings { enabled: boolean; title: string | null; notes: string[] | null }

/** Modules\ServiceMonitoring\Domain\Forms\TemplateContent */
export interface TemplateContentData {
    title: string | null;
    general_notes: string[] | null;
    sections: Record<BuiltInSection, SectionSettings>;
    custom: FormSchema;
}

/** The official wording a template starts from (shown as the placeholder of each text it may replace). */
export interface OfficialWording {
    title: string;
    general_notes: string[];
    sections: Record<BuiltInSection, { title: string; notes: string[] }>;
}

export interface EditableTemplate {
    id: string;
    code: string;
    version: number;
    name_am: string;
    name_en: string | null;
    description: string | null;
    content: TemplateContentData;
    organization_type_ids: string[];
}

export interface EditorProps {
    organizationTypes: Named[];
    official: OfficialWording;
    blank: TemplateContentData;
    limits: BuilderLimits;
}

type Data = { code: string; name_am: string; name_en: string; description: string; organization_type_ids: string[]; content: TemplateContentData };

const SECTIONS: BuiltInSection[] = ['1', '2', '3', '4'];

/** Notes are edited one per line; an empty box keeps the official notes. */
function notesFromText(text: string): string[] | null {
    const notes = text.split('\n').map((line) => line.trim()).filter(Boolean);
    return notes.length === 0 ? null : notes;
}

/** Merges a stored template over the blank one, so templates saved before a setting existed still edit. */
function normalize(content: TemplateContentData | undefined, blank: TemplateContentData): TemplateContentData {
    if (!content) return blank;
    return {
        title: content.title ?? null,
        general_notes: content.general_notes ?? null,
        sections: Object.fromEntries(SECTIONS.map((n) => [n, { ...blank.sections[n], ...(content.sections?.[n] ?? {}) }])) as Record<BuiltInSection, SectionSettings>,
        custom: content.custom?.sections ? content.custom : blank.custom,
    };
}

/**
 * Create and edit form of a form template (docs/service-monitoring.md §Form templates):
 * its name and organization types, the official sections it keeps and how it
 * words them, and its additional sections.
 */
export default function TemplateEditor({ template, organizationTypes, official, blank, limits }: EditorProps & { template?: EditableTemplate }): JSX.Element {
    const { t, locale } = useLocale();
    const tt = (key: string) => t(`serviceMonitoring.templates.${key}`);
    const editing = template !== undefined;
    const form = useForm<Data>({
        code: template?.code ?? '',
        name_am: template?.name_am ?? '',
        name_en: template?.name_en ?? '',
        description: template?.description ?? '',
        organization_type_ids: template?.organization_type_ids ?? [],
        content: normalize(template?.content, blank),
    });
    const { data, setData, errors: rawErrors, processing } = form;
    const errors = rawErrors as Record<string, string | undefined>;
    const content = data.content;

    const setContent = (next: Partial<TemplateContentData>) => setData('content', { ...content, ...next });
    const setSection = (n: BuiltInSection, next: Partial<SectionSettings>) => setContent({ sections: { ...content.sections, [n]: { ...content.sections[n], ...next } } });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (editing) form.put(route('service-monitoring.forms.update', template.id), { preserveScroll: true });
        else form.post(route('service-monitoring.forms.store'), { preserveScroll: true });
    }

    function toggleType(id: string, on: boolean) {
        setData('organization_type_ids', on ? [...data.organization_type_ids, id] : data.organization_type_ids.filter((existing) => existing !== id));
    }

    return (
        <form onSubmit={submit} className="space-y-6">
            {Object.keys(errors).length > 0 && <Alert tone="danger">{tt('errorsSummary')}</Alert>}

            <Card className="space-y-4 p-4">
                <CardHeader className="p-0"><CardTitle>{tt('details')}</CardTitle></CardHeader>
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField label={tt('code')} required={!editing} description={tt('codeHelp')} error={errors.code}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={64} value={data.code} disabled={editing} onChange={(e) => setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField label={tt('nameAm')} required error={errors.name_am}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_am} onChange={(e) => setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('nameEn')} error={errors.name_en}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('descriptionLabel')} error={errors.description} className="md:col-span-2">
                        {({ id }) => <Textarea id={id} className="w-full" maxLength={5000} value={data.description} onChange={(e) => setData('description', e.target.value)} />}
                    </FormField>
                </div>
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-(--app-foreground)">{tt('organizationTypes')}</legend>
                    <CardDescription>{tt('organizationTypesHelp')}</CardDescription>
                    <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                        {organizationTypes.map((type) => (
                            <label key={type.id} className="inline-flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="checkbox" className="h-4 w-4 accent-(--color-primary)" checked={data.organization_type_ids.includes(type.id)} onChange={(e) => toggleType(type.id, e.target.checked)} />
                                {nameOf(type, locale)}
                            </label>
                        ))}
                    </div>
                    {(errors.organization_type_ids || errors['organization_type_ids.0']) && <FieldError>{errors.organization_type_ids ?? errors['organization_type_ids.0']}</FieldError>}
                </fieldset>
            </Card>

            <Card className="space-y-4 p-4">
                <CardHeader className="p-0">
                    <CardTitle>{tt('wording')}</CardTitle>
                    <CardDescription>{tt('wordingHelp')}</CardDescription>
                </CardHeader>
                <FormField label={tt('formTitle')} error={errors['content.title']}>
                    {({ id }) => <Input id={id} className="w-full" maxLength={500} placeholder={official.title} value={content.title ?? ''} onChange={(e) => setContent({ title: e.target.value === '' ? null : e.target.value })} />}
                </FormField>
                <FormField label={tt('generalNotes')} description={tt('notesHelp')} error={errors['content.general_notes']}>
                    {({ id }) => <Textarea id={id} className="w-full" placeholder={official.general_notes.join('\n')} value={(content.general_notes ?? []).join('\n')}
                        onChange={(e) => setContent({ general_notes: notesFromText(e.target.value) })} />}
                </FormField>
            </Card>

            <Card className="space-y-4 p-4">
                <CardHeader className="p-0">
                    <CardTitle>{tt('officialSections')}</CardTitle>
                    <CardDescription>{tt('officialSectionsHelp')}</CardDescription>
                </CardHeader>
                {errors['content.sections'] && <FieldError>{errors['content.sections']}</FieldError>}
                {SECTIONS.map((n) => {
                    const section = content.sections[n];
                    return (
                        <div key={n} className="space-y-3 rounded-card border border-(--app-border) bg-(--app-surface-muted) p-3">
                            <label className="inline-flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                                <input type="checkbox" className="h-4 w-4 accent-(--color-primary)" checked={section.enabled} onChange={(e) => setSection(n, { enabled: e.target.checked })} />
                                {official.sections[n].title}
                            </label>
                            {section.enabled && (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <FormField label={tt('sectionTitle')} error={errors[`content.sections.${n}.title`]}>
                                        {({ id }) => <Input id={id} className="w-full" maxLength={500} placeholder={official.sections[n].title} value={section.title ?? ''} onChange={(e) => setSection(n, { title: e.target.value === '' ? null : e.target.value })} />}
                                    </FormField>
                                    <FormField label={tt('sectionNotes')} description={tt('notesHelp')} error={errors[`content.sections.${n}.notes`]}>
                                        {({ id }) => <Textarea id={id} className="w-full" placeholder={official.sections[n].notes.join('\n')} value={(section.notes ?? []).join('\n')}
                                            onChange={(e) => setSection(n, { notes: notesFromText(e.target.value) })} />}
                                    </FormField>
                                </div>
                            )}
                        </div>
                    );
                })}
            </Card>

            <div className="space-y-2">
                <div>
                    <h2 className="text-base font-semibold text-(--app-foreground)">{tt('customSections')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{tt('customSectionsHelp')}</p>
                </div>
                <FormBuilder schema={content.custom} onChange={(custom) => setContent({ custom })} errors={errors} limits={limits} errorPrefix="content.custom" />
            </div>

            <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                <Button as={Link} href={editing ? route('service-monitoring.forms.show', template.id) : route('service-monitoring.forms.index')} variant="outline">{tt('actions.cancel')}</Button>
                <Button type="submit" variant="primary" loading={processing}>{tt('actions.save')}</Button>
            </div>
        </form>
    );
}
