import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, FieldError, FormDescription, FormField, FormLabel, Textarea, buttonClassName } from '@/Components/ui';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { CaseRef, RespondentResponseSummary } from '@/court/types/applicantCases';
import { MetaTile } from '../../../Cases/Show/SectionCard';

/** The four tiles on top of every reply page: case, response number, applicant, respondent. */
export function ReplyContext({ kase, response, applicantName }: { kase: CaseRef; response: RespondentResponseSummary; applicantName: string }) {
    const { t } = useApplicantCaseText();
    const missing = t('cm.cases.labels.not_available');

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <MetaTile label={t('cm.cases.case_number')}>{kase.case_number}</MetaTile>
            <MetaTile label={t('cm.respondent.response_number_label')}>{response.response_number ?? missing}</MetaTile>
            <MetaTile label={t('cm.cases.applicant_full_name')}>{applicantName || missing}</MetaTile>
            <MetaTile label={t('cm.cases.respondent_defendant')}>{response.respondent_name ?? missing}</MetaTile>
        </div>
    );
}

/**
 * The reply form: the text and the PDF (required on a new reply, optional
 * when replacing one). A new reply posts; an edit posts with _method=patch so
 * the PDF travels as multipart.
 */
export function ReplyForm({ action, editing, initialDescription, cancelHref }: {
    action: string;
    editing: boolean;
    initialDescription: string;
    cancelHref: string;
}) {
    const { t } = useApplicantCaseText();
    const form = useForm<{ description: string; pdf: File | null }>({ description: initialDescription, pdf: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) {
            form.transform((data) => ({ ...data, _method: 'patch' }));
        }
        form.post(action, { forceFormData: true });
    };

    return (
        <Card>
            <form onSubmit={submit} className="space-y-5" encType="multipart/form-data">
                <FormField label={t('cm.respondent.response_of_response_description')} required error={form.errors.description}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} name="description" rows={8} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)}
                            required aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                    )}
                </FormField>

                <div className="space-y-1.5">
                    <FormLabel htmlFor="reply-pdf" required={!editing}>{t('cm.cases.attachment')}</FormLabel>
                    <FormDescription>{t('cm.respondent.response_reply_attachment_hint')}</FormDescription>
                    <input id="reply-pdf" type="file" name="pdf" accept="application/pdf" required={!editing}
                        onChange={(event) => form.setData('pdf', event.target.files?.[0] ?? null)}
                        className="block w-full text-sm text-(--app-muted-foreground) file:mr-4 file:rounded-control file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:font-semibold file:text-primary hover:file:bg-primary/15" />
                    <FieldError>{form.errors.pdf}</FieldError>
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-(--app-border) pt-4">
                    <Link href={cancelHref} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.buttons.cancel')}</Link>
                    <Button type="submit" variant="primary" loading={form.processing}>{editing ? t('cm.cases.buttons.update') : t('cm.cases.buttons.submit')}</Button>
                </div>
            </form>
        </Card>
    );
}
