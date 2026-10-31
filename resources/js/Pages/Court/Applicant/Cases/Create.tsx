import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { Button, Card, FieldError, FormField, Input, Select, buttonClassName } from '@/Components/ui';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseCreateProps } from '@/court/types/applicantCases';
import {
    CheckField, ErrorSummary, EvidenceRows, RowsCard, WitnessRows, firstError, newEvidenceRow, newWitnessRow,
    type EvidenceRow, type WitnessRow,
} from './Partials/FormParts';

interface CreateForm {
    title: string;
    applicant_address: string;
    respondent_name: string;
    respondent_address: string;
    respondent_organization_uuid: string;
    case_type_id: string;
    accept_terms: boolean;
    description: string;
    relief_requested: string;
    certify_appeal: boolean;
    evidence: EvidenceRow[];
    witnesses: WitnessRow[];
    certify_evidence: boolean;
    filing_date: string;
}

/** Today's date as the Blade form stamped it on submit (the browser's UTC date). */
const submitDay = (): string => new Date().toISOString().split('T')[0];

/**
 * The case filing form: the applicant (a lawyer types the name and address,
 * anyone else files in their own name), the respondent and the optional
 * directory organization, the case type, the published terms to accept, the
 * case details and relief requested (rich text), the evidence PDFs, the
 * witnesses and the two certifications. Posts multipart to applicant.cases.store.
 */
