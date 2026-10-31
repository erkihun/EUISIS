import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionFindingShowProps } from '@/court/types/inspections';
import { DetailLine, PlainTextBlock, SEVERITY_TONES } from './inspectionUi';

/** One inspection finding: its details, the attachment, its acceptance, and the admin's accept action. */
export default function InspectionFindingShow({ finding, canEdit, canAccept }: InspectionFindingShowProps) {
    const { t, tx } = useInspectionPagesText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<'delete' | 'accept' | null>(null);
    const title = t('cm.case_inspections.findings.show_title');
    const noData = t('cm.case_inspections.common.no_data');

    const destroy = async () => {
        const result = await confirm({
            title: t('cm.case_inspections.findings.confirm_delete'), description: finding.title ?? undefined, variant: 'danger',
            confirmLabel: t('cm.case_inspections.common.delete'), cancelLabel: t('cm.case_inspections.common.cancel'),
        });
        if (!result.confirmed) return;
        router.delete(route('court.case-inspection-findings.destroy', finding.id), { onStart: () => setBusy('delete'), onFinish: () => setBusy(null) });
    };

    const accept = () => {
        router.post(route('court.case-inspection-findings.accept', finding.id), {}, { preserveScroll: true, onStart: () => setBusy('accept'), onFinish: () => setBusy(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} backHref={route('court.case-inspection-findings.index')} actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {canAccept && <Button type="button" onClick={accept} disabled={busy !== null}>{t('cm.case_inspections.findings.accept')}</Button>}
                    {canEdit && (
                        <>
                            <Link href={route('court.case-inspection-findings.edit', finding.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.edit')}</Link>
                            <Button type="button" variant="destructiveOutline" onClick={destroy} disabled={busy !== null}>{t('cm.case_inspections.common.delete')}</Button>
                        </>
                    )}
                    <Link href={route('court.case-inspection-findings.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.back')}</Link>
                </div>
            )} />
        )}>
            <Head title={finding.title ?? title} />

            <Card className="max-w-5xl space-y-5">
                <div>
                    <h2 className="flex flex-wrap items-center gap-2 text-xl font-bold text-(--app-foreground)">
                        {finding.title}
                        {finding.accepted && <span className={badgeClassName('success')}>{t('cm.case_inspections.findings.accepted')}</span>}
                    </h2>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">
                        {finding.case_id
                            ? <Link href={route('court.cases.show', finding.case_id)} className="text-primary hover:underline">{finding.case_number ?? noData}</Link>
                            : (finding.case_number ?? noData)}
                        {' - '}
                        <Link href={route('court.case-inspection-requests.show', finding.request_id)} className="hover:underline">{finding.request_subject ?? noData}</Link>
                    </p>
                </div>

                <div className="grid gap-3 md:grid-cols-3">
                    <DetailLine label={t('cm.case_inspections.findings.labels.finding_date')}><LocalizedDateDisplay value={finding.finding_date} /></DetailLine>
                    <DetailLine label={t('cm.case_inspections.findings.labels.severity')}>
                        <span className={badgeClassName(SEVERITY_TONES[finding.severity] ?? 'neutral')}>{tx(`cm.case_inspections.severity.${finding.severity}`, finding.severity)}</span>
                    </DetailLine>
                    <DetailLine label={t('cm.case_inspections.findings.labels.recorded_by')}>{finding.recorded_by_name ?? noData}</DetailLine>
                    <DetailLine label={t('cm.case_inspections.findings.labels.attachment_pdf')}>
                        {finding.has_attachment
                            ? <a href={route('court.case-inspection-findings.attachment', finding.id)} target="_blank" rel="noopener noreferrer" className="text-primary underline">{finding.attachment_name ?? t('cm.case_inspections.findings.labels.download_attachment')}</a>
                            : noData}
                    </DetailLine>
                    <DetailLine label={t('cm.case_inspections.findings.labels.accepted')}>
                        {finding.accepted
                            ? <>{t('cm.case_inspections.findings.accepted')} ({finding.accepted_by_name ?? noData}{finding.accepted_at && <>, <LocalizedDateDisplay value={finding.accepted_at} withTime /></>})</>
                            : t('cm.case_inspections.findings.not_accepted')}
                    </DetailLine>
                </div>

                <div>
                    <h3 className="mb-2 text-base font-semibold text-(--app-foreground)">{t('cm.case_inspections.findings.labels.details')}</h3>
                    <PlainTextBlock text={finding.details} empty={noData} />
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
