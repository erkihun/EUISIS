import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, ConfirmDialog, FormField, Input, Select, Textarea } from '@euisis/ui';

/**
 * An employee's identity evidence, as the server describes it: the documents,
 * the photo and the signature, each section only when the reader may see it,
 * every version with only the actions the server allows. Files are opened
 * through the server's authorized download, never a public path; a signature
 * is drawn only when asked for.
 */
export type EvidenceVersion = {
    id: string;
    lineage_id: string;
    kind: 'document' | 'photo' | 'signature';
    document_type: string;
    title: string | null;
    version: number;
    status: string;
    is_current: boolean;
    original_filename: string;
    mime_type: string;
    file_size: number;
    issued_at: string | null;
    expires_at: string | null;
    uploaded_by: string | null;
    uploaded_at: string | null;
    verified_by: string | null;
    verified_at: string | null;
    verification_notes: string | null;
    archived_by: string | null;
    archive_reason: string | null;
    can: { download: boolean; replace: boolean; verify: boolean; archive: boolean; discard: boolean };
    urls: { file: string | null; replace: string; verify: string; reject: string; archive: string; discard: string };
};

type EvidenceSection = {
    items: Array<{ current: EvidenceVersion | null; history: EvidenceVersion[] }>;
    can_upload: boolean;
    upload_url: string;
};

export type EmployeeEvidence = {
    documents: EvidenceSection | null;
    photo: EvidenceSection | null;
    signature: EvidenceSection | null;
    document_types: string[];
    missing: { approval: string[]; cards: string[] } | null;
    limits: { document_max_kb: number; photo_max_kb: number; signature_max_kb: number; photo_min_pixels: number };
};

type Dialog =
    | { type: 'upload'; kind: 'document' | 'photo' | 'signature'; url: string }
    | { type: 'replace'; version: EvidenceVersion }
    | { type: 'reject'; version: EvidenceVersion }
    | { type: 'archive'; version: EvidenceVersion }
    | null;

function useLabel() {
    const { t } = useLocale();
    return (key: string) => t(`employees.evidence.${key}`);
}

/** A reason code such as REQUIRED_DOCUMENT_MISSING:appointment_letter, in words. */
function codeLabel(code: string, label: (key: string) => string): string {
    const [base, type] = code.split(':');
    const text = label(`codes.${base}`);
    return type ? `${text}: ${label(`types.${type}`)}` : text;
}

function VersionMeta({ version }: { version: EvidenceVersion }) {
    const label = useLabel();
    return (
        <div className="space-y-0.5 text-xs text-(--app-muted-foreground)">
            <p>
                {label('version')} {version.version} · {version.uploaded_by ?? '—'} · <LocalizedDateDisplay value={version.uploaded_at} withTime />
            </p>
            {version.verified_by && (
                <p>
                    {label(version.status === 'rejected' ? 'rejectedBy' : 'verifiedBy')} {version.verified_by} · <LocalizedDateDisplay value={version.verified_at} withTime />
                </p>
            )}
            {version.verification_notes && <p className="text-(--app-foreground)">{version.verification_notes}</p>}
            {version.expires_at && <p>{label('expires')} <LocalizedDateDisplay value={version.expires_at} /></p>}
            {version.archive_reason && <p>{label('archivedBecause')} {version.archive_reason}</p>}
        </div>
    );
}

function Actions({ version, onDialog, onConfirmVerify, onDiscard }: {
    version: EvidenceVersion;
    onDialog: (dialog: Dialog) => void;
    onConfirmVerify: (version: EvidenceVersion) => void;
    onDiscard: (version: EvidenceVersion) => void;
}) {
    const label = useLabel();
    return (
        <div className="flex flex-wrap gap-1">
            {version.can.download && version.urls.file && version.kind === 'document' && (
                <Button as="a" href={version.urls.file} size="xs" variant="outline">{label('download')}</Button>
            )}
            {version.can.verify && <Button size="xs" variant="primary" onClick={() => onConfirmVerify(version)}>{label('verify')}</Button>}
            {version.can.verify && <Button size="xs" variant="outline" onClick={() => onDialog({ type: 'reject', version })}>{label('reject')}</Button>}
            {version.can.replace && version.kind === 'document' && <Button size="xs" variant="outline" onClick={() => onDialog({ type: 'replace', version })}>{label('replace')}</Button>}
            {version.can.discard && <Button size="xs" variant="destructiveOutline" onClick={() => onDiscard(version)}>{label('discard')}</Button>}
            {version.can.archive && !version.can.discard && <Button size="xs" variant="destructiveOutline" onClick={() => onDialog({ type: 'archive', version })}>{label('archive')}</Button>}
        </div>
    );
}

