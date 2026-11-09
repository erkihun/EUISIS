import { router } from '@inertiajs/react';
import { useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { actionLinkClassName, badgeClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import type { CaseDetailPermissions, InspectionFindingRow, InspectionRequestRow } from '@/court/types/caseDetail';
import SectionCard, { EmptyBox } from './SectionCard';
import { useCaseText } from './useCaseText';
import { DetailsIcon } from './icons';

/** The case's inspection requests (people with inspection-requests.manage). */
export function InspectionRequestsSection({ requests }: { requests: InspectionRequestRow[] }) {
    const { t, tx } = useCaseText();
    const noData = t('cm.case_inspections.common.no_data');

    return (
        <SectionCard id="inspection-requests" title={t('cm.case_inspections.requests.index_title')} icon={<DetailsIcon />} count={`${requests.length} ${t('cm.cases.show.entries')}`}>
            {requests.length === 0 ? <EmptyBox>{t('cm.case_inspections.requests.empty')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.case_inspections.requests.table_date'), t('cm.case_inspections.requests.table_subject'), t('cm.case_inspections.requests.table_status'),
                                        t('cm.case_inspections.requests.table_inspector'), t('cm.case_inspections.requests.table_created_by'),
                                    ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>)}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{tx('cm.cases.labels.actions', 'Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {requests.map((request) => (
                                    <tr key={request.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}><LocalizedDateDisplay value={request.request_date} /></td>
                                        <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>{request.subject}</td>
                                        <td className={tableCellClassName}>{tx(`cm.case_inspections.status.${request.status}`, request.status)}</td>
                                        <td className={tableCellClassName}>{request.assigned_inspector_name ?? noData}</td>
                                        <td className={tableCellClassName}>{request.created_by_name ?? noData}</td>
                                        <td className={cx(tableCellClassName, 'space-x-2 text-right whitespace-nowrap')}>
                                            <a href={route('court.case-inspection-requests.show', request.id)} className={actionLinkClassName({})}>{t('cm.case_inspections.common.view')}</a>
                                            {request.status !== 'completed' && (
                                                <a href={route('court.case-inspection-requests.edit', request.id)} className={actionLinkClassName({ tone: 'neutral' })}>{t('cm.case_inspections.common.edit')}</a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

interface FindingsProps {
    findings: InspectionFindingRow[];
    can: CaseDetailPermissions;
    selectedId: number | null;
    onToggle: (id: number) => void;
}

/** The case's inspection findings, each expandable to its details, with the admin's "accept" action. */
export function InspectionFindingsSection({ findings, can, selectedId, onToggle }: FindingsProps) {
    const { t, tx } = useCaseText();
    const [accepting, setAccepting] = useState<number | null>(null);
    const noData = t('cm.case_inspections.common.no_data');

    const accept = (id: number) => {
        router.post(route('court.case-inspection-findings.accept', id), {}, { preserveScroll: true, onStart: () => setAccepting(id), onFinish: () => setAccepting(null) });
    };

    return (
        <SectionCard id="inspection-findings" title={t('cm.case_inspections.findings.index_title')} icon={<DetailsIcon />} count={`${findings.length} ${t('cm.cases.show.entries')}`}>
            {findings.length === 0 ? <EmptyBox>{t('cm.case_inspections.findings.empty')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.case_inspections.findings.table_date'), t('cm.case_inspections.findings.table_title'), t('cm.case_inspections.findings.table_severity'),
                                        t('cm.case_inspections.findings.table_request'), t('cm.case_inspections.requests.table_inspector'),
                                    ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>)}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{tx('cm.cases.labels.actions', 'Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {findings.map((finding) => (
                                    <FindingRows key={finding.id} finding={finding} can={can} open={selectedId === finding.id} onToggle={() => onToggle(finding.id)}
                                        onAccept={() => accept(finding.id)} accepting={accepting === finding.id} noData={noData} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

function FindingRows({ finding, can, open, onToggle, onAccept, accepting, noData }: {
    finding: InspectionFindingRow; can: CaseDetailPermissions; open: boolean; onToggle: () => void; onAccept: () => void; accepting: boolean; noData: string;
}) {
    const { t, tx } = useCaseText();
    const severity = tx(`cm.case_inspections.severity.${finding.severity}`, finding.severity);

    return (
        <>
            <tr className={tableRowClassName}>
                <td className={tableCellClassName}><LocalizedDateDisplay value={finding.finding_date} /></td>
                <td className={cx(tableCellClassName, 'text-(--app-foreground)')}>
                    {finding.title}
                    {finding.accepted && <span className={badgeClassName('success', 'ml-2')}>{t('cm.case_inspections.findings.accepted')}</span>}
                </td>
                <td className={tableCellClassName}>{severity}</td>
                <td className={tableCellClassName}>{finding.request_subject ?? noData}</td>
                <td className={tableCellClassName}>{finding.assigned_inspector_name ?? noData}</td>
                <td className={cx(tableCellClassName, 'space-x-2 text-right whitespace-nowrap')}>
                    {can.manageInspectionFindings && can.isAdmin && !finding.accepted && (
                        <button type="button" onClick={onAccept} disabled={accepting} className={actionLinkClassName({ tone: 'success' })}>{t('cm.case_inspections.findings.accept')}</button>
                    )}
                    <button type="button" onClick={onToggle} aria-expanded={open} className={actionLinkClassName({})}>{t('cm.case_inspections.common.view')}</button>
                </td>
            </tr>
            {open && (
                <tr className="bg-(--app-surface-muted)">
                    <td colSpan={6} className="px-4 py-3">
                        <div className="space-y-3 rounded-control border border-(--app-border) bg-(--app-surface) p-4">
                            <div className="grid gap-3 text-sm md:grid-cols-3">
                                <div><span className="text-(--app-muted-foreground)">{t('cm.case_inspections.findings.labels.finding_date')}:</span> <LocalizedDateDisplay value={finding.finding_date} className="text-(--app-foreground)" /></div>
                                <div><span className="text-(--app-muted-foreground)">{t('cm.case_inspections.findings.labels.severity')}:</span> <span className="text-(--app-foreground)">{severity}</span></div>
                                <div><span className="text-(--app-muted-foreground)">{t('cm.case_inspections.findings.labels.recorded_by')}:</span> <span className="text-(--app-foreground)">{finding.recorded_by_name ?? noData}</span></div>
                                <div>
                                    <span className="text-(--app-muted-foreground)">{t('cm.case_inspections.findings.labels.attachment_pdf')}:</span>{' '}
                                    {finding.has_attachment
                                        ? <a href={route('court.case-inspection-findings.attachment', finding.id)} target="_blank" rel="noopener noreferrer" className="text-primary underline">{finding.attachment_name ?? t('cm.case_inspections.findings.labels.download_attachment')}</a>
                                        : <span className="text-(--app-foreground)">{noData}</span>}
                                </div>
                            </div>
                            <div>
                                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.case_inspections.findings.labels.details')}</p>
                                <div className="whitespace-pre-wrap text-sm text-(--app-foreground)">{finding.details}</div>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}
