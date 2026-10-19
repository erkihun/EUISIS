import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import LegalSourceForm, { type LegalSourceFormValues, type SourceType } from '@/Components/organizationDesign/LegalSourceForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FieldError, FileUpload, FormField, Input, Select, Textarea, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { LegalSourceSummary } from './Index';

type Attachment = { id: string; original_name: string; mime_type: string; size_bytes: number; label: string | null; uploaded_by: string | null; created_at: string | null };
type Citation = { id: string; subject_type: string; subject_id: string; label: string; title: string | null; note: string | null; created_at: string | null };

type Props = {
    source: LegalSourceSummary & { description_en: string | null; description_am: string | null; source_url: string | null; created_by: string | null };
    attachments: Attachment[];
    citations: Citation[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    sourceTypes: SourceType[];
    statuses: string[];
    allowedExtensions: string[];
    maxAttachmentKb: number;
    can: { update: boolean; delete: boolean; manageAttachments: boolean };
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * One legal source: its details, the documents behind it (downloaded only
 * through the controlled route), the design records that cite it, and its
 * status history. A cited source is never deleted; it is superseded or
 * repealed so the records resting on it keep their evidence.
 */
export default function LegalSourceShow({ source, attachments, citations, history, sourceTypes, statuses, allowedExtensions, maxAttachmentKb, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [changingStatus, setChangingStatus] = useState(false);
    const upload = useForm<{ file: File | null; label: string }>({ file: null, label: '' });
    const statusForm = useForm<{ status: string; reason: string }>({ status: source.status, reason: '' });

    const values: LegalSourceFormValues = {
        organization_id: source.organization_id ?? '', code: source.code, title_en: source.title_en, title_am: source.title_am ?? '',
        source_type_id: source.source_type_id, issuing_authority: source.issuing_authority ?? '', document_number: source.document_number ?? '',
        issue_date: source.issue_date ?? '', effective_from: source.effective_from ?? '', effective_to: source.effective_to ?? '',
        description_en: source.description_en ?? '', description_am: source.description_am ?? '', source_url: source.source_url ?? '',
    };

    async function remove() {
        const { confirmed } = await confirm({ title: tt('legalSources.delete'), description: tt('legalSources.deleteConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.legal-sources.destroy', source.id));
    }

    async function removeAttachment(attachment: Attachment) {
        const { confirmed } = await confirm({ title: tt('legalSources.removeDocument'), description: tt('legalSources.removeDocumentConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.legal-sources.attachments.destroy', [source.id, attachment.id]), { preserveScroll: true });
    }

    async function removeCitation(citation: Citation) {
        const { confirmed } = await confirm({ title: tt('legalSources.removeCitation'), description: tt('legalSources.removeCitationConfirm'), variant: 'warning' });
        if (confirmed) router.delete(route('organization-design.legal-sources.citations.destroy', [source.id, citation.id]), { preserveScroll: true });
    }

    function submitUpload(event: React.FormEvent) {
        event.preventDefault();
        upload.post(route('organization-design.legal-sources.attachments.store', source.id), { preserveScroll: true, forceFormData: true, onSuccess: () => upload.reset() });
    }

    function submitStatus(event: React.FormEvent) {
        event.preventDefault();
        statusForm.post(route('organization-design.legal-sources.status', source.id), { preserveScroll: true, onSuccess: () => setChangingStatus(false) });
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.legal-sources.index')}
                title={`${source.code} · ${titleOf(source, locale)}`}
                description={source.organization ? nameOf(source.organization, locale) : tt('legalSources.cityLevel')}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('legalSources.edit')}</Button>}
                        {can.update && <Button variant="outline" onClick={() => setChangingStatus(true)}>{tt('legalSources.changeStatus')}</Button>}
                        {can.delete && <Button variant="destructiveOutline" onClick={remove}>{tt('legalSources.delete')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${source.code} · ${titleOf(source, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('legalSources.details')}</CardTitle>
                            <StatusPill status={source.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.sourceType'), nameOf(source.source_type, locale))}
                            {detail(tt('fields.documentNumber'), source.document_number)}
                            {detail(tt('fields.issuingAuthority'), source.issuing_authority)}
                            {detail(tt('fields.issueDate'), source.issue_date ? <LocalizedDateDisplay value={source.issue_date} /> : null)}
                            {detail(tt('fields.effectiveFrom'), source.effective_from ? <LocalizedDateDisplay value={source.effective_from} /> : null)}
                            {detail(tt('fields.effectiveTo'), source.effective_to ? <LocalizedDateDisplay value={source.effective_to} /> : null)}
                            {detail(tt('fields.sourceUrl'), source.source_url ? <a href={source.source_url} target="_blank" rel="noopener noreferrer" className="wrap-anywhere text-primary hover:underline">{source.source_url}</a> : null)}
                            {detail(tt('legalSources.recordedBy'), source.created_by)}
                            {detail(tt('fields.descriptionEn'), source.description_en)}
                            {detail(tt('fields.descriptionAm'), source.description_am)}
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('legalSources.documents')}</CardTitle></CardHeader>
                        <div className="divide-y divide-(--app-border)">
                            {attachments.length === 0 && <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('legalSources.noDocuments')}</p>}
                            {attachments.map((a) => (
                                <div key={a.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-(--app-foreground)">{a.label || a.original_name}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{a.original_name} · {formatBytes(a.size_bytes)}{a.uploaded_by ? ` · ${a.uploaded_by}` : ''} · <LocalizedDateDisplay value={a.created_at} /></p>
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <a href={route('organization-design.legal-sources.attachments.download', [source.id, a.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{tt('legalSources.download')}</a>
                                        {can.manageAttachments && <Button variant="ghost" size="sm" onClick={() => removeAttachment(a)}>{t('common.remove')}</Button>}
                                    </div>
                                </div>
                            ))}
                        </div>
                        {can.manageAttachments && (
                            <form onSubmit={submitUpload} className="grid gap-3 border-t border-(--app-border) p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <FileUpload id="legal-source-file" label={tt('legalSources.addDocument')} value={upload.data.file} accept={allowedExtensions.map((e) => `.${e}`).join(',')} maxSizeMb={Math.floor(maxAttachmentKb / 1024)} error={upload.errors.file} onChange={(file) => upload.setData('file', file)} />
                                <FormField id="legal-source-label" label={tt('legalSources.documentLabel')} error={upload.errors.label}>
                                    {({ id }) => <Input id={id} value={upload.data.label} onChange={(e) => upload.setData('label', e.target.value)} />}
                                </FormField>
                                <Button type="submit" variant="primary" disabled={!upload.data.file} loading={upload.processing}>{tt('legalSources.upload')}</Button>
                            </form>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('legalSources.citations')}</CardTitle></CardHeader>
                        <div className="divide-y divide-(--app-border)">
                            {citations.length === 0 && <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('legalSources.noCitations')}</p>}
                            {citations.map((c) => (
                                <div key={c.id} className="flex items-start justify-between gap-3 px-5 py-3">
                                    <div className="min-w-0">
                                        <p className="text-xs font-medium uppercase tracking-wide text-(--app-muted-foreground)">{tt(`legalSources.subjects.${c.subject_type}`)}</p>
                                        <p className="truncate text-sm font-medium text-(--app-foreground)">{c.label}{c.title ? ` · ${c.title}` : ''}</p>
                                        {c.note && <p className="text-xs text-(--app-muted-foreground)">{c.note}</p>}
                                    </div>
                                    {can.update && <Button variant="ghost" size="sm" onClick={() => removeCitation(c)}>{t('common.remove')}</Button>}
                                </div>
                            ))}
                        </div>
                        <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('legalSources.citeFromRecord')}</p>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('legalSources.history')}</CardTitle></CardHeader>
                        <ol className="divide-y divide-(--app-border)">
                            {history.length === 0 && <li className="px-5 py-4 text-sm text-(--app-muted-foreground)">—</li>}
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <span className="font-medium text-(--app-foreground)">{h.from ? `${tt(`legalSources.status.${h.from}`)} → ` : ''}{tt(`legalSources.status.${h.to}`)}</span>
                                    <span className="text-(--app-muted-foreground)"> · {h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} /></span>
                                    {h.reason && <p className="text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
            </div>

            {can.update && (
                <LegalSourceForm show={editing} onClose={() => setEditing(false)} title={tt('legalSources.edit')} initial={values}
                    url={route('organization-design.legal-sources.update', source.id)} method="put" sourceTypes={sourceTypes} />
            )}

            <Modal show={changingStatus} onClose={() => setChangingStatus(false)} maxWidth="md">
                <form onSubmit={submitStatus} className="p-6">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{tt('legalSources.changeStatus')}</h2>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('legalSources.changeStatusHelp')}</p>
                    <div className="mt-5 space-y-4">
                        <FormField id="legal-source-status" label={tt('fields.status')} error={statusForm.errors.status}>
                            {({ id }) => (
                                <Select id={id} value={statusForm.data.status} onChange={(e) => statusForm.setData('status', e.target.value)}>
                                    {statuses.map((s) => <option key={s} value={s}>{tt(`legalSources.status.${s}`)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="legal-source-reason" label={tt('fields.reason')} error={statusForm.errors.reason}>
                            {({ id }) => <Textarea id={id} value={statusForm.data.reason} onChange={(e) => statusForm.setData('reason', e.target.value)} />}
                        </FormField>
                        <FieldError>{(statusForm.errors as Record<string, string | undefined>).source}</FieldError>
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setChangingStatus(false)}>{tt('actions.cancel')}</Button>
                        <Button type="submit" variant="primary" loading={statusForm.processing}>{tt('actions.save')}</Button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
