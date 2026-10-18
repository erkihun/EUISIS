import PublicLayout from '@/Layouts/PublicLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, useForm } from '@inertiajs/react';
import { Alert, Button, FieldError, FileUpload, FormField, Textarea } from '@euisis/ui';
import MultiFileUpload from '@/Components/public/MultiFileUpload';
import { PublicContainer, PublicPageHeader, publicButtonSecondary } from '@/Components/public/PublicPage';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent, useState } from 'react';
import type { PageProps } from '@/types';

type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    closing_date: string | null;
    /** Settings-level documents first, then the announcement's own — the server's ordinal order. */
    required_documents: string[] | null;
};

interface Props extends PageProps {
    announcement: Announcement;
    show_url: string;
}

const ACCEPT = '.pdf,.jpg,.jpeg,.png';

/**
 * Apply for a transfer.
 *
 * The server matches uploads to required documents by position — the first
 * file is the first required document, and so on — and refuses the
 * application when one is missing. So when documents are required, the form
 * offers one labelled slot per document instead of a free pile of files that
 * the applicant would have to order by guesswork. Free uploads remain for an
 * announcement that requires nothing specific.
 */
export default function TransferAnnouncementApply({ announcement: a, show_url }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';
    const required = a.required_documents ?? [];

    const orgName = (useAmharic ? a.organization_name_am : null) ?? a.organization_name_en ?? '—';
    const posTitle = (useAmharic ? a.position_title_am : null) ?? a.position_title_en ?? '—';

    const form = useForm<{ cover_letter: string; documents: Array<File | null> }>({
        cover_letter: '',
        documents: required.map(() => null),
    });
    const [missing, setMissing] = useState<number[]>([]);

    function setSlot(index: number, file: File | null) {
        const next = form.data.documents.slice();
        next[index] = file;
        form.setData('documents', next);

        if (file) setMissing((current) => current.filter((i) => i !== index));
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (required.length > 0) {
            const empty = required.map((_, i) => i).filter((i) => !form.data.documents[i]);
            setMissing(empty);

            if (empty.length > 0) {
                document.getElementById(`document-${empty[0]}`)?.focus();

                return;
            }
        }

        const fd = new FormData();
        fd.append('cover_letter', form.data.cover_letter);
        form.data.documents.forEach((file, index) => {
            if (!file) return;
            // documents[i] keeps the slot's ordinal even if an earlier slot is
            // empty; documents[] is the free pile when nothing is required.
            fd.append(required.length > 0 ? `documents[${index}]` : 'documents[]', file);
        });

        form.transform(() => fd as any);
        form.post(route('public.transfer-announcements.apply.store', { announcement: a.id }));
    }

    const pageTitle = `${t('transfers.applyForTransfer')} — ${posTitle}`;
    const errors = form.errors as Record<string, string | undefined>;
    const applicationError = errors.application;
    const slotError = (index: number): string | undefined =>
        missing.includes(index) ? t('transfers.documentRequired') : errors[`documents.${index}`];
    const generalDocumentError = errors.documents;

    return (
        <PublicLayout title={pageTitle} noindex>
            <PublicPageHeader
                title={t('transfers.applyForTransfer')}
                breadcrumbs={[
                    { label: t('nav.announcements'), href: route('public.announcements') },
                    { label: posTitle, href: show_url },
                    { label: t('transfers.applyForTransfer') },
                ]}
                meta={
                    <span className="flex flex-wrap gap-x-4 gap-y-1">
                        {/* On the navy band: white, not the body-text gray that vanished into it. */}
                        <span className="font-medium text-white">{posTitle}</span>
                        <span>{orgName}</span>
                        {a.grade_level && <span>{t('transfers.gradeLevel')}: {a.grade_level}</span>}
                        {a.closing_date && (
                            <span>{t('transfers.closes')}: <LocalizedDateDisplay value={a.closing_date} /></span>
                        )}
                    </span>
                }
            />

            <div className="bg-gray-50 py-12 sm:py-16 dark:bg-slate-900">
                <PublicContainer narrow className="space-y-6 py-8">
                    <form onSubmit={submit} className="space-y-6 rounded-panel border border-gray-200 bg-white p-5 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
                        {applicationError && <Alert tone="danger">{applicationError}</Alert>}

                        <FormField
                            id="cover-letter"
                            label={<>{t('transfers.coverLetter')} <span className="font-normal text-gray-500 dark:text-slate-400">({t('common.optional')})</span></>}
                            error={form.errors.cover_letter}
                            description={`${form.data.cover_letter.length} / 3000`}
                        >
                            {({ id, describedBy, invalid }) => (
                                <Textarea
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid}
                                    className="min-h-40 resize-y"
                                    placeholder={t('transfers.coverLetterPlaceholder')}
                                    value={form.data.cover_letter}
                                    onChange={(e) => form.setData('cover_letter', e.target.value)}
                                    maxLength={3000}
                                />
                            )}
                        </FormField>

                        {required.length > 0 ? (
                            <fieldset className="space-y-4">
                                <legend className="mb-1 text-sm font-medium text-gray-900 dark:text-slate-100">
                                    {t('transfers.requiredDocuments')}{' '}
                                    <span className="font-normal text-gray-500 dark:text-slate-400">({t('transfers.documentsRequiredHint')})</span>
                                </legend>
                                {required.map((type, index) => (
                                    <FileUpload
                                        key={`${type}-${index}`}
                                        id={`document-${index}`}
                                        label={type}
                                        accept={ACCEPT}
                                        value={form.data.documents[index] ?? null}
                                        error={slotError(index)}
                                        onChange={(file) => setSlot(index, file)}
                                        onRemove={() => setSlot(index, null)}
                                    />
                                ))}
                                <FieldError>{generalDocumentError}</FieldError>
                            </fieldset>
                        ) : (
                            <div className="space-y-1.5">
                                <p id="documents-label" className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                    {t('transfers.supportingDocuments')}{' '}
                                    <span className="font-normal text-gray-500 dark:text-slate-400">({t('transfers.documentUploadHint')})</span>
                                </p>
                                <MultiFileUpload
                                    id="documents"
                                    files={form.data.documents.filter((file): file is File => file !== null)}
                                    onChange={(files) => form.setData('documents', files)}
                                    accept={ACCEPT}
                                    chooseLabel={t('transfers.clickToUpload')}
                                    removeLabel={t('transfers.removeFile')}
                                    describedBy="documents-label"
                                />
                                <FieldError>{generalDocumentError}</FieldError>
                            </div>
                        )}

                        <div className="flex flex-col-reverse gap-2 border-t border-gray-200 pt-5 sm:flex-row sm:justify-end dark:border-slate-800">
                            <Link href={show_url} className={publicButtonSecondary}>
                                {t('common.cancel')}
                            </Link>
                            <Button type="submit" variant="primary" loading={form.processing}>
                                {form.processing ? t('transfers.submitting') : t('transfers.submitApplication')}
                            </Button>
                        </div>
                    </form>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}
