import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, Card, FieldError, FormDescription, FormField, FormLabel, Input, Select, Textarea, buttonClassName, cx } from '@/Components/ui';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionFindingFormProps } from '@/court/types/inspections';

/**
 * One form for a new and an existing inspection finding: the request, date,
 * severity, title, details and the optional PDF attachment (multipart).
 */
export default function InspectionFindingForm({ finding, requests, prefill, severities, today }: InspectionFindingFormProps) {
    const { t, tx, text, locale } = useInspectionPagesText();
    const system = useCalendarSystem();
    const editing = finding !== null;
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{
        case_inspection_request_id: string;
        finding_date: string;
        severity: string;
        title: string;
        details: string;
        attachment_pdf: File | null;
    }>({
        case_inspection_request_id: finding ? String(finding.request_id) : prefill.request_id,
        finding_date: finding?.finding_date ?? today,
        severity: finding?.severity ?? 'medium',
        title: finding?.title ?? '',
        details: finding?.details ?? '',
        attachment_pdf: null,
    });
    const title = editing ? t('cm.case_inspections.findings.edit_title') : t('cm.case_inspections.findings.create_title');

    /** "date - CASE-123 - subject", as the Blade form labelled a request, the date in the viewer's calendar. */
    const requestLabel = (request: InspectionFindingFormProps['requests'][number]): string =>
        [request.request_date ? formatDateDisplay(request.request_date, system, locale) : null, request.case_number, request.subject].filter(Boolean).join(' - ');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // The attachment goes as multipart; without a new file the field is left out so the current one is kept.
        form.transform((data) => {
            const { attachment_pdf, ...rest } = data;
            return attachment_pdf ? { ...rest, attachment_pdf } : rest;
        });
        const options = { forceFormData: true, onSuccess: () => { if (fileInput.current) fileInput.current.value = ''; } };
        if (editing) form.patch(route('court.case-inspection-findings.update', finding.id), options);
        else form.post(route('court.case-inspection-findings.store'), options);
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.case_inspections.findings.index_description')} backHref={route('court.case-inspection-findings.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl" encType="multipart/form-data">
                <Card className="space-y-5">
                    <FormField label={t('cm.case_inspections.findings.form.request')} required error={form.errors.case_inspection_request_id}>
                        {({ id }) => (
                            <Select id={id} value={form.data.case_inspection_request_id} onChange={(event) => form.setData('case_inspection_request_id', event.target.value)} required>
                                <option value="">{t('cm.case_inspections.findings.form.select_request')}</option>
                                {requests.map((request) => <option key={request.id} value={String(request.id)}>{requestLabel(request)}</option>)}
                            </Select>
                        )}
                    </FormField>

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={t('cm.case_inspections.findings.form.finding_date')} required error={form.errors.finding_date}>
                            {({ id }) => <LocalizedDatePicker id={id} value={form.data.finding_date} onChange={(value) => form.setData('finding_date', value)} required />}
                        </FormField>
                        <FormField label={t('cm.case_inspections.findings.form.severity')} required error={form.errors.severity}>
                            {({ id }) => (
                                <Select id={id} value={form.data.severity} onChange={(event) => form.setData('severity', event.target.value)} required>
                                    {severities.map((item) => <option key={item} value={item}>{tx(`cm.case_inspections.severity.${item}`, item)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    </div>

                    <FormField label={t('cm.case_inspections.findings.form.title')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={255} required />}
                    </FormField>

                    <FormField label={t('cm.case_inspections.findings.form.details')} required error={form.errors.details}>
                        {({ id }) => <Textarea id={id} rows={7} value={form.data.details} onChange={(event) => form.setData('details', event.target.value)} required />}
                    </FormField>

                    <div className="space-y-1.5">
                        <FormLabel htmlFor="attachment_pdf">{t('cm.case_inspections.findings.form.attachment_pdf')}</FormLabel>
                        <input id="attachment_pdf" ref={fileInput} type="file" name="attachment_pdf" accept="application/pdf"
                            onChange={(event) => form.setData('attachment_pdf', event.target.files?.[0] ?? null)}
                            className={cx('block text-sm text-(--app-muted-foreground)', 'file:mr-3 file:rounded-control file:border-0 file:bg-(--app-border) file:px-4 file:py-2 file:text-sm file:font-medium file:text-(--app-foreground)')} />
                        <FormDescription>{t('cm.case_inspections.findings.form.attachment_hint')}</FormDescription>
                        {form.data.attachment_pdf && <FormDescription>{text('attachment_selected')}: {form.data.attachment_pdf.name}</FormDescription>}
                        {editing && finding.has_attachment && (
                            <p className="text-xs">
                                <a href={route('court.case-inspection-findings.attachment', finding.id)} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                                    {t('cm.case_inspections.findings.form.current_attachment')}: {finding.attachment_name ?? t('cm.case_inspections.findings.labels.download_attachment')}
                                </a>
                                <span className="ml-2 text-(--app-muted-foreground)">{text('attachment_keep')}</span>
                            </p>
                        )}
                        <FieldError>{form.errors.attachment_pdf}</FieldError>
                    </div>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.case-inspection-findings.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.case_inspections.findings.update') : t('cm.case_inspections.findings.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
