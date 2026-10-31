import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, FormField, Input, Select, TableCard, Textarea, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    appeal: {
        id: number; appeal_number: string; title: string; grounds: string | null; status: string;
        case_id: number; case_number: string; case_title: string | null;
        decided_by: string | null; decision_notes: string | null; submitted_at: string | null; decided_at: string | null; created_at: string | null;
        /** EUISIS ADR 0013 #14: who appeals, how the request came, the release and the appellate outcome. */
        side: 'applicant' | 'respondent' | null; appellant: string | null; via: string | null; released_at: string | null;
        outcome: 'confirmed' | 'modified' | 'reversed' | null; outcome_date: string | null; appellate_court: string | null;
        appellate_case_number: string | null; outcome_notes: string | null; outcome_document_url: string | null;
    };
    documents: { id: number; label: string | null; created_at: string | null }[];
    can: { edit: boolean; decide: boolean; upload: boolean; outcome?: boolean };
}

const TONES: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
    draft: 'warning', submitted: 'info', under_review: 'info', approved: 'success', rejected: 'danger', closed: 'neutral',
};

/**
 * One appeal to the appellate court: its case, who appeals and on what grounds;
 * the registry releases the record (the case is then marked as appealed) or
 * refuses it, and the appellate outcome is recorded on the case record page.
 */
