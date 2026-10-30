import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { Alert, Card } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { JSX, ReactNode } from 'react';
import { nameOf } from './helpers';
import type { ReportDetail } from './report';

/** The identity card of a report: institution, form, period, status and review. */
export default function ReportIdentity({ report }: { report: ReportDetail }): JSX.Element {
    const { t, locale } = useLocale();

    return (
        <Card className="space-y-3 p-4">
            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <Item label={t('serviceMonitoring.reports.institution')}>
                    {nameOf(report.organization, locale)}
                    {report.organization.type && <span className="block text-xs text-(--app-muted-foreground)">{nameOf(report.organization.type, locale)}</span>}
                </Item>
                <Item label={t('serviceMonitoring.reports.form')}>
                    {nameOf(report.form, locale)}
                    <span className="block text-xs text-(--app-muted-foreground)">{t('serviceMonitoring.reports.formVersion')} {report.form.version}</span>
                </Item>
                <Item label={t('serviceMonitoring.reports.period')}>{nameOf(report.period, locale)}</Item>
                <Item label={t('serviceMonitoring.reports.status')}>
                    <StatusBadge status={report.status} label={t(`serviceMonitoring.reportStatus.${report.status}`)} />
                    {report.submitted_at && (
                        <span className="mt-1 block text-xs text-(--app-muted-foreground)">
                            {t('serviceMonitoring.reports.submittedBy')}: {report.submitter ?? '—'} · <LocalizedDateDisplay value={report.submitted_at} withTime />
                        </span>
                    )}
                </Item>
            </dl>
            {report.description && <p className="whitespace-pre-line text-xs text-(--app-muted-foreground)">{report.description}</p>}
            {!report.period_open && <Alert tone="warning">{t('serviceMonitoring.reports.periodClosed')}</Alert>}
            {report.review_note && (
                <Alert tone={report.status === 'returned' ? 'warning' : 'info'} title={report.status === 'returned' ? t('serviceMonitoring.reports.returnedNotice') : t('serviceMonitoring.reports.reviewNote')}>
                    <span className="whitespace-pre-line">{report.review_note}</span>
                    {report.reviewer && <span className="mt-1 block text-xs">{t('serviceMonitoring.reports.reviewedBy')}: {report.reviewer}</span>}
                </Alert>
            )}
        </Card>
    );
}

function Item({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 text-(--app-foreground)">{children}</dd>
        </div>
    );
}
