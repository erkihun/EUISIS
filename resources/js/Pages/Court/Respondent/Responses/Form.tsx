import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { SendIcon } from '@/Components/Icons';
import { Alert, Button, Card, FormField, Input, buttonClassName } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentResponseFormProps } from '@/court/types/respondent';

type FormData = { title: string; case_number: string; description: string; pdf: File | null };

/**
 * One form for giving a response to a case (title, the case number from the case page, the main content and
 * the signed PDF, all required but the content) and for editing one (any field; a new PDF replaces the old).
 * The server checks the case is not closed, not the person's own and opened by them, and cleans the content.
 */
export default function RespondentResponseForm({ response, caseNumber }: RespondentResponseFormProps) {
    const { t, text } = useRespondentText();
    const editing = response !== null;
    const form = useForm<FormData>({
        title: response?.title ?? '',
        case_number: caseNumber,
        description: response?.description ?? '',
        pdf: null,
    });
    const title = editing ? t('cm.respondent.edit_response') : t('cm.respondent.create_response');
    const errors = Object.values(form.errors).filter(Boolean);
    const [noteBefore, noteAfter = ''] = text('response_pdf_note').split(':field');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (response) {
            // A multipart PATCH is sent as a POST with _method; a PDF left empty keeps the current one.
            form.transform(({ pdf, ...data }) => ({ ...data, ...(pdf ? { pdf } : {}), _method: 'patch' }));
            form.post(route('court.respondent.responses.update', response.id), { forceFormData: true, preserveScroll: true });
        } else {
            form.transform((data) => data);
            form.post(route('court.respondent.responses.store'), { forceFormData: true, preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout contentWidth="form" header={(
            <PageHeader title={title} description={editing ? t('cm.respondent.response_edit_description') : t('cm.respondent.response_intro')}
                backHref={editing ? route('court.respondent.responses.show', response.id) : route('court.respondent.responses.index')} />
        )}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-4" encType="multipart/form-data">
                {errors.length > 0 && (
                    <Alert tone="danger">
                        <div className="font-semibold">{t('cm.cases.please_fix_errors')}</div>
                        <ul className="mt-1 list-inside list-disc space-y-0.5">
                            {errors.map((message) => <li key={message}>{message}</li>)}
                        </ul>
                    </Alert>
                )}

                <Card className="space-y-5">
                    {!editing && (
                        <Alert tone="warning">
                            {noteBefore}<strong className="font-semibold">{t('cm.respondent.description_label')}</strong>{noteAfter}
                        </Alert>
                    )}

                    <FormField label={t('cm.respondent.response_title_label')} required error={form.errors.title}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={form.data.title} required maxLength={255} autoComplete="off" aria-describedby={describedBy} aria-invalid={invalid}
                                onChange={(event) => form.setData('title', event.target.value)} />
                        )}
                    </FormField>

                    <FormField label={t('cm.respondent.case_number_label')} required={!editing} error={form.errors.case_number}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} value={form.data.case_number} maxLength={64} aria-describedby={describedBy} aria-invalid={invalid}
                                required={!editing} readOnly={!editing} className={!editing ? 'cursor-not-allowed bg-(--app-surface-muted)' : undefined}
                                onChange={(event) => form.setData('case_number', event.target.value)} />
                        )}
                    </FormField>

                    <RichTextEditor label={t('cm.respondent.description_label')} value={form.data.description} preset="document" minHeight={420}
                        onChange={(html) => form.setData('description', html)} error={form.errors.description} />

                    <FormField label={editing ? t('cm.respondent.replace_pdf') : t('cm.respondent.response_pdf')} required={!editing} error={form.errors.pdf}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="file" accept="application/pdf" required={!editing} aria-describedby={describedBy} aria-invalid={invalid}
                                className="cursor-pointer py-1.5 file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium"
                                onChange={(event) => form.setData('pdf', event.target.files?.[0] ?? null)} />
                        )}
                    </FormField>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.respondent.responses.index')} className={buttonClassName({ variant: 'outline' })}>{t('common.cancel')}</Link>
                        <Button type="submit" loading={form.processing} icon={<SendIcon className="h-4 w-4" />}>{t('cm.respondent.submit_response')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
