import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Button, Card, FormField, Input, Select, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Null on the create page. */
    announcement: { id: number; title: string; content: string; status: string } | null;
}

/** One form for a new and an existing announcement: title, rich-text content, state. */
export default function AnnouncementForm({ announcement }: Props) {
    const { t } = useLocale();
    const editing = announcement !== null;
    const form = useForm({ title: announcement?.title ?? '', content: announcement?.content ?? '', status: announcement?.status ?? 'active' });
    const title = editing ? t('cm.announcements.edit_heading') : t('cm.announcements.create_heading');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.patch(route('court.announcements.update', announcement.id));
        else form.post(route('court.announcements.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={editing ? t('cm.announcements.edit_description') : t('cm.announcements.create_description')} backHref={route('court.announcements.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.announcements.form_title_label')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required />}
                    </FormField>
                    <RichTextEditor label={t('cm.announcements.form_content_label')} value={form.data.content} onChange={(html) => form.setData('content', html)} error={form.errors.content} required />
                    <FormField label={t('cm.announcements.form_status_label')} required error={form.errors.status}>
                        {({ id }) => (
                            <Select id={id} value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                <option value="active">{t('cm.announcements.status_active')}</option>
                                <option value="inactive">{t('cm.announcements.status_inactive')}</option>
                            </Select>
                        )}
                    </FormField>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.announcements.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.announcements.cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.announcements.form_update') : t('cm.announcements.form_submit')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
