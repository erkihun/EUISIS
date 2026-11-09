import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, FormField, Input, Textarea, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Null on the create page. */
    term: { id: number; title: string; body: string; is_published: boolean } | null;
}

/** One form for a new and an existing terms-and-conditions text. */
export default function TermForm({ term }: Props) {
    const { t } = useLocale();
    const editing = term !== null;
    const form = useForm({ title: term?.title ?? '', body: term?.body ?? '', is_published: term?.is_published ?? false });
    const title = editing ? t('cm.terms.edit_heading') : t('cm.terms.create_heading');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const data = { ...form.data, is_published: form.data.is_published ? 1 : 0 };
        form.transform(() => data);
        if (editing) form.patch(route('court.terms.update', term.id));
        else form.post(route('court.terms.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.terms.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.terms.form_title_label')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required />}
                    </FormField>
                    <FormField label={t('cm.terms.form_body_label')} required error={form.errors.body}>
                        {({ id }) => <Textarea id={id} rows={12} value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} required />}
                    </FormField>
                    <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                        <input type="checkbox" checked={form.data.is_published} onChange={(event) => form.setData('is_published', event.target.checked)} className="h-4 w-4 rounded border-(--app-border)" />
                        {t('cm.terms.form_published_label')}
                    </label>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.terms.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.terms.form_cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.terms.form_update') : t('cm.terms.form_save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
