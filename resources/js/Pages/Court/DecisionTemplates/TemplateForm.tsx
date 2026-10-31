import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Button, Card, FileUpload, FormField, Input, Textarea, buttonClassName } from '@/Components/ui';
import type { DecisionTemplateFormProps } from '@/court/types/decisions';
import { useDecisionText } from '../Decisions/useDecisionText';

interface FormData {
    title: string;
    category: string;
    is_default: boolean;
    placeholders: string;
    body: string;
    header_image: File | null;
    footer_image: File | null;
    remove_header_image: boolean;
    remove_footer_image: boolean;
}

/** The lang string lists the tokens as `<code>{{token}}</code>`; render those as code, the rest as text. */
function CodeHelp({ value }: { value: string }) {
    const parts: ReactNode[] = value.split(/<code>(.*?)<\/code>/g).map((part, index) => (index % 2 === 1
        ? <code key={index} className="rounded bg-(--app-surface-muted) px-1 font-mono text-[11px] text-(--app-foreground)">{part}</code>
        : <span key={index}>{part}</span>));
    return <>{parts}</>;
}

/** The header or footer image of a template: the current file with its preview and remove box, and the upload. */
function ImageField({ label, current, currentName, currentUrl, removeLabel, previewAlt, file, removing, error, onFile, onRemove }: {
    label: string; current: string; currentName: string | null; currentUrl: string | null; removeLabel: string; previewAlt: string;
    file: File | null; removing: boolean; error?: string; onFile: (file: File | null) => void; onRemove: (remove: boolean) => void;
}) {
    return (
        <div className="space-y-2">
            <FileUpload label={label} value={file} accept="image/*" maxSizeMb={3} error={error} onChange={onFile} onRemove={() => onFile(null)} />
            {currentUrl && currentName && (
                <div className="space-y-1">
                    <p className="text-xs text-(--app-muted-foreground)">{current}</p>
                    <img src={currentUrl} alt={previewAlt} className="h-20 rounded border border-(--app-border)" />
                    <label className="flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                        <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={removing} onChange={(event) => onRemove(event.target.checked)} />
                        {removeLabel}
                    </label>
                </div>
            )}
        </div>
    );
}

/**
 * One form for a new and an existing decision template: title, category, the
 * default flag, the placeholder tokens, the header and footer images and the
 * rich-text body. Posts multipart to decision-templates.store, or to
 * decision-templates.update with the PATCH method spoofed.
 */
export default function TemplateForm({ template }: DecisionTemplateFormProps) {
    const { t, text, fill } = useDecisionText();
    const editing = template !== null;
    const title = editing ? t('cm.decision_templates.edit') : t('cm.decision_templates.new');
    const form = useForm<FormData>({
        title: template?.title ?? '',
        category: template?.category ?? '',
        is_default: template?.is_default ?? false,
        placeholders: template?.placeholders ?? '',
        body: template?.body ?? '',
        header_image: null,
        footer_image: null,
        remove_header_image: false,
        remove_footer_image: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            is_default: data.is_default ? 1 : 0,
            remove_header_image: data.remove_header_image ? 1 : 0,
            remove_footer_image: data.remove_footer_image ? 1 : 0,
            ...(editing ? { _method: 'PATCH' } : {}),
        }));
        // Files travel as multipart; the update spoofs PATCH over POST, as the Blade form did.
        form.post(editing ? route('court.decision-templates.update', template.id) : route('court.decision-templates.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={editing ? t('cm.decision_templates.edit_help') : t('cm.decision_templates.create_help')} backHref={route('court.decision-templates.index')} />
        )}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-4xl">
                <Card className="space-y-6">
                    <h2 className="text-lg font-semibold text-(--app-foreground)">{editing ? t('cm.decision_templates.edit') : t('cm.decision_templates.create')}</h2>

                    <FormField id="template-title" label={t('cm.decision_templates.form.title')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required className="w-full" />}
                    </FormField>
                    <FormField id="template-category" label={t('cm.decision_templates.form.category')} error={form.errors.category}>
                        {({ id }) => <Input id={id} value={form.data.category} onChange={(event) => form.setData('category', event.target.value)} placeholder={t('cm.decision_templates.form.category_placeholder')} className="w-full" />}
                    </FormField>

                    <div>
                        <label className="flex items-center gap-2 text-sm font-medium text-(--app-foreground)">
                            <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={form.data.is_default} onChange={(event) => form.setData('is_default', event.target.checked)} />
                            {t('cm.decision_templates.form.is_default')}
                        </label>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.decision_templates.form.is_default_help')}</p>
                    </div>

                    <FormField id="template-placeholders" label={t('cm.decision_templates.form.placeholders')} error={form.errors.placeholders}
                        description={(
                            <>
                                <span className="block">{t('cm.decision_templates.form.placeholders_example')}</span>
                                <span className="block italic"><CodeHelp value={t('cm.decision_templates.form.placeholders_help')} /></span>
                            </>
                        )}>
                        {({ id }) => (
                            <Textarea id={id} rows={2} value={form.data.placeholders} onChange={(event) => form.setData('placeholders', event.target.value)}
                                placeholder={t('cm.decision_templates.form.placeholders_placeholder')} className="w-full" />
                        )}
                    </FormField>

                    <div className="grid gap-4 md:grid-cols-2">
                        <ImageField
                            label={t('cm.decision_templates.form.header_image')}
                            current={fill(t('cm.decision_templates.form.header_current'), { file: template?.header_image_name ?? '' })}
                            currentName={template?.header_image_name ?? null}
                            currentUrl={template?.header_image_url ?? null}
                            removeLabel={t('cm.decision_templates.form.remove_header')}
                            previewAlt={text('header_preview')}
                            file={form.data.header_image}
                            removing={form.data.remove_header_image}
                            error={form.errors.header_image}
                            onFile={(file) => form.setData('header_image', file)}
                            onRemove={(remove) => form.setData('remove_header_image', remove)}
                        />
                        <ImageField
                            label={t('cm.decision_templates.form.footer_image')}
                            current={fill(t('cm.decision_templates.form.footer_current'), { file: template?.footer_image_name ?? '' })}
                            currentName={template?.footer_image_name ?? null}
                            currentUrl={template?.footer_image_url ?? null}
                            removeLabel={t('cm.decision_templates.form.remove_footer')}
                            previewAlt={text('footer_preview')}
                            file={form.data.footer_image}
                            removing={form.data.remove_footer_image}
                            error={form.errors.footer_image}
                            onFile={(file) => form.setData('footer_image', file)}
                            onRemove={(remove) => form.setData('remove_footer_image', remove)}
                        />
                    </div>

                    <RichTextEditor
                        label={t('cm.decision_templates.form.body')}
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        error={form.errors.body}
                        helperText={t('cm.decision_templates.form.body_help')}
                        placeholder={t('cm.decision_templates.form.body_placeholder')}
                        preset="document"
                        minHeight={400}
                        required
                    />

                    <div className="flex items-center justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.decision-templates.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.decision_templates.actions.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{t('cm.decision_templates.actions.save_template')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