export default function ApplicantCaseCreate({ types, terms, applicant, organizations, today }: ApplicantCaseCreateProps) {
    const { t, text } = useApplicantCaseText();
    const form = useForm<CreateForm>({
        title: applicant.is_lawyer ? '' : applicant.name,
        applicant_address: applicant.is_lawyer ? '' : applicant.address,
        respondent_name: '',
        respondent_address: '',
        respondent_organization_uuid: '',
        case_type_id: '',
        accept_terms: false,
        description: '',
        relief_requested: '',
        certify_appeal: false,
        evidence: [newEvidenceRow()],
        witnesses: [newWitnessRow()],
        certify_evidence: false,
        filing_date: today,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const title = t('cm.cases.submit_case');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ evidence, witnesses, accept_terms, ...data }) => ({
            ...data,
            ...(terms ? { accept_terms } : {}),
            filing_date: submitDay(),
            evidence_titles: evidence.map((row) => row.title),
            evidence_files: evidence.map((row) => row.file),
            witnesses: witnesses.map(({ full_name, phone, address }) => ({ full_name, phone, address })),
        }));
        form.post(route('court.applicant.cases.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.applicant.cases.index')} />}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-5" encType="multipart/form-data">
                <ErrorSummary errors={errors} />

                <Card className="space-y-5">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label={t('cm.cases.applicant_name')} required error={errors.title}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} readOnly={!applicant.is_lawyer}
                                    placeholder={t('cm.cases.applicant_name_placeholder')} required aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                    className={applicant.is_lawyer ? undefined : 'bg-(--app-surface-muted)'} />
                            )}
                        </FormField>
                        <FormField label={t('cm.cases.applicant_address')} required error={errors.applicant_address}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} value={form.data.applicant_address} onChange={(event) => form.setData('applicant_address', event.target.value)} readOnly={!applicant.is_lawyer}
                                    placeholder={t('cm.cases.applicant_address_placeholder')} required aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                    className={applicant.is_lawyer ? undefined : 'bg-(--app-surface-muted)'} />
                            )}
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label={t('cm.cases.respondent_name')} required error={errors.respondent_name}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} value={form.data.respondent_name} onChange={(event) => form.setData('respondent_name', event.target.value)}
                                    placeholder={t('cm.cases.respondent_name_placeholder')} required aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                            )}
                        </FormField>
                        <FormField label={t('cm.cases.respondent_address')} required error={errors.respondent_address}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} value={form.data.respondent_address} onChange={(event) => form.setData('respondent_address', event.target.value)}
                                    placeholder={t('cm.cases.respondent_address_placeholder')} required aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                            )}
                        </FormField>
                    </div>

                    {organizations.length > 0 && (
                        <FormField label={t('cm.cases.respondent_organization_directory')} description={t('cm.cases.respondent_organization_directory_help')} error={errors.respondent_organization_uuid}>
                            {({ id, describedBy }) => (
                                <Select id={id} value={form.data.respondent_organization_uuid} onChange={(event) => form.setData('respondent_organization_uuid', event.target.value)} aria-describedby={describedBy}>
                                    <option value="">{t('cm.cases.respondent_organization_not_listed')}</option>
                                    {organizations.map((org) => <option key={org.uuid} value={org.uuid}>{org.name}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <FormField label={t('cm.cases.case_type')} required error={errors.case_type_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.case_type_id} onChange={(event) => form.setData('case_type_id', event.target.value)} required aria-describedby={describedBy} aria-invalid={invalid || undefined}>
                                    <option value="">-- {t('cm.cases.select_option')} --</option>
                                    {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                                </Select>
                            )}
                        </FormField>
                    </div>
                </Card>

                {terms && (
                    <Card className="space-y-3">
                        <div className="max-h-96 overflow-y-auto rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
                            <RichTextViewer html={terms.body_html} />
                        </div>
                        <CheckField id="accept-terms" checked={form.data.accept_terms} onChange={(checked) => form.setData('accept_terms', checked)} required error={errors.accept_terms}>
                            {t('cm.terms.applicant_checkbox')}
                        </CheckField>
                    </Card>
                )}

                <Card className="space-y-5">
                    <RichTextEditor label={t('cm.cases.case_details')} required preset="document" minHeight={520} value={form.data.description}
                        onChange={(html) => form.setData('description', html)} error={errors.description} />
                    <RichTextEditor label={t('cm.cases.relief_requested')} required preset="document" minHeight={380} value={form.data.relief_requested}
                        onChange={(html) => form.setData('relief_requested', html)} error={errors.relief_requested} />
                    <CheckField id="certify-appeal" checked={form.data.certify_appeal} onChange={(checked) => form.setData('certify_appeal', checked)} required error={errors.certify_appeal}>
                        {t('cm.cases.certify_appeal')}
                    </CheckField>
                </Card>

                <RowsCard
                    title={t('cm.cases.evidence_documents')}
                    required
                    hint={(
                        <>
                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{t('cm.cases.file_requirements')}</p>
                            <p className="mt-0.5 text-xs text-red-600 dark:text-red-400">{text('evidence_size_note')}</p>
                        </>
                    )}
                    addLabel={t('cm.cases.add_document')}
                    onAdd={() => form.setData('evidence', [...form.data.evidence, newEvidenceRow()])}
                >
                    <EvidenceRows idPrefix="evidence" rows={form.data.evidence} onChange={(rows) => form.setData('evidence', rows)} required titlePlaceholder={t('cm.cases.document_title_placeholder')} />
                    <FieldError>{firstError(errors, 'evidence_files', 'evidence_titles')}</FieldError>
                </RowsCard>

                <RowsCard
                    title={t('cm.cases.witnesses_section.title')}
                    required
                    addLabel={t('cm.cases.add_witness')}
                    onAdd={() => form.setData('witnesses', [...form.data.witnesses, newWitnessRow()])}
                >
                    <WitnessRows
                        idPrefix="witness"
                        rows={form.data.witnesses}
                        onChange={(rows) => form.setData('witnesses', rows)}
                        required
                        placeholders={{
                            full_name: t('cm.cases.witnesses_section.full_name_placeholder'),
                            phone: t('cm.cases.witnesses_section.phone_placeholder'),
                            address: t('cm.cases.witnesses_section.address_placeholder'),
                        }}
                    />
                    <FieldError>{firstError(errors, 'witnesses', 'witnesses_duplicate_phone')}</FieldError>
                </RowsCard>

                <Card className="space-y-4">
                    <CheckField id="certify-evidence" checked={form.data.certify_evidence} onChange={(checked) => form.setData('certify_evidence', checked)} required error={errors.certify_evidence}>
                        {t('cm.cases.certify_evidence')}
                    </CheckField>
                    <div className="flex flex-wrap gap-3 border-t border-(--app-border) pt-4">
                        <Button type="submit" variant="primary" loading={form.processing}>{t('cm.cases.submit_case_button')}</Button>
                        <Link href={route('court.applicant.cases.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.cancel')}</Link>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
