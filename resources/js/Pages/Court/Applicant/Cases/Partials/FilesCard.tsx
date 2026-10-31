import { router, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, FieldError, FormField, Input, actionLinkClassName, buttonClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseFile } from '@/court/types/applicantCases';
import SectionCard, { EmptyBox } from '../../../Cases/Show/SectionCard';
import { EyeIcon, FilesIcon, TrashIcon, UploadIcon } from '../../../Cases/Show/icons';

/**
 * The files on the case besides the evidence PDFs: the applicant uploads one
 * with an optional label (applicant.cases.files.upload), opens any of them,
 * and removes the ones they uploaded.
 */
export default function FilesCard({ caseId, files }: { caseId: number; files: ApplicantCaseFile[] }) {
    const { t, text } = useApplicantCaseText();
    const { confirm } = useConfirm();
    const fileInput = useRef<HTMLInputElement>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const form = useForm<{ label: string; file: File | null }>({ label: '', file: null });

    const upload = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.cases.files.upload', caseId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const remove = async (file: ApplicantCaseFile) => {
        const result = await confirm({ title: t('cm.cases.files.remove_confirm'), description: file.name, variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.files.delete', [caseId, file.id]), { preserveScroll: true, onStart: () => setRemoving(file.id), onFinish: () => setRemoving(null) });
    };

    return (
        <SectionCard id="uploaded-files" title={t('cm.cases.files.title')} icon={<FilesIcon className="h-4 w-4" />} count={`${files.length} ${t('cm.cases.files.total')}`}>
            <form onSubmit={upload} className="grid grid-cols-1 gap-3 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                <FormField id="file-label" label={t('cm.cases.files.label_placeholder')} error={form.errors.label}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} placeholder={t('cm.cases.files.label_placeholder')}
                            aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" maxLength={255} />
                    )}
                </FormField>
                <div className="space-y-1.5">
                    <label htmlFor="file-upload" className="sr-only">{t('cm.cases.file')}</label>
                    <input id="file-upload" ref={fileInput} type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                        className={cx('block text-sm text-(--app-muted-foreground)', 'file:mr-3 file:rounded-control file:border-0 file:bg-(--app-border) file:px-4 file:py-2 file:text-sm file:font-medium file:text-(--app-foreground)')} />
                    <FieldError>{form.errors.file}</FieldError>
                </div>
                <Button type="submit" variant="primary" loading={form.processing} icon={<UploadIcon className="h-4 w-4" />}>{t('cm.cases.files.upload')}</Button>
                <p className="text-xs text-(--app-muted-foreground) sm:col-span-3">{text('files_hint')}</p>
            </form>

            {files.length === 0 ? (
                <EmptyBox>
                    <FilesIcon className="mx-auto mb-2 h-10 w-10 opacity-50" />
                    {t('cm.cases.files.no_files')}
                </EmptyBox>
            ) : (
                <ul className="divide-y divide-(--app-border)">
                    {files.map((file) => (
                        <li key={file.id} className="flex flex-wrap items-center justify-between gap-3 px-1 py-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 font-medium text-(--app-foreground)"><FilesIcon className="h-4 w-4 text-(--app-muted-foreground)" /> {file.name}</div>
                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-(--app-muted-foreground)">
                                    <span>{file.mime ?? t('cm.cases.file')}</span>
                                    <span>• {(file.size / 1024).toFixed(1)} KB</span>
                                    <span>• <LocalizedDateDisplay value={file.created_at} withTime /></span>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <a href={route('court.applicant.cases.files.download', [caseId, file.id])} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline', size: 'xs' })}>
                                    <EyeIcon className="mr-1 h-3 w-3" />{t('cm.cases.view')}
                                </a>
                                {file.can_delete && (
                                    <button type="button" onClick={() => remove(file)} disabled={removing === file.id} className={actionLinkClassName({ tone: 'danger' })}>
                                        <TrashIcon className="mr-0.5 inline h-3 w-3" /> {t('cm.cases.general.delete')}
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
