import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { localizedName } from '@/utils/localizedName';
import { Alert, Button, Card, CardHeader, CardTitle, FileUpload, FormField, Select, Textarea, buttonClassName } from '@euisis/ui';
import type { PageProps } from '@/types';

/**
 * Apply for a transfer, inside the portal.
 *
 * Posts to the portal's own endpoint, which shares SubmitTransferApplicationAction
 * with the public flow — only the chrome and the redirect target differ. The
 * server matches files to required documents by position, so each required
 * document gets its own labelled slot, and a missing one is caught here before
 * the upload rather than after it.
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

const ACCEPT = '.pdf,.jpg,.jpeg,.png';
const COVER_LETTER_MAX = 3000;

export default function AnnouncementApply({ announcement, employee_context, employee_documents }: Props) {
    const { t, locale } = useLocale();
    const { organizationName } = useDisplayFormat();
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';
    const position = name(announcement.position_title_en, announcement.position_title_am);
    const showHref = route('employee.announcements.show', { announcement: announcement.id });

    const eligiblePositions = announcement.positions.filter((item) => item.eligible !== false);
    const requiresPositionSelection = announcement.positions.some((item) => item.id !== null);
    const requiredDocuments = announcement.required_documents ?? [];
    const form = useForm<{ announcement_position_id: string; cover_letter: string; documents: Array<File | null>; reuse_document_ids: string[] }>({
        announcement_position_id: eligiblePositions.length === 1 ? eligiblePositions[0].id ?? '' : '',
        cover_letter: '',
        documents: requiredDocuments.map(() => null),
        reuse_document_ids: requiredDocuments.map(() => ''),
    });
    const [missing, setMissing] = useState<number[]>([]);
    const errors = form.errors as Record<string, string | undefined>;

    const hasDocument = (index: number) => Boolean(form.data.documents[index] || form.data.reuse_document_ids[index]);

    function setFile(index: number, file: File | null) {
        form.setData((current) => ({
            ...current,
            documents: current.documents.map((value, i) => (i === index ? file : value)),
            // A new upload replaces a reused document for the same slot.
            reuse_document_ids: file ? current.reuse_document_ids.map((value, i) => (i === index ? '' : value)) : current.reuse_document_ids,
        }));
        if (file) setMissing((current) => current.filter((i) => i !== index));
    }

    function setReuse(index: number, id: string) {
        form.setData((current) => ({
            ...current,
            reuse_document_ids: current.reuse_document_ids.map((value, i) => (i === index ? id : value)),
            documents: id ? current.documents.map((value, i) => (i === index ? null : value)) : current.documents,
        }));
        if (id) setMissing((current) => current.filter((i) => i !== index));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;

        const empty = requiredDocuments.map((_, index) => index).filter((index) => !hasDocument(index));
        setMissing(empty);
        if (empty.length > 0) {
            document.getElementById(`document-${empty[0]}`)?.focus();

            return;
        }

        form.post(route('employee.announcements.apply.store', { announcement: announcement.id }), { forceFormData: true, preserveScroll: true });
    }

    const slotError = (index: number) => (missing.includes(index) ? t('transfers.documentRequired') : errors[`documents.${index}`]);

    return (
        <PortalPage title={t('transfers.applyForTransfer')} description={position} backHref={showHref}>
            <form onSubmit={submit} noValidate className="space-y-4">
                {errors.application && <Alert tone="danger">{errors.application}</Alert>}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>{t('transfers.applyingFor')}</CardTitle></CardHeader>
                        <dl className="space-y-2 text-sm">
                            <Item label={t('transfers.position')}>{position}</Item>
                            <Item label={t('transfers.organization')}>{organizationName(announcement.organization_name_en, announcement.organization_name_am) || '—'}</Item>
                            {announcement.grade_level && <Item label={t('transfers.gradeLevel')}>{announcement.grade_level}</Item>}
                            {announcement.closing_date && <Item label={t('transfers.closes')}><LocalizedDateDisplay value={announcement.closing_date} /></Item>}
                        </dl>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{t('transfers.currentAssignment')}</CardTitle></CardHeader>
                        <dl className="space-y-2 text-sm">
                            <Item label={t('employees.employeeNumber')}>{employee_context.employee_number}</Item>
                            <Item label={t('common.name')}>{employee_context.employee_name}</Item>
                            <Item label={t('transfers.organization')}>{employee_context.organization ?? '—'}</Item>
                            <Item label={t('transfers.position')}>{[employee_context.position, employee_context.grade_level].filter(Boolean).join(' · ') || '—'}</Item>
                        </dl>
                    </Card>
                </div>

                <Card className="space-y-5">
                    {requiresPositionSelection && (
                        <FormField id="announcement_position_id" label={t('transfers.destinationPosition')} required error={errors.announcement_position_id}
                            description={eligiblePositions.length === 0 ? t('transfers.requirementsNotMet') : undefined}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.announcement_position_id}
                                    onChange={(e) => form.setData('announcement_position_id', e.target.value)}>
                                    <option value="">{t('transfers.selectPosition')}</option>
                                    {eligiblePositions.map((item, index) => (
                                        <option key={item.id ?? `legacy-${index}`} value={item.id ?? ''}>
                                            {name(item.position_title_en, item.position_title_am)}{item.position_code ? ` (${item.position_code})` : ''}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </FormField>
                    )}

                    <FormField id="cover_letter" label={`${t('transfers.coverLetter')} (${t('common.optional')})`} error={errors.cover_letter}
                        description={`${form.data.cover_letter.length} / ${COVER_LETTER_MAX}`}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} rows={6} maxLength={COVER_LETTER_MAX} aria-describedby={describedBy} aria-invalid={invalid}
                                placeholder={t('transfers.coverLetterPlaceholder')} value={form.data.cover_letter} onChange={(e) => form.setData('cover_letter', e.target.value)} />
                        )}
                    </FormField>

                    {requiredDocuments.length > 0 ? (
                        <fieldset className="space-y-4">
                            <legend className="text-sm font-medium text-(--app-foreground)">
                                {t('transfers.requiredDocuments')} <span className="font-normal text-(--app-muted-foreground)">({t('transfers.documentsRequiredHint')})</span>
                            </legend>
                            {requiredDocuments.map((type, index) => {
                                const reusable = employee_documents.filter((document) => document.document_type === type);

                                return (
                                    <div key={`${type}-${index}`} className="space-y-2 rounded-control border border-(--app-border) p-3">
                                        <FileUpload id={`document-${index}`} label={type} accept={ACCEPT} maxSizeMb={5}
                                            value={form.data.documents[index] ?? null} error={slotError(index)}
                                            onChange={(file) => setFile(index, file)} onRemove={() => setFile(index, null)} />
                                        {reusable.length > 0 && (
                                            <FormField id={`reuse-${index}`} label={t('transfers.orReuseEmployeeDocument')}>
                                                {({ id }) => (
                                                    <Select id={id} value={form.data.reuse_document_ids[index] ?? ''} onChange={(e) => setReuse(index, e.target.value)}>
                                                        <option value="">{t('transfers.uploadNewDocument')}</option>
                                                        {reusable.map((document) => <option key={document.id} value={document.id}>{document.name}</option>)}
                                                    </Select>
                                                )}
                                            </FormField>
                                        )}
                                    </div>
                                );
                            })}
                            {errors.documents && <p role="alert" className="text-sm text-red-600 dark:text-red-400">{errors.documents}</p>}
                        </fieldset>
                    ) : (
                        <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noRequiredDocumentsConfigured')}</p>
                    )}

                    <div className="flex flex-col-reverse gap-2 border-t border-(--app-border) pt-4 sm:flex-row sm:justify-end">
                        <Link href={showHref} className={buttonClassName({ variant: 'outline' })}>{t('common.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}
                            disabled={eligiblePositions.length === 0 || (requiresPositionSelection && !form.data.announcement_position_id)}>
                            {form.processing ? t('transfers.submitting') : t('transfers.submitApplication')}
                        </Button>
                    </div>
                </Card>
            </form>
        </PortalPage>
    );
}

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-(--app-muted-foreground)">{label}</dt>
            <dd className="text-end font-medium wrap-break-word text-(--app-foreground)">{children}</dd>
        </div>
    );
}
