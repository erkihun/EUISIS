import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useConfirm } from '@/Components/ConfirmProvider';
import { Alert, Button, Card, FileUpload } from '@/Components/ui';
import FormRenderer from '@/Components/serviceMonitoring/FormRenderer';
import ReportIdentity from '@/Components/serviceMonitoring/ReportIdentity';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { ReportDetail } from '@/Components/serviceMonitoring/report';
import type { ReportData } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { JSX } from 'react';

type Payload = { data: ReportData; submit: boolean; attachment: File | null };

/** An institution fills its report: save as a draft any time, submit once complete. */
export default function ServiceMonitoringReportEdit({ report }: { report: ReportDetail }): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const form = useForm<Payload>({ data: report.data ?? {}, submit: false, attachment: null });
    const errors = form.errors as Record<string, string | undefined>;
    const title = `${t('serviceMonitoring.reports.editTitle')} — ${nameOf(report.organization, locale)}`;

    function save(submit: boolean) {
        // Multipart carries the answers as one JSON field; the request decodes it.
        form.transform((payload) => ({ ...payload, submit, data: JSON.stringify(payload.data) }) as unknown as Payload);
        form.post(route('service-monitoring.reports.update', report.id), { forceFormData: true, preserveScroll: true });
    }

    async function submit() {
        const { confirmed } = await confirm({ title: t('serviceMonitoring.actions.submit'), description: t('serviceMonitoring.reports.submitHelp'), variant: 'warning' });
        if (confirmed) save(true);
    }

    const hasAnswerErrors = Object.keys(errors).some((key) => key.startsWith('data.'));

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={nameOf(report.form, locale)} backHref={route('service-monitoring.reports.show', report.id)} />}>
            <Head title={title} />
            <div className="space-y-6">
                <ReportIdentity report={report} />
                {(hasAnswerErrors || errors.status || errors.period_id) && (
                    <Alert tone="danger">{errors.status ?? errors.period_id ?? t('serviceMonitoring.forms.builderErrors')}</Alert>
                )}

                <Card className="p-4">
                    <FormRenderer schema={report.schema} data={form.data.data} errors={errors} onChange={(data) => form.setData('data', data)} />
                </Card>

                <Card className="space-y-2 p-4">
                    <FileUpload label={t('serviceMonitoring.reports.attachment')} accept=".pdf,.jpg,.jpeg,.png" maxSizeMb={10} value={form.data.attachment}
                        error={errors.attachment} onChange={(file) => form.setData('attachment', file)} onRemove={() => form.setData('attachment', null)} />
                    <p className="text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.reports.attachmentHelp')}</p>
                    {report.attachment && (
                        <p className="text-sm">
                            {t('serviceMonitoring.reports.attachmentCurrent')}: <a href={report.attachment.url} className="text-(--color-primary) hover:underline">{report.attachment.name}</a>
                        </p>
                    )}
                </Card>

                <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                    <Button as={Link} href={route('service-monitoring.reports.index')} variant="outline">{t('serviceMonitoring.actions.cancel')}</Button>
                    <Button type="button" variant="secondary" loading={form.processing} onClick={() => save(false)}>{t('serviceMonitoring.actions.saveDraft')}</Button>
                    <Button type="button" variant="primary" loading={form.processing} onClick={submit}>{t('serviceMonitoring.actions.submit')}</Button>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
