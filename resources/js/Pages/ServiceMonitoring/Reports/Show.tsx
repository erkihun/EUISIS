import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useConfirm } from '@/Components/ConfirmProvider';
import { Button, Card, CardHeader, CardTitle, FieldError } from '@/Components/ui';
import FormRenderer from '@/Components/serviceMonitoring/FormRenderer';
import ReportIdentity from '@/Components/serviceMonitoring/ReportIdentity';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { ReportDetail } from '@/Components/serviceMonitoring/report';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { JSX } from 'react';

/** A report read-only, with the reviewer's accept / return decision. */
export default function ServiceMonitoringReportShow({ report, can }: { report: ReportDetail; can: { update: boolean; review: boolean } }): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const title = `${t('serviceMonitoring.reports.showTitle')} — ${nameOf(report.organization, locale)}`;

    async function review(decision: 'accept' | 'return') {
        const result = await confirm({
            title: t(decision === 'accept' ? 'serviceMonitoring.actions.accept' : 'serviceMonitoring.actions.return'),
            description: nameOf(report.organization, locale),
            variant: decision === 'accept' ? 'success' : 'warning',
            requireReason: decision === 'return',
            reasonLabel: decision === 'return' ? t('serviceMonitoring.reports.reviewNoteRequired') : undefined,
        });
        if (!result.confirmed) return;
        router.post(route('service-monitoring.reports.review', report.id), { decision, note: result.reason ?? null }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={nameOf(report.form, locale)} backHref={route('service-monitoring.reports.index')} actions={<>
            {report.attachment && <Button as="a" href={report.attachment.url} variant="outline">{t('serviceMonitoring.actions.download')}</Button>}
            {can.review && <Button variant="outline" onClick={() => review('return')}>{t('serviceMonitoring.actions.return')}</Button>}
            {can.review && <Button variant="primary" onClick={() => review('accept')}>{t('serviceMonitoring.actions.accept')}</Button>}
            {can.update && <Button as={Link} href={route('service-monitoring.reports.edit', report.id)} variant="primary">{t('serviceMonitoring.actions.fill')}</Button>}
        </>} />}>
            <Head title={title} />
            <div className="space-y-6">
                <ReportIdentity report={report} />
                {(errors.status || errors.note) && <FieldError>{errors.status ?? errors.note}</FieldError>}
                {!report.attachment && <p className="text-sm text-(--app-muted-foreground)">{t('serviceMonitoring.reports.noAttachment')}</p>}
                <Card className="space-y-4 p-4">
                    <CardHeader className="p-0"><CardTitle>{nameOf(report.form, locale)}</CardTitle></CardHeader>
                    <FormRenderer schema={report.schema} data={report.data ?? {}} />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
