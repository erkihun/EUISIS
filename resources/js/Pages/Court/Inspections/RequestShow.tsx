import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, actionLinkClassName, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionRequestShowProps } from '@/court/types/inspections';
import { DetailLine, PlainTextBlock, REQUEST_STATUS_TONES, SEVERITY_TONES, caseLabel } from './inspectionUi';

/** One inspection request: its details, the note, and the findings recorded against it. */
export default function InspectionRequestShow({ request, canEdit }: InspectionRequestShowProps) {
    const { t, tx } = useInspectionPagesText();
    const { confirm } = useConfirm();
    const [deleting, setDeleting] = useState(false);
    const title = t('cm.case_inspections.requests.show_title');
    const noData = t('cm.case_inspections.common.no_data');

    const destroy = async () => {
        const result = await confirm({
            title: t('cm.case_inspections.requests.confirm_delete'), description: request.subject ?? undefined, variant: 'danger',
            confirmLabel: t('cm.case_inspections.common.delete'), cancelLabel: t('cm.case_inspections.common.cancel'),
        });
        if (!result.confirmed) return;
        router.delete(route('court.case-inspection-requests.destroy', request.id), { onStart: () => setDeleting(true), onFinish: () => setDeleting(false) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} backHref={route('court.case-inspection-requests.index')} actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {canEdit && (
                        <>
                            <Link href={route('court.case-inspection-requests.edit', request.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.edit')}</Link>
                            <Button type="button" variant="destructiveOutline" onClick={destroy} disabled={deleting}>{t('cm.case_inspections.common.delete')}</Button>
                        </>
                    )}
                    <Link href={route('court.case-inspection-requests.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.case_inspections.common.back')}</Link>
                </div>
            )} />
        )}>
            <Head title={request.subject ?? title} />

            <div className="max-w-5xl space-y-4">
                <Card className="space-y-5">
                    <div>
                        <h2 className="text-xl font-bold text-(--app-foreground)">{request.subject}</h2>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">
                            {request.case_id
                                ? <Link href={route('court.cases.show', request.case_id)} className="text-primary hover:underline">{caseLabel(request.case_number, request.case_title)}</Link>
                                : caseLabel(request.case_number, request.case_title)}
                        </p>
                    </div>

                    <div className="grid gap-3 md:grid-cols-3">
                        <DetailLine label={t('cm.case_inspections.requests.labels.request_date')}><LocalizedDateDisplay value={request.request_date} /></DetailLine>
                        <DetailLine label={t('cm.case_inspections.requests.labels.status')}>
                            <span className={badgeClassName(REQUEST_STATUS_TONES[request.status] ?? 'neutral')}>{tx(`cm.case_inspections.status.${request.status}`, request.status)}</span>
                        </DetailLine>
                        <DetailLine label={t('cm.case_inspections.requests.labels.inspector')}>{request.assigned_inspector_name ?? noData}</DetailLine>
                        <DetailLine label={t('cm.case_inspections.requests.labels.created_by')}>{request.created_by_name ?? noData}</DetailLine>
                    </div>

                    <PlainTextBlock text={request.request_note} empty={noData} />
                </Card>

                <Card className="space-y-3">
                    <h3 className="text-base font-semibold text-(--app-foreground)">{t('cm.case_inspections.requests.labels.findings')}</h3>
                    {request.findings.length === 0
                        ? <p className="text-sm text-(--app-muted-foreground)">{t('cm.case_inspections.requests.labels.no_findings')}</p>
                        : (
                            <ul className="space-y-2">
                                {request.findings.map((finding) => (
                                    <li key={finding.id} className="flex items-center justify-between gap-3 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
                                        <div>
                                            <div className="font-medium text-(--app-foreground)">{finding.title}</div>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)">
                                                <LocalizedDateDisplay value={finding.finding_date} />
                                                <span className={badgeClassName(SEVERITY_TONES[finding.severity] ?? 'neutral')}>{tx(`cm.case_inspections.severity.${finding.severity}`, finding.severity)}</span>
                                                {finding.recorded_by_name && <span>{finding.recorded_by_name}</span>}
                                            </div>
                                        </div>
                                        <Link href={route('court.case-inspection-findings.show', finding.id)} className={actionLinkClassName({ size: 'sm' })}>{t('cm.case_inspections.common.view')}</Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
