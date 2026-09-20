import PublicLayout from '@/Layouts/PublicLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, useForm } from '@inertiajs/react';
import { Alert, Button, FieldError, FormField, Textarea, buttonClassName } from '@euisis/ui';
import { PublicContainer, PublicPageHeader, publicButtonSecondary } from '@/Components/public/PublicPage';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent, useRef } from 'react';
import { X } from '@/Components/Icons';
import type { PageProps } from '@/types';

type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    closing_date: string | null;
    required_documents: string[] | null;
};

interface Props extends PageProps {
    announcement: Announcement;
    show_url: string;
}

/**
 * Apply for a transfer.
 *
 * The form used to be hard-coded dark — slate-900 panels and slate-100 text
 * with no light-theme variants — so in the default light theme it was a black
 * slab in a white page, and its labels were not connected to their fields.
 * It now uses the shared form controls and theme tokens like every other page.
 * Submission is unchanged: same fields, same FormData, same route.
 */
export default function TransferAnnouncementApply({ announcement: a, show_url }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';
    const fileInputRef = useRef<HTMLInputElement>(null);

    const orgName = (useAmharic ? a.organization_name_am : null) ?? a.organization_name_en ?? '—';
    const posTitle = (useAmharic ? a.position_title_am : null) ?? a.position_title_en ?? '—';

    const form = useForm<{
        cover_letter: string;
        documents: File[];
    }>({
        cover_letter: '',
        documents: [],
    });

    function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
        const picked = Array.from(e.target.files ?? []);
        form.setData('documents', [...form.data.documents, ...picked]);
        if (fileInputRef.current) fileInputRef.current.value = '';
    }

    function removeFile(index: number) {
        form.setData('documents', form.data.documents.filter((_, i) => i !== index));
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        const fd = new FormData();
        fd.append('cover_letter', form.data.cover_letter);
        form.data.documents.forEach((f) => fd.append('documents[]', f));

        form.transform(() => fd as any);
        form.post(route('public.transfer-announcements.apply.store', { announcement: a.id }));
    }

    const pageTitle = `${t('transfers.applyForTransfer')} — ${posTitle}`;
    const applicationError = (form.errors as Record<string, string | undefined>).application;

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
                        <span className="font-medium text-gray-700 dark:text-slate-300">{posTitle}</span>
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
                    {a.required_documents && a.required_documents.length > 0 && (
                        <Alert tone="info" title={t('transfers.requiredDocuments')}>
                            <ul className="mt-1 list-inside list-disc space-y-0.5">
                                {a.required_documents.map((d, i) => <li key={i}>{d}</li>)}
                            </ul>
                        </Alert>
                    )}

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
                                    className="min-h-[10rem] resize-y"
                                    placeholder={t('transfers.coverLetterPlaceholder')}
                                    value={form.data.cover_letter}
                                    onChange={(e) => form.setData('cover_letter', e.target.value)}
                                    maxLength={3000}
                                />
                            )}
                        </FormField>

                        <div className="space-y-1.5">
                            <p id="documents-label" className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                {t('transfers.supportingDocuments')}{' '}
                                <span className="font-normal text-gray-500 dark:text-slate-400">({t('transfers.documentUploadHint')})</span>
                            </p>

                            <Button
                                variant="outline"
                                className="w-full border-dashed"
                                aria-describedby="documents-label"
                                onClick={() => fileInputRef.current?.click()}
                            >
                                {t('transfers.clickToUpload')}
                            </Button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                accept=".pdf,.jpg,.jpeg,.png"
                                className="hidden"
                                aria-labelledby="documents-label"
                                onChange={handleFileChange}
                            />

                            {form.data.documents.length > 0 && (
                                <ul className="divide-y divide-gray-200 rounded-card border border-gray-200 dark:divide-slate-800 dark:border-slate-800">
                                    {form.data.documents.map((f, i) => (
                                        <li key={i} className="flex items-center justify-between gap-2 px-3 py-2 text-sm text-gray-700 dark:text-slate-300">
                                            <span className="min-w-0 truncate">{f.name}</span>
                                            <button
                                                type="button"
                                                onClick={() => removeFile(i)}
                                                className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'h-8 w-8' })}
                                                aria-label={`${t('transfers.removeFile')}: ${f.name}`}
                                            >
                                                <X className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <FieldError>{form.errors.documents}</FieldError>
                        </div>

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
