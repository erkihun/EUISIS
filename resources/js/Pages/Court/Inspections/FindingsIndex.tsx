import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    FilterField, FilterPanel, ListPagination, Select, actionLinkClassName, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { InspectionFindingsIndexProps } from '@/court/types/inspections';
import { InspectionTabs, SEVERITY_TONES } from './inspectionUi';

/** Every inspection finding the viewer may see, newest first, filtered by request and severity (and the case it was opened from). */
export default function InspectionFindingsIndex({ findings, requests, filters, severities, nav }: InspectionFindingsIndexProps) {
    const { t, tx, text } = useInspectionPagesText();
    const [requestId, setRequestId] = useState(filters.request_id);
    const [severity, setSeverity] = useState(filters.severity);
    const title = t('cm.case_inspections.findings.index_title');
    const noData = t('cm.case_inspections.common.no_data');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        // The case filter arrives from the case page and travels with the form, as the Blade page's hidden field did.
        if (filters.case_id) params.case_id = filters.case_id;
        if (requestId) params.request_id = requestId;
        if (severity) params.severity = severity;
        router.get(route('court.case-inspection-findings.index'), params, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={t('cm.case_inspections.findings.index_description')}
                actions={<Link href={route('court.case-inspection-findings.create')} className={buttonClassName({})}>{t('cm.case_inspections.findings.new')}</Link>} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <InspectionTabs active="findings" canRequests={nav.requests} canFindings={nav.findings} />

                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.case-inspection-findings.index'))}
                    hasActiveFilters={Boolean(filters.request_id || filters.severity || filters.case_id)} label={title}
                    submitLabel={t('cm.case_inspections.common.filter')} resetLabel={text('reset')}>
                    <FilterField label={t('cm.case_inspections.findings.table_request')} htmlFor="filter-request" wide>
                        <Select id="filter-request" value={requestId} onChange={(event) => setRequestId(event.target.value)}>
                            <option value="">{t('cm.case_inspections.findings.all_requests')}</option>
                            {requests.map((request) => (
                                <option key={request.id} value={String(request.id)}>{[request.case_number, request.subject].filter(Boolean).join(' - ')}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.case_inspections.findings.table_severity')} htmlFor="filter-severity">
                        <Select id="filter-severity" value={severity} onChange={(event) => setSeverity(event.target.value)}>
                            <option value="">{t('cm.case_inspections.findings.all_severities')}</option>
                            {severities.map((item) => <option key={item} value={item}>{tx(`cm.case_inspections.severity.${item}`, item)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.case_inspections.findings.table_case'), t('cm.case_inspections.findings.table_request'), t('cm.case_inspections.findings.table_title'),
                                        t('cm.case_inspections.findings.table_date'), t('cm.case_inspections.findings.table_severity'),
                                    ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>)}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.case_inspections.common.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {findings.data.map((finding) => (
                                    <tr key={finding.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>
                                            {finding.case_id
                                                ? <Link href={route('court.cases.show', finding.case_id)} className="font-mono font-semibold text-primary hover:underline">{finding.case_number ?? noData}</Link>
                                                : (finding.case_number ?? noData)}
                                        </td>
                                        <td className={tableCellClassName}>{finding.request_subject ?? noData}</td>
                                        <td className={tableCellClassName}>
                                            <span className="text-(--app-foreground)">{finding.title}</span>
                                            {finding.accepted && <span className={badgeClassName('success', 'ml-2')}>{t('cm.case_inspections.findings.accepted')}</span>}
                                            {finding.has_attachment && (
                                                <div className="mt-1">
                                                    <a href={route('court.case-inspection-findings.attachment', finding.id)} target="_blank" rel="noopener noreferrer" className="text-xs text-primary underline">
                                                        {t('cm.case_inspections.findings.labels.download_attachment')}
                                                    </a>
                                                </div>
                                            )}
                                        </td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap')}><LocalizedDateDisplay value={finding.finding_date} /></td>
                                        <td className={tableCellClassName}>
                                            <span className={badgeClassName(SEVERITY_TONES[finding.severity] ?? 'neutral')}>{tx(`cm.case_inspections.severity.${finding.severity}`, finding.severity)}</span>
                                        </td>
                                        <td className={cx(tableCellClassName, 'space-x-2 text-right whitespace-nowrap')}>
                                            {!finding.accepted && (
                                                <Link href={route('court.case-inspection-findings.edit', finding.id)} className={actionLinkClassName({ tone: 'neutral' })}>{t('cm.case_inspections.common.edit')}</Link>
                                            )}
                                            <Link href={route('court.case-inspection-findings.show', finding.id)} className={actionLinkClassName({})}>{t('cm.case_inspections.common.view')}</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {findings.data.length === 0 && <EmptyState title={t('cm.case_inspections.findings.empty')} />}
                    <ListPagination meta={findings} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
