import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    Alert, Button, Card, FieldError, FormField, Input, Select, actionLinkClassName, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { CASE_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseEditProps } from '@/court/types/applicantCases';
import {
    CheckField, ErrorSummary, EvidenceRows, RowsCard, WitnessRows, casePrefix, firstError, newEvidenceRow, newWitnessRow,
    type EvidenceRow, type WitnessRow,
} from './Partials/FormParts';

interface EditForm {
    title: string;
    filing_date: string;
    case_type_id: string;
    respondent_name: string;
    respondent_address: string;
    description: string;
    relief_requested: string;
    certify_appeal: boolean;
    evidence: EvidenceRow[];
    witnesses: WitnessRow[];
    certify_evidence: boolean;
}

/**
 * The edit form of a pending case: the core fields (title, filing date, case
 * type with the case number format, respondent, details and relief), new
 * evidence PDFs and new witnesses, the certifications; on the right the
 * documents and witnesses already filed, each removable while the case is
 * pending. Saving sends the case back for review.
 */
export default function ApplicantCaseEdit({ case: kase, editable, types, docs, witnesses, yearSuffix }: ApplicantCaseEditProps) {
    const { t, tx, fill, text, statusLabel } = useApplicantCaseText();
    const { confirm } = useConfirm();
    const [removing, setRemoving] = useState<string | null>(null);
    const form = useForm<EditForm>({
        title: kase.title,
        filing_date: kase.filing_date ?? '',
        case_type_id: kase.case_type_id !== null ? String(kase.case_type_id) : '',
        respondent_name: kase.respondent_name,
        respondent_address: kase.respondent_address,
        description: kase.description,
        relief_requested: kase.relief_requested,
        certify_appeal: true,
        evidence: [newEvidenceRow()],
        witnesses: [newWitnessRow()],
        certify_evidence: true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const title = fill(t('cm.cases.edit_title'), { no: kase.case_number });
    const prefix = casePrefix(types.find((type) => String(type.id) === form.data.case_type_id));
    const disabled = !editable;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!editable) return;
        form.transform(({ evidence, witnesses: rows, ...data }) => {
            // Only the rows that carry a file are new documents; empty witness rows are skipped by the server as well.
            const withFiles = evidence.filter((row) => row.file !== null);
            return {
                ...data,
                _method: 'patch',
                evidence_titles: withFiles.map((row) => row.title),
                evidence_files: withFiles.map((row) => row.file),
                witnesses: rows.map(({ full_name, phone, email, address }) => ({ full_name, phone, email, address })),
            };
        });
        // Multipart PATCH goes as POST with _method (PHP reads no files from a real PATCH body).
        form.post(route('court.applicant.cases.update', kase.id), { forceFormData: true });
    };

    const removeDocument = async (id: number, name: string) => {
        const result = await confirm({ title: t('cm.cases.confirm.remove_document'), description: name, variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.evidences.delete', { id: kase.id, evidenceId: id }), {
            preserveScroll: true, onStart: () => setRemoving(`doc-${id}`), onFinish: () => setRemoving(null),
        });
    };
    const removeWitness = async (id: number, name: string) => {
        const result = await confirm({ title: t('cm.cases.confirm.remove_witness'), description: name, variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.witnesses.delete', { id: kase.id, witnessId: id }), {
            preserveScroll: true, onStart: () => setRemoving(`witness-${id}`), onFinish: () => setRemoving(null),
        });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.cases.hints.edit_only_pending')} backHref={route('court.applicant.cases.show', kase.id)} />}>
            <Head title={title} />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <form onSubmit={submit} className="space-y-5 lg:col-span-2" encType="multipart/form-data">
                    <ErrorSummary errors={errors} />

                    <Card className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div>
                                    <div className="text-xs text-(--app-muted-foreground)">{t('cm.cases.case_number_short')}</div>
                                    <div className="text-xl font-semibold tracking-tight text-(--app-foreground)">{kase.case_number}</div>
                                </div>
                                <span className={badgeClassName(CASE_STATUS_TONES[kase.status] ?? 'neutral')}>{statusLabel(kase.status)}</span>
                            </div>
                            <Link href={route('court.applicant.cases.show', kase.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.back_to_case')}</Link>
                        </div>
                        {!editable && (
                            <Alert tone="warning">{kase.status !== 'pending' ? t('cm.cases.not_pending_readonly') : t('cm.cases.review_notice')}</Alert>
                        )}
                    </Card>

                    <Card className="space-y-5">
                        <h2 className="text-sm font-semibold text-(--app-foreground)">{t('cm.cases.section.case_details')}</h2>

                        <FormField label={t('cm.cases.labels.title')} required error={errors.title}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required disabled={disabled} aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                            )}
                        </FormField>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <FormField label={t('cm.cases.labels.filing_date')} required error={errors.filing_date}>
                                {({ id }) => <LocalizedDatePicker id={id} value={form.data.filing_date} onChange={(value) => form.setData('filing_date', value)} required disabled={disabled} />}
                            </FormField>
                            <FormField
                                label={t('cm.cases.labels.case_type')}
                                required
                                error={errors.case_type_id}
                                description={<>{tx('cm.json.Case number format', 'Case number format')}: <span className="font-mono text-(--app-foreground)">{`${prefix}/00001/${yearSuffix}`}</span> {text('case_number_format_hint')}</>}
                            >
                                {({ id, describedBy, invalid }) => (
                                    <Select id={id} value={form.data.case_type_id} onChange={(event) => form.setData('case_type_id', event.target.value)} required disabled={disabled} aria-describedby={describedBy} aria-invalid={invalid || undefined}>
                                        <option value="">{t('cm.cases.placeholders.select')}</option>
                                        {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                                    </Select>
                                )}
                            </FormField>
                        </div>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <FormField label={t('cm.cases.labels.respondent_name')} error={errors.respondent_name}>
                                {({ id }) => <Input id={id} value={form.data.respondent_name} onChange={(event) => form.setData('respondent_name', event.target.value)} disabled={disabled} />}
                            </FormField>
                            <FormField label={t('cm.cases.labels.respondent_address')} error={errors.respondent_address}>
                                {({ id }) => <Input id={id} value={form.data.respondent_address} onChange={(event) => form.setData('respondent_address', event.target.value)} disabled={disabled} />}
                            </FormField>
                        </div>

                        <RichTextEditor label={t('cm.cases.labels.description')} required preset="document" minHeight={520} value={form.data.description}
                            onChange={(html) => form.setData('description', html)} error={errors.description} disabled={disabled} />
                        <RichTextEditor label={t('cm.cases.labels.relief_requested')} preset="document" minHeight={380} value={form.data.relief_requested}
                            onChange={(html) => form.setData('relief_requested', html)} error={errors.relief_requested} disabled={disabled} />
                        <CheckField id="certify-appeal" checked={form.data.certify_appeal} onChange={(checked) => form.setData('certify_appeal', checked)} required disabled={disabled} error={errors.certify_appeal}>
                            {t('cm.cases.certify_appeal')}
                        </CheckField>
                    </Card>

                    <RowsCard title={t('cm.cases.section.add_documents')} addLabel={t('cm.cases.buttons.add_row')} disabled={disabled}
                        onAdd={() => form.setData('evidence', [...form.data.evidence, newEvidenceRow()])}>
                        <EvidenceRows idPrefix="evidence" rows={form.data.evidence} onChange={(rows) => form.setData('evidence', rows)} disabled={disabled} titlePlaceholder={t('cm.cases.placeholders.document_title')} />
                        <FieldError>{firstError(errors, 'evidence_files', 'evidence_titles')}</FieldError>
                    </RowsCard>

                    <RowsCard title={t('cm.cases.section.witnesses')} addLabel={t('cm.cases.buttons.add_witness')} disabled={disabled}
                        onAdd={() => form.setData('witnesses', [...form.data.witnesses, newWitnessRow()])}>
                        <WitnessRows
                            idPrefix="witness"
                            rows={form.data.witnesses}
                            onChange={(rows) => form.setData('witnesses', rows)}
                            withEmail
                            disabled={disabled}
                            placeholders={{
                                full_name: t('cm.cases.placeholders.full_name'),
                                phone: t('cm.cases.placeholders.phone'),
                                email: t('cm.cases.placeholders.email'),
                                address: t('cm.cases.placeholders.address'),
                            }}
                        />
                        <FieldError>{firstError(errors, 'witnesses', 'witnesses_duplicate_phone', 'witnesses_duplicate_email')}</FieldError>
                    </RowsCard>

                    <Card className="space-y-4">
                        <CheckField id="certify-evidence" checked={form.data.certify_evidence} onChange={(checked) => form.setData('certify_evidence', checked)} required disabled={disabled} error={errors.certify_evidence}>
                            {t('cm.cases.certify_evidence')}
                        </CheckField>
                        <div className="border-t border-(--app-border) pt-4">
                            <Button type="submit" variant="primary" className="w-full" loading={form.processing} disabled={disabled}>{t('cm.cases.buttons.save_changes')}</Button>
                            <p className="mt-2 text-xs text-(--app-muted-foreground)">
                                {editable ? t('cm.cases.hints.edit_only_pending') : (kase.status !== 'pending' ? t('cm.cases.hints.editing_disabled') : t('cm.cases.review_notice'))}
                            </p>
                        </div>
                    </Card>
                </form>

                <aside className="space-y-5">
                    <Card className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.cases.section.existing_documents')}</h3>
                            <span className="text-[11px] text-(--app-muted-foreground)">{docs.length} {t('cm.cases.total_suffix')}</span>
                        </div>
                        {docs.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('cm.cases.empty.no_documents')}</p>
                        ) : (
                            <div className="-mx-2 overflow-x-auto">
                                <table className={cx(tableClassName, 'min-w-full text-left')}>
                                    <thead className={tableHeadClassName}>
                                        <tr>
                                            <th className={tableHeaderCellClassName}>{t('cm.cases.table.title')}</th>
                                            <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.table.actions')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className={tableBodyClassName}>
                                        {docs.map((doc) => {
                                            const name = doc.title ?? t('cm.cases.labels.document');
                                            return (
                                                <tr key={doc.id} className={tableRowClassName}>
                                                    <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                                        {name}
                                                        {!doc.has_file && <span className="ml-2 text-[11px] text-(--app-muted-foreground)">({t('cm.cases.inline')})</span>}
                                                    </td>
                                                    <td className={cx(tableCellClassName, 'text-right')}>
                                                        <div className="inline-flex items-center gap-2">
                                                            {doc.has_file && (
                                                                <a href={route('court.applicant.cases.evidences.download', { id: kase.id, evidenceId: doc.id })} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.cases.table.view')}</a>
                                                            )}
                                                            {editable && (
                                                                <button type="button" onClick={() => removeDocument(doc.id, name)} disabled={removing === `doc-${doc.id}`} className={actionLinkClassName({ tone: 'danger' })}>{t('cm.cases.general.delete')}</button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('cm.cases.section.existing_witnesses')}</h3>
                            <span className="text-[11px] text-(--app-muted-foreground)">{witnesses.length} {t('cm.cases.total_suffix')}</span>
                        </div>
                        {witnesses.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('cm.cases.empty.no_witnesses')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {witnesses.map((witness) => (
                                    <li key={witness.id} className="flex items-start justify-between gap-3 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <div className="font-medium text-(--app-foreground)">{witness.full_name}</div>
                                            <dl className="mt-0.5 space-y-0.5 text-xs text-(--app-muted-foreground)">
                                                <div><dt className="inline">{t('cm.cases.labels.phone')}: </dt><dd className="inline">{witness.phone ?? '—'}</dd></div>
                                                <div>
                                                    <dt className="inline">{t('cm.cases.labels.email')}: </dt>
                                                    <dd className="inline">{witness.email ? <a href={`mailto:${witness.email}`} className="text-primary hover:underline">{witness.email}</a> : '—'}</dd>
                                                </div>
                                                <div><dt className="inline">{t('cm.cases.labels.address')}: </dt><dd className="inline">{witness.address ?? '—'}</dd></div>
                                            </dl>
                                        </div>
                                        {editable && (
                                            <button type="button" onClick={() => removeWitness(witness.id, witness.full_name)} disabled={removing === `witness-${witness.id}`} className={actionLinkClassName({ tone: 'danger', className: 'shrink-0' })}>{t('cm.cases.general.delete')}</button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
