import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, Button, Card, buttonClassName } from '@/Components/ui';
import type { PageProps } from '@/types';
import type { LetterEditProps, LetterFormValues } from '@/court/types/letters';
import LetterFields from './LetterFields';
import { submitLetterDocument } from './submitLetterDocument';
import { useLetterText } from './useLetterText';

/**
 * Edit a generated letter: the template it came from and its references, then
 * the recipient, subject and body (letters.update). The updated letter is a
 * document page, so it opens in a new tab, as before.
 */
export default function LettersEdit({ letter, template }: LetterEditProps) {
    const { t, text } = useLetterText();
    const pageErrors = (usePage<PageProps<{ errors?: Record<string, string> }>>().props.errors ?? {}) as Partial<Record<keyof LetterFormValues, string>>;
    const title = t('cm.letters.titles.edit');
    const locked = letter.approval_status === 'approved';
    const [deliveryError, setDeliveryError] = useState<string | null>(null);
    const form = useForm<LetterFormValues>({
        recipient_name: letter.recipient_name, recipient_title: letter.recipient_title, recipient_company: letter.recipient_company,
        case_number: letter.case_number, cc: letter.cc, send_to_applicant: letter.send_to_applicant, send_to_respondent: letter.send_to_respondent,
        subject: letter.subject, body: letter.body,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (locked) return;
        if (!form.data.send_to_applicant && !form.data.send_to_respondent) {
            setDeliveryError(t('cm.letters.form.delivery_required'));
            return;
        }
        setDeliveryError(null);
        submitLetterDocument(route('court.letters.update', letter.id), 'PATCH', { ...form.data });
    };

    const errors: Partial<Record<keyof LetterFormValues, string>> = { ...pageErrors, ...form.errors, ...(deliveryError ? { send_to_applicant: deliveryError } : {}) };
    const missing = t('cm.letters.cards.missing');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.letters.description.edit')} backHref={route('court.letters.index')} />}>
            <Head title={title} />

            <div className="mx-auto max-w-4xl space-y-6">
                <Card>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm text-(--app-muted-foreground)">
                            <p className="text-xs uppercase tracking-wide">{t('cm.letters.form.template_label')}</p>
                            <p className="font-semibold text-(--app-foreground)">{template.title}</p>
                            <dl className="mt-2 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                                <div><dt className="inline">{t('cm.letters.table.reference')}: </dt><dd className="inline font-mono text-(--app-foreground)">{letter.reference_number ?? missing}</dd></div>
                                <div><dt className="inline">{t('cm.letters.form.subject_prefix_label')}: </dt><dd className="inline text-(--app-foreground)">{template.subject_prefix ?? missing}</dd></div>
                                <div><dt className="inline">{t('cm.letters.table.reference_next')}: </dt><dd className="inline font-mono text-(--app-foreground)">{template.next_reference}</dd></div>
                            </dl>
                        </div>

                        {locked && <Alert tone="warning">{t('cm.letters.messages.approved_edit_locked')}</Alert>}

                        <LetterFields values={form.data} onChange={(field, value) => form.setData((current) => ({ ...current, [field]: value }))} errors={errors} caseNumberReadOnly bodyHint={t('cm.letters.form.body_preview_hint')} />

                        <div className="flex flex-wrap items-center justify-end gap-3 border-t border-(--app-border) pt-4">
                            <span className="text-xs text-(--app-muted-foreground)">{text('opens_in_new_tab')}</span>
                            <Link href={route('court.letters.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.letters.actions.back')}</Link>
                            <Button type="submit" variant="primary" disabled={locked}>{t('cm.letters.actions.update_letter')}</Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
