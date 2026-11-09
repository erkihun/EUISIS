import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Button, Card, FormField, Input, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Null on the create page. */
    page: { id: number; title: string; slug: string; body: string; is_published: boolean } | null;
}

/** One form for a new and an existing About page: title, slug, body, published. */
export default function AboutForm({ page }: Props) {
    const { t } = useLocale();
    const editing = page !== null;
    const form = useForm({ title: page?.title ?? '', slug: page?.slug ?? '', body: page?.body ?? '', is_published: page?.is_published ?? false });
    const title = editing ? t('cm.about.edit_heading') : t('cm.about.create_heading');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const data = { ...form.data, slug: form.data.slug || null, is_published: form.data.is_published ? 1 : 0 };
        form.transform(() => data);
        if (editing) form.patch(route('court.about.update', page.id));
        else form.post(route('court.about.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.about.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.about.form_title_label')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required />}
                    </FormField>
                    <FormField label={t('cm.about.form_slug_label')} error={form.errors.slug}>
                        {({ id }) => <Input id={id} value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value)} />}
                    </FormField>
                    {/* The body is HTML (the applicant sign-in page shows it as such), purified on save. */}
                    <RichTextEditor label={t('cm.about.form_body_label')} value={form.data.body} onChange={(html) => form.setData('body', html)} error={form.errors.body} required />
                    <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                        <input type="checkbox" checked={form.data.is_published} onChange={(event) => form.setData('is_published', event.target.checked)} className="h-4 w-4 rounded border-(--app-border)" />
                        {t('cm.about.form_published_label')}
                    </label>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.about.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.about.form_cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.about.form_update') : t('cm.about.form_save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
