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

type Data = { name_am: string; name_en: string; description: string; organization_type_ids: string[]; content: TemplateContentData };

const SECTIONS: BuiltInSection[] = ['1', '2', '3', '4'];

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
        <form onSubmit={submit} className="space-y-5">
            {Object.keys(errors).length > 0 && <Alert tone="danger">{tt('errorsSummary')}</Alert>}

            <section className="border-s-4 border-(--color-primary) bg-(--app-surface-muted) px-4 py-3">
                <p className="text-sm font-semibold text-(--app-foreground)">{tt('digitalFirstTitle')}</p>
                <p className="mt-1 max-w-3xl text-sm text-(--app-muted-foreground)">{tt('digitalFirstHelp')}</p>
            </section>

            <Card className="p-5">
                <CardHeader className="mb-4 p-0">
                    <CardTitle>{tt('details')}</CardTitle>
                    <CardDescription>{tt('detailsHelp')}</CardDescription>
                </CardHeader>
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField label={tt('nameAm')} required error={errors.name_am}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_am} onChange={(e) => setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('nameEn')} error={errors.name_en}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField label={tt('descriptionLabel')} description={tt('descriptionHelp')} error={errors.description} className="md:col-span-2">
                        {({ id }) => <Textarea id={id} className="w-full" rows={2} maxLength={5000} value={data.description} onChange={(e) => setData('description', e.target.value)} />}
                    </FormField>
                </div>
            </Card>

            <Card className="p-5">
                <CardHeader className="mb-4 p-0">
                    <CardTitle>{tt('organizationTypes')}</CardTitle>
                    <CardDescription>{tt('organizationTypesHelp')}</CardDescription>
                </CardHeader>
                <fieldset>
                    <legend className="sr-only">{tt('organizationTypes')}</legend>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {organizationTypes.map((type) => {
                            const selected = data.organization_type_ids.includes(type.id);
                            return (
                                <label key={type.id} className={`flex cursor-pointer items-center gap-3 border px-3 py-2.5 text-sm transition-colors ${selected ? 'border-(--color-primary) bg-(--app-surface-muted) font-medium' : 'border-(--app-border) hover:border-(--app-border-strong)'}`}>
                                    <input type="checkbox" className="h-4 w-4 accent-(--color-primary)" checked={selected} onChange={(e) => toggleType(type.id, e.target.checked)} />
                                    <span>{nameOf(type, locale)}</span>
                                </label>
                            );
                        })}
                    </div>
                    {(errors.organization_type_ids || errors['organization_type_ids.0']) && <FieldError>{errors.organization_type_ids ?? errors['organization_type_ids.0']}</FieldError>}
                </fieldset>
            </Card>

            <Card className="p-5">
                <CardHeader className="mb-4 p-0">
                    <CardTitle>{tt('officialSections')}</CardTitle>
                    <CardDescription>{tt('officialSectionsSimpleHelp')}</CardDescription>
                </CardHeader>
                {errors['content.sections'] && <FieldError>{errors['content.sections']}</FieldError>}
                <div className="divide-y divide-(--app-border) border-y border-(--app-border)">
                    {SECTIONS.map((n) => {
                        const section = content.sections[n];
                        return (
                            <div key={n} className="grid gap-3 py-4 sm:grid-cols-[auto_1fr]">
                                <input id={`official-section-${n}`} type="checkbox" className="mt-1 h-4 w-4 accent-(--color-primary)" checked={section.enabled} onChange={(e) => setSection(n, { enabled: e.target.checked })} />
                                <div className="min-w-0">
                                    <label htmlFor={`official-section-${n}`} className="cursor-pointer text-sm font-semibold text-(--app-foreground)">{official.sections[n].title}</label>
                                    <p className="text-xs text-(--app-muted-foreground)">{section.enabled ? tt('includedAutomatically') : tt('notCollected')}</p>
                                    {section.enabled && (
                                        <div className="mt-3 max-w-2xl">
                                            <FormField label={tt('sectionName')} description={tt('sectionNameHelp')} error={errors[`content.sections.${n}.title`]}>
                                                {({ id }) => <Input id={id} className="w-full" maxLength={500} placeholder={official.sections[n].title} value={section.title ?? ''} onChange={(e) => setSection(n, { title: e.target.value === '' ? null : e.target.value })} />}
                                            </FormField>
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </Card>

            <details className="border border-(--app-border) bg-(--app-surface)" open={content.custom.sections.length > 0 || undefined}>
                <summary className="cursor-pointer list-none px-5 py-4 marker:hidden">
                    <span className="block text-sm font-semibold text-(--app-foreground)">{tt('optionalQuestions')}</span>
                    <span className="mt-1 block text-sm text-(--app-muted-foreground)">{tt('optionalQuestionsHelp')}</span>
                </summary>
                <div className="border-t border-(--app-border) p-5">
                    <FormBuilder schema={content.custom} onChange={(custom) => setContent({ custom })} errors={errors} limits={limits} errorPrefix="content.custom" />
                </div>
            </details>

            <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 border-t border-(--app-border) bg-(--app-background)/95 py-3 backdrop-blur">
                <p className="text-xs text-(--app-muted-foreground)">{tt('automaticCode')}</p>
                <div className="flex gap-2">
                    <Button as={Link} href={editing ? route('service-monitoring.forms.show', template.id) : route('service-monitoring.forms.index')} variant="outline">{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={processing}>{tt('actions.save')}</Button>
                </div>
            </div>
        </form>
    );
}
