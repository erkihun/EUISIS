import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, FormField, Input, Textarea, buttonClassName } from '@/Components/ui';
import type { LetterCategoryFormProps } from '@/court/types/letters';
import { useLetterText } from '@/Pages/Court/Letters/useLetterText';

/** One form for a new and an existing letter category: its name and an optional description. */
export default function LetterCategoryForm({ category }: LetterCategoryFormProps) {
    const { t, text } = useLetterText();
    const editing = category !== null;
    const title = editing ? text('edit_category_title') : text('new_category_title');
    const form = useForm({ name: category?.name ?? '', description: category?.description ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.patch(route('court.letter-categories.update', category.id));
        else form.post(route('court.letter-categories.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={editing ? text('edit_category_help') : text('create_category_help')} backHref={route('court.letter-categories.index')} />}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-3xl">
                <Card className="space-y-5">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{editing ? text('edit_category') : text('create_category')}</h2>
                    </div>
                    <FormField label={text('category_name')} required error={form.errors.name}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required maxLength={120} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <FormField label={text('description')} error={form.errors.description}>
                        {({ id, describedBy, invalid }) => <Textarea id={id} rows={2} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} maxLength={255} placeholder={text('description_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} />}
                    </FormField>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.letter-categories.index')} className={buttonClassName({ variant: 'outline' })}>{editing ? t('cm.letters.templates.actions.back') : t('cm.json.Cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{editing ? text('update_category') : text('save_category')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