export default function AppealShow({ appeal, documents, can }: Props) {
    const { t } = useLocale();
    const a = (key: string) => t(`cm.appeal_records.panel.${key}`);
    const { confirm } = useConfirm();
    const fileInput = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const upload = useForm<{ label: string; file: File | null }>({ label: '', file: null });
    const decide = useForm({ decision: 'approved', decision_notes: '' });
    const statusLabel = (status: string) => t(`cm.appeal_records.statuses.${status}`);

    const submitUpload = (event: FormEvent) => {
        event.preventDefault();
        upload.post(route('court.appeals.docs.upload', appeal.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { upload.reset(); if (fileInput.current) fileInput.current.value = ''; } });
    };
    const removeDocument = async (id: number) => {
        const { confirmed } = await confirm({ title: t('court.appeals.remove'), description: t('court.appeals.removeConfirm'), confirmLabel: t('court.appeals.remove'), cancelLabel: t('common.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('court.appeals.docs.delete', [appeal.id, id]), { preserveScroll: true, onStart: () => setBusy(id), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={appeal.appeal_number} description={appeal.title} backHref={route('court.appeals.index')} actions={(
                <>
                    {appeal.status === 'draft' && can.edit && <Link href={route('court.appeals.edit', appeal.id)} className={buttonClassName({ variant: 'outline' })}>{t('court.appeals.edit')}</Link>}
                    {appeal.status === 'draft' && can.edit && (
                        <Button onClick={() => router.post(route('court.appeals.submit', appeal.id), {}, { preserveScroll: true })}>{t('court.appeals.submit')}</Button>
                    )}
                </>
            )} />
        )}>
            <Head title={appeal.appeal_number} />

            <div className="grid gap-4 xl:grid-cols-3">
                <div className="space-y-4 xl:col-span-2">
                    <Card className="space-y-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className={badgeClassName(TONES[appeal.status] ?? 'neutral')}>{statusLabel(appeal.status)}</span>
                            <span className="text-sm text-(--app-muted-foreground)">
                                {t('court.appeals.case')}: <Link href={route('court.cases.show', appeal.case_id)} className="font-mono font-semibold text-primary hover:underline">{appeal.case_number}</Link>
                                {appeal.case_title && <> · {appeal.case_title}</>}
                            </span>
                        </div>
                        <h2 className="text-xl font-semibold text-(--app-foreground)">{appeal.title}</h2>
                        {appeal.grounds && <div className="whitespace-pre-line text-sm leading-relaxed text-(--app-foreground)">{appeal.grounds}</div>}
                        <dl className="grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt className="text-xs text-(--app-muted-foreground)">{t('court.appeals.submittedAt')}</dt><dd><LocalizedDateDisplay value={appeal.submitted_at} withTime /></dd></div>
                            <div><dt className="text-xs text-(--app-muted-foreground)">{t('court.appeals.decidedAt')}</dt><dd><LocalizedDateDisplay value={appeal.decided_at} withTime /></dd></div>
                            <div><dt className="text-xs text-(--app-muted-foreground)">{t('court.appeals.decidedBy')}</dt><dd>{appeal.decided_by ?? '—'}</dd></div>
                        </dl>
                        {appeal.decision_notes && <p className="rounded-lg bg-(--app-surface-muted) p-3 text-sm text-(--app-foreground)">{appeal.decision_notes}</p>}
                        {appeal.side && (
                            <dl className="grid gap-3 border-t border-(--app-border) pt-4 text-sm sm:grid-cols-3">
                                <div><dt className="text-xs text-(--app-muted-foreground)">{a('side')}</dt><dd>{appeal.appellant} ({t(`cm.appeal_records.sides.${appeal.side}`)})</dd></div>
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.appeal_records.bundle.requested')}</dt><dd>{appeal.via ? t(`cm.appeal_records.via.${appeal.via}`) : '—'}</dd></div>
                                <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.appeal_records.bundle.released')}</dt><dd><LocalizedDateDisplay value={appeal.released_at} fallback="—" /></dd></div>
                            </dl>
                        )}
                        {appeal.outcome && (
                            <div className="rounded-lg border border-(--app-border) p-3 text-sm">
                                <p className="font-semibold">{t('cm.appeal_records.bundle.outcome')}: {t(`cm.appeal_records.outcomes.${appeal.outcome}`)}</p>
                                <p className="text-(--app-muted-foreground)">{[appeal.appellate_court, appeal.appellate_case_number].filter(Boolean).join(' · ')} · <LocalizedDateDisplay value={appeal.outcome_date} fallback="—" /></p>
                                {appeal.outcome_notes && <p className="mt-1">{appeal.outcome_notes}</p>}
                                {appeal.outcome_document_url && <a href={appeal.outcome_document_url} className="mt-1 inline-block text-xs font-medium text-primary hover:underline">{a('outcome_document')}</a>}
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Link href={`${route('court.recordes.show', appeal.case_id)}#section-appeal`} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.recordes.titles.record')}</Link>
                            <a href={route('court.recordes.appeal-pdf', appeal.case_id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{a('download')}</a>
                        </div>
                    </Card>

                    <TableCard title={t('court.appeals.documents')}>
                        {documents.length === 0 ? <p className="px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{t('court.appeals.noDocuments')}</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {documents.map((doc) => (
                                    <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                        <div>
                                            <div className="font-medium text-(--app-foreground)">{doc.label || `#${doc.id}`}</div>
                                            <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={doc.created_at} withTime /></div>
                                        </div>
                                        <div className="flex gap-1.5">
                                            <a href={route('court.appeals.docs.download', [appeal.id, doc.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('court.appeals.download')}</a>
                                            {can.edit && <button type="button" disabled={busy === doc.id} onClick={() => void removeDocument(doc.id)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>{t('court.appeals.remove')}</button>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {can.upload && (
                            <form onSubmit={submitUpload} className="grid gap-3 border-t border-(--app-border) p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <FormField label={t('court.appeals.documentLabel')} error={upload.errors.label}>
                                    {({ id }) => <Input id={id} value={upload.data.label} onChange={(event) => upload.setData('label', event.target.value)} />}
                                </FormField>
                                <FormField label={t('court.appeals.documentFile')} required error={upload.errors.file}>
                                    {({ id }) => <input id={id} ref={fileInput} type="file" required onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)} className="block w-full text-sm" />}
                                </FormField>
                                <Button type="submit" disabled={upload.processing}>{t('court.appeals.upload')}</Button>
                            </form>
                        )}
                    </TableCard>
                </div>

                {can.decide && ['submitted', 'under_review'].includes(appeal.status) && (
                    <Card>
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{t('court.appeals.decide')}</h2>
                        <form onSubmit={(event) => { event.preventDefault(); decide.post(route('court.appeals.decide', appeal.id), { preserveScroll: true }); }} className="mt-4 space-y-4">
                            <FormField label={t('court.appeals.decision')} required error={decide.errors.decision}>
                                {({ id }) => (
                                    <Select id={id} value={decide.data.decision} onChange={(event) => decide.setData('decision', event.target.value)}>
                                        <option value="approved">{a('release')}</option>
                                        <option value="rejected">{a('refuse')}</option>
                                    </Select>
                                )}
                            </FormField>
                            <FormField label={t('court.appeals.decisionNotes')} error={decide.errors.decision_notes}>
                                {({ id }) => <Textarea id={id} rows={3} value={decide.data.decision_notes} onChange={(event) => decide.setData('decision_notes', event.target.value)} />}
                            </FormField>
                            <Button type="submit" disabled={decide.processing}>{t('court.appeals.record')}</Button>
                        </form>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
