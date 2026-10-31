import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, Button, Card, FormField, Input, Select } from '@/Components/ui';
import type { PageProps } from '@/types';
import type { LetterComposeProps, LetterFormValues, LetterTemplateChoice } from '@/court/types/letters';
import LetterFields from './LetterFields';
import { submitLetterDocument } from './submitLetterDocument';
import { useLetterText } from './useLetterText';

type ComposeForm = LetterFormValues & { template_id: string };

const stripHtml = (html: string): string => html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

/**
 * Compose a letter: pick a template to fill the body, address it, choose who
 * is served, and save it (letters.store). The saved letter is a document page,
 * so it opens in a new tab and this page keeps the draft, as before.
 */
export default function LettersCompose({ templates, selectedTemplateId, caseNumber, nextReference, initial }: LetterComposeProps) {
    const { t, text } = useLetterText();
    const pageErrors = (usePage<PageProps<{ errors?: Record<string, string> }>>().props.errors ?? {}) as Partial<Record<keyof ComposeForm, string>>;
    const title = t('cm.letters.titles.compose');

    const arrival = templates.find((template) => template.id === selectedTemplateId) ?? null;
    const [selectedId, setSelectedId] = useState(arrival ? String(arrival.id) : '');
    const [loaded, setLoaded] = useState<LetterTemplateChoice | null>(arrival);
    const [notice, setNotice] = useState<string | null>(null);
    const [deliveryError, setDeliveryError] = useState<string | null>(null);
    const form = useForm<ComposeForm>({ ...initial, template_id: arrival ? String(arrival.id) : '' });

    const load = (id: string = selectedId) => {
        const template = templates.find((item) => String(item.id) === id) ?? null;
        setLoaded(template);
        if (!template) {
            form.setData('template_id', '');
            setNotice(null);
            return;
        }
        setNotice(text('template_loaded'));
        form.setData((current) => ({
            ...current,
            template_id: String(template.id),
            body: template.body,
            subject: current.subject.trim() === '' ? template.title : current.subject,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!loaded) return;
        if (!form.data.send_to_applicant && !form.data.send_to_respondent) {
            setDeliveryError(t('cm.letters.form.delivery_required'));
            return;
        }
        setDeliveryError(null);
        submitLetterDocument(route('court.letters.store'), 'POST', { ...form.data });
    };

    const errors: Partial<Record<keyof LetterFormValues, string>> = { ...pageErrors, ...form.errors, ...(deliveryError ? { send_to_applicant: deliveryError } : {}) };
    const excerpt = loaded ? stripHtml(loaded.body) : '';

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.letters.description.compose')} backHref={route('court.letters.index')} />}>
            <Head title={title} />

            <div className="mx-auto max-w-5xl space-y-6">
                <Card className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.letters.form.select_template')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{t('cm.letters.description.compose')}</p>
                    </div>
                    <div className="flex flex-col gap-3 md:flex-row md:items-end">
                        <FormField id="letter-template" label={t('cm.letters.form.template_label')} className="flex-1" error={pageErrors.template_id}>
                            {({ id }) => (
                                <Select id={id} value={selectedId} onChange={(event) => { setSelectedId(event.target.value); load(event.target.value); }} className="w-full">
                                    <option value="">{t('cm.letters.form.select_placeholder')}</option>
                                    {templates.map((template) => <option key={template.id} value={template.id}>{template.title}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <Button variant="outline" onClick={() => load()}>{t('cm.letters.actions.load')}</Button>
                    </div>
                    {notice && <p className="text-xs text-(--app-muted-foreground)" role="status">{notice}</p>}
                </Card>

                <Card>
                    <form onSubmit={submit} className="space-y-4">
                        {!loaded && <Alert tone="warning">{t('cm.letters.form.template_notice')}</Alert>}

                        <LetterFields values={form.data} onChange={(field, value) => form.setData((current) => ({ ...current, [field]: value }))} errors={errors} bodyHint={t('cm.letters.form.body_hint')}>
                            {(caseNumber || nextReference) && (
                                <FormField label={t('cm.letters.form.reference_number_auto')} description={t('cm.letters.form.reference_number_auto_help')}>
                                    {({ id }) => <Input id={id} value={nextReference ?? ''} readOnly className="w-full bg-(--app-surface-muted)" />}
                                </FormField>
                            )}

                            {loaded && loaded.placeholders.length > 0 && (
                                <div className="rounded-control border border-dashed border-primary/40 bg-primary/5 px-4 py-3 text-xs text-(--app-foreground)">
                                    <p className="mb-1 font-semibold">{t('cm.letters.form.placeholders_title')}</p>
                                    <p>{t('cm.letters.form.placeholders_help')}</p>
                                    <p className="mt-1 font-mono">{loaded.placeholders.join(', ')}</p>
                                </div>
                            )}

                            {loaded && (
                                <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) px-4 py-3 text-sm text-(--app-muted-foreground)">
                                    <p className="text-xs uppercase tracking-wide">{t('cm.letters.form.selected_template')}</p>
                                    <p className="font-semibold text-(--app-foreground)">{loaded.title}</p>
                                    <p className="text-xs">{loaded.category ?? t('cm.letters.form.category_fallback')}</p>
                                    {excerpt && <p className="mt-1 text-xs">{excerpt.length > 100 ? `${excerpt.slice(0, 100)}...` : excerpt}</p>}
                                </div>
                            )}
                        </LetterFields>

                        <div className="flex flex-wrap items-center justify-end gap-3 border-t border-(--app-border) pt-4">
                            <span className="text-xs text-(--app-muted-foreground)">{text('opens_in_new_tab')}</span>
                            <Button type="submit" variant="primary" disabled={!loaded}>{t('cm.letters.actions.save_letter')}</Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
