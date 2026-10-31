import { router, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, FieldError, FormField, Input, actionLinkClassName, buttonClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import type { CaseDetailPermissions, CaseFile } from '@/court/types/caseDetail';
import { fileKind } from './fileKind';
import SectionCard, { EmptyBox } from './SectionCard';
import { useCaseText } from './useCaseText';
import { EyeIcon, FilesIcon, TrashIcon, UploadIcon } from './icons';

interface Props {
    caseId: number;
    files: CaseFile[];
    can: CaseDetailPermissions;
    /** A closed case with a published decision takes no new files and loses none. */
    caseLocked: boolean;
}

/** The files uploaded to the case (by the court or the applicant): upload, view, remove. */
export default function FilesSection({ caseId, files, can, caseLocked }: Props) {
    const { t } = useCaseText();
    const { confirm } = useConfirm();
    const fileInput = useRef<HTMLInputElement>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const form = useForm<{ label: string; file: File | null }>({ label: '', file: null });

    const upload = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.cases.files.upload', caseId), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const remove = async (file: CaseFile) => {
        const result = await confirm({ title: t('cm.cases.files.remove_confirm'), description: file.name, variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.general.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.cases.files.delete', [caseId, file.id]), { preserveScroll: true, onStart: () => setRemoving(file.id), onFinish: () => setRemoving(null) });
    };

    return (
        <SectionCard id="uploaded-files" title={t('cm.cases.files.title')} icon={<FilesIcon />} count={`${files.length} ${t('cm.cases.files.total')}`}>
            {!caseLocked && can.createFiles && (
                <form onSubmit={upload} className="grid grid-cols-1 gap-3 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                    <FormField id="file-label" label={t('cm.cases.files.label_placeholder')} error={form.errors.label}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder={t('cm.cases.files.label_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <div className="space-y-1.5">
                        <label htmlFor="file-upload" className="sr-only">{t('cm.cases.file')}</label>
                        <input id="file-upload" ref={fileInput} type="file" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            className={cx('block text-sm text-(--app-muted-foreground)', 'file:mr-3 file:rounded-control file:border-0 file:bg-(--app-border) file:px-4 file:py-2 file:text-sm file:font-medium file:text-(--app-foreground)')} />
                        <FieldError>{form.errors.file}</FieldError>
                    </div>
                    <Button type="submit" variant="primary" loading={form.processing} icon={<UploadIcon className="h-4 w-4" />}>{t('cm.cases.files.upload')}</Button>
                </form>
            )}

            {files.length === 0 ? (
                <EmptyBox>
                    <FilesIcon className="mx-auto mb-2 h-10 w-10 opacity-50" />
                    {t('cm.cases.files.no_files')}
                </EmptyBox>
            ) : (
                <ul className="divide-y divide-(--app-border)">
                    {files.map((file) => (
                        <li key={file.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-3 hover:bg-(--app-surface-muted)">
                            <div>
                                <div className="flex items-center gap-2 font-medium text-(--app-foreground)"><FilesIcon className="h-4 w-4 text-(--app-muted-foreground)" /> {file.name}</div>
                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-(--app-muted-foreground)">
                                    <span>{fileKind(file.mime) ?? t('cm.cases.file')}</span>
                                    <span>• {(file.size / 1024).toFixed(1)} KB</span>
                                    <span>• <LocalizedDateDisplay value={file.created_at} withTime /></span>
                                    {file.uploaded_by && <span>• {t('cm.cases.files.uploaded_by')} {file.uploaded_by}</span>}
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {can.viewFiles && (
                                    <a href={route('court.cases.files.download', [caseId, file.id])} className={buttonClassName({ variant: 'outline', size: 'xs' })}><EyeIcon className="h-3 w-3" /> {t('cm.cases.documents.view')}</a>
                                )}
                                {!caseLocked && can.deleteFiles && (
                                    <button type="button" onClick={() => remove(file)} disabled={removing === file.id} className={actionLinkClassName({ tone: 'danger' })}><TrashIcon className="h-3 w-3" /> {t('cm.cases.general.delete')}</button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