function History({ history }: { history: EvidenceVersion[] }) {
    const label = useLabel();
    if (history.length === 0) return null;
    return (
        <details className="mt-2">
            <summary className="cursor-pointer text-xs text-primary">{label('history')} ({history.length})</summary>
            <ul className="mt-2 space-y-2 border-l border-(--app-border) pl-3">
                {history.map((version) => (
                    <li key={version.id} className="text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={version.status} label={label(`statuses.${version.status}`)} />
                            {version.can.download && version.urls.file && version.kind === 'document' && (
                                <a href={version.urls.file} className="text-xs text-primary hover:underline">{label('download')}</a>
                            )}
                        </div>
                        <VersionMeta version={version} />
                    </li>
                ))}
            </ul>
        </details>
    );
}

export default function EmployeeEvidencePanel({ evidence }: { evidence: EmployeeEvidence }) {
    const label = useLabel();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [verifying, setVerifying] = useState<EvidenceVersion | null>(null);
    const [discarding, setDiscarding] = useState<EvidenceVersion | null>(null);
    const [signatureShown, setSignatureShown] = useState(false);
    const form = useForm<{ kind: string; document_type: string; title: string; issued_at: string; expires_at: string; file: File | null; notes: string; reason: string }>({
        kind: 'document', document_type: '', title: '', issued_at: '', expires_at: '', file: null, notes: '', reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const open = (next: Dialog) => {
        form.reset();
        form.clearErrors();
        if (next?.type === 'upload') form.setData('kind', next.kind);
        setDialog(next);
    };
    const done = { preserveScroll: true, forceFormData: true, onSuccess: () => setDialog(null) };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!dialog) return;
        if (dialog.type === 'upload') form.post(dialog.url, done);
        if (dialog.type === 'replace') form.post(dialog.version.urls.replace, done);
        if (dialog.type === 'reject') form.post(dialog.version.urls.reject, { preserveScroll: true, onSuccess: () => setDialog(null) });
        if (dialog.type === 'archive') form.post(dialog.version.urls.archive, { preserveScroll: true, onSuccess: () => setDialog(null) });
    };
    const fileKind = dialog?.type === 'upload' ? dialog.kind : dialog?.type === 'replace' ? dialog.version.kind : 'document';
    const maxKb = fileKind === 'photo' ? evidence.limits.photo_max_kb : fileKind === 'signature' ? evidence.limits.signature_max_kb : evidence.limits.document_max_kb;
    const accept = fileKind === 'document' ? '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png' : '.jpg,.jpeg,.png,image/jpeg,image/png';

    const photo = evidence.photo?.items.find((item) => item.current !== null) ?? evidence.photo?.items[0] ?? null;
    const signature = evidence.signature?.items.find((item) => item.current !== null) ?? evidence.signature?.items[0] ?? null;
    const actions = { onDialog: open, onConfirmVerify: setVerifying, onDiscard: setDiscarding };

    return (
        <section className="space-y-5" aria-labelledby="employee-evidence-title">
            <div>
                <h3 id="employee-evidence-title" className="text-sm font-semibold text-(--app-foreground)">{label('title')}</h3>
                <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('description')}</p>
            </div>

            {evidence.missing && (evidence.missing.approval.length > 0 || evidence.missing.cards.length > 0) && (
                <div className="rounded-card border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-500/30 dark:bg-amber-500/5">
                    {evidence.missing.approval.length > 0 && (
                        <p><span className="font-medium">{label('missingForApproval')}</span> {evidence.missing.approval.map((code) => codeLabel(code, label)).join(' · ')}</p>
                    )}
                    {evidence.missing.cards.length > 0 && (
                        <p className="mt-1"><span className="font-medium">{label('missingForCards')}</span> {evidence.missing.cards.map((code) => codeLabel(code, label)).join(' · ')}</p>
                    )}
                </div>
            )}

            <div className="grid gap-5 lg:grid-cols-2">
                {evidence.photo && (
                    <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <div className="flex items-start justify-between gap-3">
                            <h4 className="text-sm font-semibold text-(--app-foreground)">{label('photo')}</h4>
                            {evidence.photo.can_upload && (
                                <Button size="xs" variant="outline" onClick={() => open({ type: 'upload', kind: 'photo', url: evidence.photo!.upload_url })}>
                                    {photo?.current ? label('replacePhoto') : label('uploadPhoto')}
                                </Button>
                            )}
                        </div>
                        {photo?.current ? (
                            <div className="mt-3 flex gap-4">
                                {photo.current.urls.file && (
                                    <img src={photo.current.urls.file} alt={label('photo')} className="h-32 w-24 rounded-card border border-(--app-border) object-cover" />
                                )}
                                <div className="min-w-0 space-y-2">
                                    <StatusBadge status={photo.current.status} label={label(`statuses.${photo.current.status}`)} />
                                    <VersionMeta version={photo.current} />
                                    <Actions version={photo.current} {...actions} />
                                </div>
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-(--app-muted-foreground)">{label('noPhoto')}</p>
                        )}
                        {photo && <History history={photo.history} />}
                    </div>
                )}

                {evidence.signature && (
                    <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                        <div className="flex items-start justify-between gap-3">
                            <h4 className="text-sm font-semibold text-(--app-foreground)">{label('signature')}</h4>
                            {evidence.signature.can_upload && (
                                <Button size="xs" variant="outline" onClick={() => open({ type: 'upload', kind: 'signature', url: evidence.signature!.upload_url })}>
                                    {signature?.current ? label('replaceSignature') : label('uploadSignature')}
                                </Button>
                            )}
                        </div>
                        {signature?.current ? (
                            <div className="mt-3 space-y-2">
                                <StatusBadge status={signature.current.status} label={label(`statuses.${signature.current.status}`)} />
                                <VersionMeta version={signature.current} />
                                {signature.current.urls.file && (signatureShown ? (
                                    <img src={signature.current.urls.file} alt={label('signature')} className="max-h-16 max-w-56 rounded-control border border-(--app-border) bg-white p-1" />
                                ) : (
                                    <Button size="xs" variant="outline" onClick={() => setSignatureShown(true)}>{label('showSignature')}</Button>
                                ))}
                                <p className="text-xs text-(--app-muted-foreground)">{label('signatureRestricted')}</p>
                                <Actions version={signature.current} {...actions} />
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-(--app-muted-foreground)">{label('noSignature')}</p>
                        )}
                        {signature && <History history={signature.history} />}
                    </div>
                )}
            </div>

            {evidence.documents && (
                <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <h4 className="text-sm font-semibold text-(--app-foreground)">{label('documents')}</h4>
                        {evidence.documents.can_upload && (
                            <Button size="xs" variant="primary" onClick={() => open({ type: 'upload', kind: 'document', url: evidence.documents!.upload_url })}>{label('uploadDocument')}</Button>
                        )}
                    </div>
                    {evidence.documents.items.length === 0 ? (
                        <p className="mt-3 text-sm text-(--app-muted-foreground)">{label('noDocuments')}</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-(--app-border)">
                            {evidence.documents.items.map((item) => {
                                const shown = item.current ?? item.history[0];
                                return (
                                    <li key={shown.lineage_id} className="py-3">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-(--app-foreground)">
                                                    {label(`types.${shown.document_type}`)}
                                                    {shown.title && <span className="font-normal text-(--app-muted-foreground)"> · {shown.title}</span>}
                                                </p>
                                                <p className="truncate text-xs text-(--app-muted-foreground)" title={shown.original_filename}>{shown.original_filename}</p>
                                                <VersionMeta version={shown} />
                                            </div>
                                            <div className="flex flex-col items-end gap-2">
                                                <StatusBadge status={shown.status} label={label(`statuses.${shown.status}`)} />
                                                {item.current && <Actions version={item.current} {...actions} />}
                                            </div>
                                        </div>
                                        <History history={item.current ? item.history : item.history.slice(1)} />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}

            <AppDialog
                open={dialog !== null}
                onClose={() => setDialog(null)}
                maxWidth="lg"
                title={dialog?.type === 'reject' ? label('rejectTitle') : dialog?.type === 'archive' ? label('archiveTitle') : dialog?.type === 'replace' ? label('replaceTitle') : label(`upload.${fileKind}`)}
                description={dialog?.type === 'replace' ? label('replaceDescription') : dialog?.type === 'archive' ? label('archiveDescription') : undefined}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setDialog(null)} disabled={form.processing}>{label('cancel')}</Button>
                        <Button variant={dialog?.type === 'archive' || dialog?.type === 'reject' ? 'destructive' : 'primary'} type="submit" form="employee-evidence-form" loading={form.processing}>
                            {dialog?.type === 'reject' ? label('reject') : dialog?.type === 'archive' ? label('archive') : label('save')}
                        </Button>
                    </>
                )}
            >
                <form id="employee-evidence-form" onSubmit={submit} className="space-y-4">
                    {(dialog?.type === 'upload' || dialog?.type === 'replace') && (
                        <>
                            {dialog.type === 'upload' && dialog.kind === 'document' && (
                                <>
                                    <FormField label={label('documentType')} required error={errors.document_type}>
                                        {({ id }) => (
                                            <Select id={id} value={form.data.document_type} onChange={(event) => form.setData('document_type', event.target.value)}>
                                                <option value="">{label('chooseType')}</option>
                                                {evidence.document_types.map((type) => <option key={type} value={type}>{label(`types.${type}`)}</option>)}
                                            </Select>
                                        )}
                                    </FormField>
                                    <FormField label={label('documentTitle')} error={errors.title}>
                                        {({ id }) => <Input id={id} maxLength={200} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />}
                                    </FormField>
                                </>
                            )}
                            {fileKind === 'document' && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label={label('issuedAt')} error={errors.issued_at}>
                                        {({ id }) => <LocalizedDatePicker id={id} value={form.data.issued_at} onChange={(value) => form.setData('issued_at', value)} />}
                                    </FormField>
                                    <FormField label={label('expiresAt')} error={errors.expires_at}>
                                        {({ id }) => <LocalizedDatePicker id={id} value={form.data.expires_at} onChange={(value) => form.setData('expires_at', value)} />}
                                    </FormField>
                                </div>
                            )}
                            <FormField label={label('file')} required description={label(`fileHint.${fileKind}`).replace(':kb', String(maxKb)).replace(':px', String(evidence.limits.photo_min_pixels))} error={errors.file ?? errors.document}>
                                {({ id, describedBy }) => (
                                    <input id={id} aria-describedby={describedBy} type="file" accept={accept} className="block w-full text-sm"
                                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                                )}
                            </FormField>
                        </>
                    )}
                    {dialog?.type === 'reject' && (
                        <FormField label={label('rejectReason')} required error={errors.notes ?? errors.document}>
                            {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />}
                        </FormField>
                    )}
                    {dialog?.type === 'archive' && (
                        <FormField label={label('archiveReason')} required error={errors.reason ?? errors.document}>
                            {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />}
                        </FormField>
                    )}
                </form>
            </AppDialog>

            <ConfirmDialog
                open={verifying !== null}
                onClose={() => setVerifying(null)}
                title={label('verifyTitle')}
                description={label('verifyDescription')}
                confirmLabel={label('verify')}
                cancelLabel={label('cancel')}
                onConfirm={() => verifying && router.post(verifying.urls.verify, {}, { preserveScroll: true, onFinish: () => setVerifying(null) })}
            />
            <ConfirmDialog
                open={discarding !== null}
                onClose={() => setDiscarding(null)}
                destructive
                title={label('discardTitle')}
                description={label('discardDescription')}
                confirmLabel={label('discard')}
                cancelLabel={label('cancel')}
                onConfirm={() => discarding && router.delete(discarding.urls.discard, { preserveScroll: true, onFinish: () => setDiscarding(null) })}
            />
        </section>
    );
}
