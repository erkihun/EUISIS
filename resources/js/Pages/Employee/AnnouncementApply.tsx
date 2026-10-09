import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useForm } from '@inertiajs/react';
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
    required_documents: string[] | null;
    positions: Array<{ id: string | null; position_title_en: string | null; position_title_am: string | null; position_code: string | null; organization_unit_en: string | null; organization_unit_am: string | null; grade_level: string | null; advertised_slots: number; eligible: boolean | null }>;
};

type EmployeeContext = { employee_number: string; employee_name: string; organization: string | null; organization_unit: string | null; position: string | null; grade_level: string | null };

type Props = PageProps & {
    announcement: Announcement;
    employee_context: EmployeeContext;
    employee_documents: Array<{ id: string; document_type: string; name: string }>;
};

export default function AnnouncementApply({ announcement, employee_context, employee_documents }: Props) {
    const { t, locale } = useLocale();
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';
    const position = name(announcement.position_title_en, announcement.position_title_am);

    const eligiblePositions = announcement.positions.filter((item) => item.eligible !== false);
    const requiresPositionSelection = announcement.positions.some((item) => item.id !== null);
    const requiredDocuments = announcement.required_documents ?? [];
    const form = useForm<{ announcement_position_id: string; cover_letter: string; documents: Array<File | null>; reuse_document_ids: string[] }>({
        announcement_position_id: eligiblePositions.length === 1 ? eligiblePositions[0].id ?? '' : '',
        cover_letter: '',
        documents: requiredDocuments.map(() => null),
        reuse_document_ids: requiredDocuments.map(() => ''),
    });

    const applicationError = (form.errors as Record<string, string | undefined>).application;

    function pickDocument(index: number, e: ChangeEvent<HTMLInputElement>) {
        const documents = [...form.data.documents];
        documents[index] = e.target.files?.[0] ?? null;
        form.setData('documents', documents);
        if (documents[index]) {
            const reuse = [...form.data.reuse_document_ids];
            reuse[index] = '';
            form.setData('reuse_document_ids', reuse);
        }
    }

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (form.processing) return;

        form.post(route('employee.announcements.apply.store', { announcement: announcement.id }), {
            forceFormData: true,
        });
    }

    return (
        <PortalPage
            title={t('transfers.applyForTransfer')}
            description={position}
            backHref={route('employee.announcements.show', { announcement: announcement.id })}
        >

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

                <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('transfers.currentAssignment')}</h2>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2"><div><dt className="text-xs text-gray-500">{t('employees.employeeNumber')}</dt><dd>{employee_context.employee_number}</dd></div><div><dt className="text-xs text-gray-500">{t('common.name')}</dt><dd>{employee_context.employee_name}</dd></div><div><dt className="text-xs text-gray-500">{t('transfers.organization')}</dt><dd>{employee_context.organization ?? '—'}</dd></div><div><dt className="text-xs text-gray-500">{t('transfers.position')}</dt><dd>{employee_context.position ?? '—'} {employee_context.grade_level ? `· ${employee_context.grade_level}` : ''}</dd></div></dl>
                </div>

                {requiresPositionSelection && <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <label htmlFor="announcement_position_id" className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('transfers.destinationPosition')}</label>
                    <select id="announcement_position_id" required className="w-full rounded-control border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" value={form.data.announcement_position_id} onChange={(e) => form.setData('announcement_position_id', e.target.value)}>
                        <option value="">{t('transfers.selectPosition')}</option>
                        {eligiblePositions.map((item, index) => <option key={item.id ?? `legacy-${index}`} value={item.id ?? ''}>{name(item.position_title_en, item.position_title_am)}{item.position_code ? ` (${item.position_code})` : ''}</option>)}
                    </select>
                    {form.errors.announcement_position_id && <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.announcement_position_id}</p>}
                    {announcement.required_documents && announcement.required_documents.length > 0 && <p className="mt-3 text-xs text-gray-500 dark:text-slate-400">{t('transfers.requiredDocuments')}: {announcement.required_documents.join(', ')}</p>}
                </div>}

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

                    <p className="mb-1 mt-5 text-xs font-medium text-gray-600 dark:text-slate-400">
                        {t('transfers.supportingDocuments')} <span className="font-normal text-gray-400 dark:text-slate-500">({t('transfers.documentUploadHint')})</span>
                    </p>
                    {requiredDocuments.length === 0 ? (
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('transfers.noRequiredDocumentsConfigured')}</p>
                    ) : (
                        <div className="space-y-3">{requiredDocuments.map((type, index) => {
                            const reusable = employee_documents.filter((document) => document.document_type === type);
                            return <div key={type} className="rounded-lg border border-gray-100 p-3 dark:border-slate-700">
                                <label className="mb-1 block text-sm font-medium text-gray-800 dark:text-slate-200">{type}</label>
                                <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => pickDocument(index, event)} className="w-full rounded-control border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950" />
                                {reusable.length > 0 && <label className="mt-2 block text-xs text-gray-600 dark:text-slate-400">{t('transfers.orReuseEmployeeDocument')}
                                    <select value={form.data.reuse_document_ids[index] ?? ''} onChange={(event) => { const reuse = [...form.data.reuse_document_ids]; reuse[index] = event.target.value; form.setData('reuse_document_ids', reuse); }} className="mt-1 block w-full rounded-control border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950">
                                        <option value="">{t('transfers.uploadNewDocument')}</option>{reusable.map((document) => <option key={document.id} value={document.id}>{document.name}</option>)}
                                    </select>
                                </label>}
                                {form.data.documents[index] && <p className="mt-1 truncate text-xs text-gray-500">{form.data.documents[index]?.name}</p>}
                            </div>;
                        })}</div>
                    )}
                    {form.errors.documents && (
                        <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.documents}</p>
                    )}
                </div>

                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={form.processing || eligiblePositions.length === 0 || (requiresPositionSelection && !form.data.announcement_position_id)}
                        className="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? t('transfers.submitting') : t('transfers.submitApplication')}
                    </button>
                </div>
            </form>
        </PortalPage>
    );
}
