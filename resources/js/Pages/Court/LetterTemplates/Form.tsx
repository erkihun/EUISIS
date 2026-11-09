import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Button, Card, FileUpload, FormField, Input, Textarea, buttonClassName } from '@/Components/ui';
import type { LetterTemplateFormProps } from '@/court/types/letters';
import { useLetterText } from '@/Pages/Court/Letters/useLetterText';

type TemplateForm = {
    title: string;
    category: string;
    placeholders: string;
    body: string;
    header_image: File | null;
    footer_image: File | null;
    remove_header_image: boolean;
    remove_footer_image: boolean;
};

/** The tags the template names, as the server splits them: by comma or new line. */
const parsePlaceholders = (raw: string): string[] => Array.from(new Set(raw.split(/[\r\n,]+/).map((tag) => tag.trim()).filter(Boolean)));

/**
 * One form for a new and an existing letter template: title, category,
 * placeholder tags, header and footer images, and the body with its tags.
 */
export default function LetterTemplateForm({ template, categories }: LetterTemplateFormProps) {
    const { t, text, fill } = useLetterText();
    const editing = template !== null;
    const title = editing ? t('cm.letters.templates.edit') : t('cm.letters.templates.new');
    const form = useForm<TemplateForm>({
        title: template?.title ?? '',
        category: template?.category ?? '',
        placeholders: template?.placeholders ?? '',
        body: template?.body ?? '',
        header_image: null,
        footer_image: null,
        remove_header_image: false,
        remove_footer_image: false,
    });
    const tags = useMemo(() => parsePlaceholders(form.data.placeholders), [form.data.placeholders]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // The images travel as multipart; PATCH is spoofed the way Laravel reads it from a POST.
        if (editing) {
            form.transform((data) => ({ ...data, _method: 'PATCH' }));
            form.post(route('court.letter-templates.update', template.id), { forceFormData: true });
        } else {
            form.post(route('court.letter-templates.store'), { forceFormData: true });
        }
    };

    const imageField = (side: 'header' | 'footer') => {
        const fileKey = `${side}_image` as const;
        const removeKey = `remove_${side}_image` as const;
        const currentUrl = template?.[`${side}_image_url`] ?? null;
        const currentName = template?.[`${side}_image_name`] ?? null;
        return (
            <div className="space-y-2">
                <FileUpload
                    label={t(`cm.letters.templates.form.${side}_image`)}
                    accept="image/*"
                    maxSizeMb={3}
                    value={form.data[fileKey]}
                    error={form.errors[fileKey]}
                    onChange={(file) => form.setData(fileKey, file)}
                />
                {currentUrl && (
                    <div className="space-y-1">
                        {currentName && <p className="text-xs text-(--app-muted-foreground)">{fill(t(`cm.letters.templates.form.${side}_current`), { file: currentName })}</p>}
                        <img src={currentUrl} alt={text(`${side}_preview_alt`)} className="h-20 rounded-control border border-(--app-border) object-contain" />
                        <label className="flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                            <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={form.data[removeKey]} onChange={(event) => form.setData(removeKey, event.target.checked)} />
                            {t(`cm.letters.templates.form.remove_${side}`)}
                        </label>
                    </div>
                )}
            </div>
        );
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={editing ? t('cm.letters.templates.edit_help') : t('cm.letters.templates.create_help')} backHref={route('court.letter-templates.index')} />}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-4xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.letters.templates.form.title')} required error={form.errors.title}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required maxLength={255} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>

                    <FormField label={t('cm.letters.templates.form.category')} description={categories.length === 0 ? text('no_categories_create_first') : undefined} error={form.errors.category}>
                        {({ id, describedBy, invalid }) => (
                            <>
                                <Input id={id} list={`${id}-options`} value={form.data.category} onChange={(event) => form.setData('category', event.target.value)} maxLength={120} placeholder={text('category_select_or_type')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                                <datalist id={`${id}-options`}>
                                    {categories.map((name) => <option key={name} value={name} />)}
                                </datalist>
                            </>
                        )}
                    </FormField>

                    <FormField label={t('cm.letters.templates.form.placeholders')} error={form.errors.placeholders} description={(
                        <>
                            <span className="block">{t('cm.letters.templates.form.placeholders_example')}</span>
                            <span className="block italic">{t('cm.letters.templates.form.placeholders_help')}</span>
                        </>
                    )}>
                        {({ id, describedBy, invalid }) => (
                            <div className="space-y-2">
                                <Textarea id={id} rows={2} value={form.data.placeholders} onChange={(event) => form.setData('placeholders', event.target.value)} maxLength={2000} placeholder={t('cm.letters.templates.form.placeholders_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="font-mono text-sm" />
                                {tags.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-1.5" aria-label={text('placeholder_tags')}>
                                        <span className="text-xs text-(--app-muted-foreground)">{text('placeholder_tags')}:</span>
                                        {tags.map((tag) => (
                                            <button key={tag} type="button" onClick={() => form.setData('body', `${form.data.body}${form.data.body ? ' ' : ''}${tag}`)} className="rounded-full border border-(--app-border) bg-(--app-surface-muted) px-2 py-0.5 font-mono text-xs text-(--app-foreground) hover:border-primary">{tag}</button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </FormField>

                    <div className="grid gap-4 md:grid-cols-2">
                        {imageField('header')}
                        {imageField('footer')}
                    </div>

                    <RichTextEditor
                        label={t('cm.letters.templates.form.body')}
                        required
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        error={form.errors.body}
                        helperText={t('cm.letters.templates.form.body_help')}
                        placeholder={t('cm.letters.templates.form.body_placeholder')}
                        preset="document"
                        minHeight={400}
                    />

                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.letter-templates.index')} className={buttonClassName({ variant: 'outline' })}>{editing ? t('cm.letters.templates.actions.back') : t('cm.letters.templates.actions.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{editing ? t('cm.letters.templates.actions.update_template') : t('cm.letters.templates.actions.save_template')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
