import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { PageProps } from '@/types';

/**
 * Apply for a transfer, inside the portal.
 *
 * Posts to the portal's own endpoint, which shares SubmitTransferApplicationAction
 * with the public flow — only the chrome and the redirect target differ.
 */
type Announcement = {
    id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    grade_level: string | null;
    closing_date: string | null;
    required_documents: string | null;
};

type Props = PageProps & {
    announcement: Announcement;
};

export default function AnnouncementApply({ announcement }: Props) {
    const { t, locale } = useLocale();
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';
    const position = name(announcement.position_title_en, announcement.position_title_am);

    const form = useForm<{ cover_letter: string; documents: File[] }>({
        cover_letter: '',
        documents: [],
    });

    const applicationError = (form.errors as Record<string, string | undefined>).application;

    function pickDocuments(e: ChangeEvent<HTMLInputElement>) {
        form.setData('documents', Array.from(e.target.files ?? []));
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (form.processing) return;

        form.post(route('employee.announcements.apply.store', { announcement: announcement.id }), {
            forceFormData: true,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transfers.applyForTransfer')}
                    description={position}
                    backHref={route('employee.announcements.show', { announcement: announcement.id })}
                />
            }
        >
            <Head title={t('transfers.applyForTransfer')} />

            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="font-semibold text-gray-900 dark:text-slate-100">{position}</p>
                    <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">
                        {name(announcement.organization_name_en, announcement.organization_name_am)}
                        {announcement.grade_level ? ` · ${t('transfers.gradeLevel')} ${announcement.grade_level}` : ''}
                    </p>
                    {announcement.closing_date && (
                        <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                            {t('transfers.closes')}: <LocalizedDateDisplay value={announcement.closing_date} />
                        </p>
                    )}
                </div>

                {/* The submit action reports a refused application under its own
                  * key rather than against a field. */}
                {applicationError && (
                    <div role="alert" className="rounded-panel border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-300">
                        {applicationError}
                    </div>
                )}

                <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <label htmlFor="cover_letter" className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">
                        {t('transfers.coverLetter')}
                    </label>
                    <textarea
                        id="cover_letter"
                        rows={7}
                        maxLength={3000}
                        placeholder={t('transfers.coverLetterPlaceholder')}
                        value={form.data.cover_letter}
                        onChange={(e) => form.setData('cover_letter', e.target.value)}
                        className="w-full rounded-control border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                    />
                    {form.errors.cover_letter && (
                        <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.cover_letter}</p>
                    )}

                    <label htmlFor="documents" className="mb-1 mt-5 block text-xs font-medium text-gray-600 dark:text-slate-400">
                        {t('transfers.supportingDocuments')}{' '}
                        <span className="font-normal text-gray-400 dark:text-slate-500">
                            ({t('transfers.documentUploadHint')})
                        </span>
                    </label>
                    <input
                        id="documents"
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png"
                        onChange={pickDocuments}
                        className="w-full rounded-control border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-[color:var(--color-primary)] file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-white dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300"
                    />
                    {form.data.documents.length > 0 && (
                        <ul className="mt-2 space-y-1 text-xs text-gray-500 dark:text-slate-400">
                            {form.data.documents.map((file) => (
                                <li key={file.name} className="truncate">{file.name}</li>
                            ))}
                        </ul>
                    )}
                    {form.errors.documents && (
                        <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.documents}</p>
                    )}
                </div>

                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? t('transfers.submitting') : t('transfers.submitApplication')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
