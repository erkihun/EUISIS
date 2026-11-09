import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Button, Card, FormField, Input, Select, Textarea, buttonClassName } from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionRequestFormProps } from '@/court/types/inspections';
import { caseLabel } from './inspectionUi';

/**
 * One form for a new and an existing inspection request: case, date, status,
 * subject, the inspector (for those who may assign) and the note.
 */
export default function InspectionRequestForm({ request, cases, inspectors, canAssign, prefill, statuses, today }: InspectionRequestFormProps) {
    const { t, tx } = useInspectionPagesText();
    const editing = request !== null;
    const form = useForm({
        court_case_id: request ? String(request.case_id ?? '') : prefill.case_id,
        request_date: request?.request_date ?? today,
        status: request?.status ?? 'pending',
        subject: request?.subject ?? '',
        assigned_inspector_user_id: request ? String(request.assigned_inspector_user_id ?? '') : prefill.assigned_inspector_user_id,
        request_note: request?.request_note ?? '',
    });
    const title = editing ? t('cm.case_inspections.requests.edit_title') : t('cm.case_inspections.requests.create_title');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Empty selects post as null, as the Blade form's empty options did.
        form.transform((data) => ({
            ...data,
            assigned_inspector_user_id: data.assigned_inspector_user_id === '' ? null : data.assigned_inspector_user_id,
            request_note: data.request_note === '' ? null : data.request_note,
        }));
        if (editing) form.patch(route('court.case-inspection-requests.update', request.id));
        else form.post(route('court.case-inspection-requests.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.case_inspections.requests.index_description')} backHref={route('court.case-inspection-requests.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.case_inspections.requests.form.case')} required error={form.errors.court_case_id}>
                        {({ id }) => (
                            <Select id={id} value={form.data.court_case_id} onChange={(event) => form.setData('court_case_id', event.target.value)} required>
                                <option value="">{t('cm.case_inspections.requests.form.select_case')}</option>
                                {cases.map((item) => <option key={item.id} value={String(item.id)}>{caseLabel(item.case_number, item.title)}</option>)}
                            </Select>
                        )}
                    </FormField>

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={t('cm.case_inspections.requests.form.request_date')} required error={form.errors.request_date}>
                            {({ id }) => <LocalizedDatePicker id={id} value={form.data.request_date} onChange={(value) => form.setData('request_date', value)} required />}
                        </FormField>
                        <FormField label={t('cm.case_inspections.requests.form.status')} required error={form.errors.status}>
                            {({ id }) => (
                                <Select id={id} value={form.data.status} onChange={(event) => form.setData('status', event.target.value)} required>
                                    {statuses.map((item) => <option key={item} value={item}>{tx(`cm.case_inspections.status.${item}`, item)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    </div>

                    <FormField label={t('cm.case_inspections.requests.form.subject')} required error={form.errors.subject}>
                        {({ id }) => <Input id={id} value={form.data.subject} onChange={(event) => form.setData('subject', event.target.value)} maxLength={255} required />}
                    </FormField>

                    {canAssign && (
                        <FormField label={t('cm.case_inspections.requests.form.assigned_inspector')} error={form.errors.assigned_inspector_user_id}>
                            {({ id }) => (
                                <Select id={id} value={form.data.assigned_inspector_user_id} onChange={(event) => form.setData('assigned_inspector_user_id', event.target.value)}>
                                    <option value="">{t('cm.case_inspections.requests.form.unassigned')}</option>
                                    {inspectors.map((inspector) => <option key={inspector.id} value={String(inspector.id)}>{inspector.name}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}

                    <FormField label={t('cm.case_inspections.requests.form.request_note')} error={form.errors.request_note}>
                        {({ id }) => <Textarea id={id} rows={6} value={form.data.request_note} onChange={(event) => form.setData('request_note', event.target.value)} />}
                    </FormField>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.case-inspection-requests.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('cm.case_inspections.requests.update') : t('cm.case_inspections.requests.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
