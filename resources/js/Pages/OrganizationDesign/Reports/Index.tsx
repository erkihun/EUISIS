import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, TableCard, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Row = Record<string, string | number | null>;
type Export = { id: string; report: string; format: string; status: 'queued' | 'ready' | 'failed'; filename: string | null; row_count: number | null; error: string | null; organization: Named | null; created_at: string | null; completed_at: string | null };
type Props = {
    report: string;
    reports: string[];
    result: { report: string; columns: string[]; rows: Row[]; organizations: number; truncated: boolean };
    labels: Record<string, string>;
    filters: { organization_id: string | null };
    organizations: Named[];
    exports: Export[];
    can: { export: boolean };
};

const SEVERITY_TONES = { error: 'danger', warning: 'warning', info: 'info' } as const;
const EXPORT_TONES = { queued: 'info', ready: 'success', failed: 'danger' } as const;
const STATUS_COLUMNS = ['profile_status', 'job_description_status', 'status'];
const NUMERIC = /^(mandates|functions|services|processes|activities|tasks|tasks_with_standard|units|units_with_responsibilities|positions|positions_linked|positions_with_profile|positions_with_job_description|manpower_plans_approved|work_links|competencies|qualifications|lines|headcount|calculated_fte)$/;

export default function ReportsIndex({ report, reports, result, labels, filters, organizations, exports, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ report, organization_id: filters.organization_id ?? '' });
    const hasActiveFilters = Boolean(filters.organization_id);
    const query = () => Object.fromEntries(Object.entries(draft).filter(([, v]) => v));
    const label = (column: string) => labels[column] ?? column;
    const requestExport = (format: 'csv' | 'xlsx') => router.post(route('organization-design.reports.export'), { report, format, organization_id: filters.organization_id || null }, { preserveScroll: true });

    function cell(row: Row, column: string): React.ReactNode {
        const value = row[column];
        if (value === null || value === undefined || value === '') return <span className="text-[color:var(--app-muted-foreground)]">—</span>;
        if (column === 'severity') return <span className={badgeClassName(SEVERITY_TONES[value as keyof typeof SEVERITY_TONES] ?? 'neutral')}>{tt(`validation.severity.${value}`)}</span>;
        if (STATUS_COLUMNS.includes(column)) return <StatusPill status={String(value)} />;
        if (column === 'position_code' && row.position_id) return <Link href={route('organization-design.position-design.show', String(row.position_id))} className={actionLinkClassName({ size: 'sm' })}>{String(value)}</Link>;
        if (column === 'subject_code' && row.subject_type === 'position' && row.subject_id) return <Link href={route('organization-design.position-design.show', String(row.subject_id))} className={actionLinkClassName({ size: 'sm' })}>{String(value)}</Link>;
        if (column === 'reference_number' && row.plan_id) return <Link href={route('organization-design.manpower-plans.show', String(row.plan_id))} className={actionLinkClassName({ size: 'sm' })}>{String(value)}</Link>;
        if (column === 'job_description_reference' && row.job_description_id) return <Link href={route('organization-design.job-descriptions.show', String(row.job_description_id))} className={actionLinkClassName({ size: 'sm' })}>{String(value)}</Link>;
        if (column === 'calculated_fte') return <span className="font-mono text-xs">{String(value)}</span>;
        return String(value);
    }

    return (
        <AuthenticatedLayout header={
            <PageHeader title={tt('reports.title')} description={tt('reports.description')}
                actions={can.export && (
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => requestExport('csv')}>{tt('reports.exportCsv')}</Button>
                        <Button variant="outline" onClick={() => requestExport('xlsx')}>{tt('reports.exportXlsx')}</Button>
                    </div>
                )} />
        }>
            <Head title={tt('reports.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.reports.index'), query(), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.reports.index'), { report })}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={tt('reports.report')}>
                        <Select value={draft.report} onChange={(e) => setDraft({ ...draft, report: e.target.value })}>
                            {reports.map((r) => <option key={r} value={r}>{tt(`reports.names.${r}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{tt('reports.allOrganizations')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt(`reports.names.${report}`)}>
                    <div className="flex flex-wrap items-center gap-2 px-5 pb-3 text-xs text-[color:var(--app-muted-foreground)]">
                        <span>{tt(`reports.help.${report}`)}</span>
                        <span className={badgeClassName('neutral')}>{tt('reports.rows').replace(':count', String(result.rows.length))}</span>
                        <span className={badgeClassName('neutral')}>{tt('reports.organizationsCovered').replace(':count', String(result.organizations))}</span>
                        {result.truncated && <span className={badgeClassName('warning')}>{tt('reports.truncated').replace(':max', '200')}</span>}
                    </div>
                    {result.rows.length === 0 ? (
                        <EmptyState title={tt('reports.noRows')} description={hasActiveFilters ? t('common.noMatchingRecords') : ''} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>{result.columns.map((c) => <Th key={c} align={NUMERIC.test(c) ? 'right' : undefined}>{label(c)}</Th>)}</tr>
                            </THead>
                            <TBody>
                                {result.rows.map((row, i) => (
                                    <Tr key={i}>
                                        {result.columns.map((c) => <Td key={c} align={NUMERIC.test(c) ? 'right' : undefined} className={c === 'message' ? 'max-w-md whitespace-normal' : undefined}>{cell(row, c)}</Td>)}
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </TableCard>

                {can.export && (
                    <Card>
                        <CardHeader><CardTitle>{tt('reports.exports')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('reports.exportsHelp')}</p></CardHeader>
                        {exports.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('reports.noExports')}</p> : (
                            <Table>
                                <THead>
                                    <tr>
                                        <Th>{tt('reports.report')}</Th>
                                        <Th>{tt('fields.organization')}</Th>
                                        <Th>{tt('reports.requestedAt')}</Th>
                                        <Th align="right">{tt('reports.rows').replace(':count', '').trim()}</Th>
                                        <Th>{tt('fields.status')}</Th>
                                        <Th align="right">{t('common.actions')}</Th>
                                    </tr>
                                </THead>
                                <TBody>
                                    {exports.map((e) => (
                                        <Tr key={e.id}>
                                            <Td>{tt(`reports.names.${e.report}`)} <span className="font-mono text-xs uppercase text-[color:var(--app-muted-foreground)]">{e.format}</span></Td>
                                            <Td muted>{e.organization ? `${e.organization.code} · ${nameOf(e.organization, locale)}` : tt('reports.allOrganizations')}</Td>
                                            <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={e.created_at} withTime /></Td>
                                            <Td align="right">{e.row_count ?? '—'}</Td>
                                            <Td><span className={badgeClassName(EXPORT_TONES[e.status] ?? 'neutral')}>{tt(`reports.statuses.${e.status}`)}</span>{e.error && <p className="text-xs text-[color:var(--app-muted-foreground)]">{e.error}</p>}</Td>
                                            <Td align="right">{e.status === 'ready' && <a href={route('organization-design.reports.exports.download', e.id)} className={actionLinkClassName()}>{tt('reports.download')}</a>}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
