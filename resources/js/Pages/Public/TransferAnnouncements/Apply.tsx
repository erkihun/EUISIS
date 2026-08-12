import PublicLayout from '@/Layouts/PublicLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent, useRef } from 'react';
import { SVGProps } from 'react';
import type { PageProps } from '@/types';

type IconProps = SVGProps<SVGSVGElement>;

function ArrowLeftIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <line x1="19" y1="12" x2="5" y2="12" />
            <polyline points="12 19 5 12 12 5" />
        </svg>
    );
}

function UploadIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <polyline points="16 16 12 12 8 16" />
            <line x1="12" y1="12" x2="12" y2="21" />
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3" />
        </svg>
    );
}

function XIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    );
}

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

const inputCls = 'w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-slate-100 placeholder-slate-500 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
const labelCls = 'mb-1.5 block text-xs font-medium text-slate-400';

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

    return (
        <PublicLayout title={pageTitle}>
            <Head title={pageTitle} />

            <div className="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
                {/* Back */}
                <Link
                    href={show_url}
                    className="mb-6 inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200"
                >
                    <ArrowLeftIcon className="h-4 w-4" />
                    {t('transfers.backToAnnouncement')}
                </Link>

                {/* Announcement summary */}
                <div className="mb-6 rounded-2xl border border-slate-700 bg-slate-900 p-5">
                    <p className="text-xs font-medium text-slate-400">{orgName}</p>
                    <p className="mt-0.5 text-lg font-bold text-slate-100">{posTitle}</p>
                    <div className="mt-2 flex flex-wrap gap-2 text-xs text-slate-400">
                        {a.grade_level && (
                            <span className="rounded-full bg-slate-800 px-2.5 py-1">
                                {t('transfers.gradeLevel')}: <strong className="text-slate-200">{a.grade_level}</strong>
                            </span>
                        )}
                        {a.closing_date && (
                            <span className="rounded-full bg-slate-800 px-2.5 py-1">
                                {t('transfers.closes')}: <strong className="text-slate-200"><LocalizedDateDisplay value={a.closing_date} /></strong>
                            </span>
                        )}
                    </div>
                </div>

                {/* Required documents info */}
                {a.required_documents && a.required_documents.length > 0 && (
                    <div className="mb-6 rounded-xl border border-orange-900/40 bg-orange-950/30 px-4 py-3">
                        <p className="text-xs font-semibold text-orange-300">{t('transfers.requiredDocuments')}:</p>
                        <ul className="mt-1 list-inside list-disc space-y-0.5 text-xs text-orange-200/80">
                            {a.required_documents.map((d, i) => <li key={i}>{d}</li>)}
                        </ul>
                    </div>
                )}

                {/* Form */}
                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-xl"
                >
                    <h1 className="mb-6 text-lg font-bold text-slate-100">{t('transfers.applyForTransfer')}</h1>

                    {/* Global error */}
                    {(form.errors as Record<string, string | undefined>).application && (
                        <div className="mb-4 rounded-xl border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-300">
                            {(form.errors as Record<string, string | undefined>).application}
                        </div>
                    )}

                    {/* Cover letter */}
                    <div className="mb-5">
                        <label className={labelCls}>
                            {t('transfers.coverLetter')}
                            <span className="ml-1 text-slate-500">({t('common.optional')})</span>
                        </label>
                        <textarea
                            className={`${inputCls} min-h-[10rem] resize-y`}
                            placeholder={t('transfers.coverLetterPlaceholder')}
                            value={form.data.cover_letter}
                            onChange={(e) => form.setData('cover_letter', e.target.value)}
                            maxLength={3000}
                        />
                        <div className="mt-1 flex justify-between text-xs text-slate-500">
                            <span>{form.errors.cover_letter}</span>
                            <span>{form.data.cover_letter.length} / 3000</span>
                        </div>
                    </div>

                    {/* Document upload */}
                    <div className="mb-6">
                        <label className={labelCls}>
                            {t('transfers.supportingDocuments')}
                            <span className="ml-1 text-slate-500">({t('transfers.documentUploadHint')})</span>
                        </label>

                        {/* Drop zone / file picker */}
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-700 bg-slate-800/60 px-4 py-5 text-sm text-slate-400 transition hover:border-slate-500 hover:text-slate-200"
                        >
                            <UploadIcon className="h-5 w-5" />
                            {t('transfers.clickToUpload')}
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept=".pdf,.jpg,.jpeg,.png"
                            className="hidden"
                            onChange={handleFileChange}
                        />

                        {/* Selected files list */}
                        {form.data.documents.length > 0 && (
                            <ul className="mt-3 space-y-2">
                                {form.data.documents.map((f, i) => (
                                    <li key={i} className="flex items-center justify-between rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300">
                                        <span className="max-w-[80%] truncate">{f.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => removeFile(i)}
                                            className="ml-2 text-slate-500 hover:text-red-400"
                                            aria-label={t('transfers.removeFile')}
                                        >
                                            <XIcon className="h-3.5 w-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {form.errors.documents && (
                            <p className="mt-1 text-xs text-red-400">{form.errors.documents}</p>
                        )}
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="inline-flex items-center justify-center rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-60"
                        >
                            {form.processing ? t('transfers.submitting') : t('transfers.submitApplication')}
                        </button>
                        <Link
                            href={show_url}
                            className="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-medium text-slate-300 transition hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                    </div>
                </form>
            </div>
        </PublicLayout>
    );
}
