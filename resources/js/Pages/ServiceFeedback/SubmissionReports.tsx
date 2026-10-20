import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { FeedbackStatusBadge, statusLabelKey } from '@/Components/ServiceFeedback/feedbackUi';
import { Alert, Button, Card, FilterField, FilterPanel, Select, TBody, THead, Table, TableCard, Td, Th, Tr, cx } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState, type FormEvent } from 'react';
import type { TipOffBreakdown, TipTypeBucket } from '@/types/publicSubmissions';

type NamePair = { id: string | null; name_en: string | null; name_am: string | null; total: number };
type Filters = Partial<Record<'organization_id' | 'submission_type' | 'tip_type' | 'target_type' | 'category_id' | 'status' | 'date_from' | 'date_to' | 'handler_type' | 'handler_id', string>>;
type Summary = { total: number; by_type: Record<'feedback' | 'suggestion' | 'complaint', number>; by_target: Record<'employee' | 'organization', number>; by_status: Record<string, number> };
type ExportRow = { id: string; format: string; status: 'queued' | 'ready' | 'failed'; filename: string | null; row_count: number | null; error: string | null; created_at: string | null; completed_at: string | null };

type Props = {
    filters: Filters;
    summary: Summary;
    byOrganization: (NamePair & { open: number; closed: number })[];
    byCategory: NamePair[];
    byHandler: (NamePair & { handler_type: string; handler_id: string })[];
    /** Relevant work units the public named, under the name at submission; `general` is the "General / Not sure" bucket. */
    byUnit: (NamePair & { general: boolean })[];
    tipOffs: TipOffBreakdown;
    tipOffsByOrganization: (Omit<NamePair, 'total'> & TipOffBreakdown)[];
    employeeTargetedTipOffs: ({ employee_id: string; full_name: string | null; employee_number: string | null } & TipOffBreakdown)[];
    filterOptions: {
        organizations: { id: string; name_en: string; name_am: string | null }[];
        categories: { id: string; name_en: string; name_am: string | null }[];
        statuses: string[];
        types: string[];
        tipTypes: TipTypeBucket[];
        targets: string[];
    };
    exports: ExportRow[];
    can: { export: boolean };
};

const TYPE_KEY: Record<string, string> = { feedback: 'adminTypeFeedback', suggestion: 'adminTypeSuggestion', complaint: 'adminTypeComplaint' };
const STATUS_ORDER = ['pending', 'unrouted', 'assigned', 'under_review', 'resolved', 'closed'] as const;

const TIP_LABEL: Record<TipTypeBucket, string> = {
    CORRUPTION: 'serviceFeedback.tipTypeCorruption',
    IMPROPER_PRACTICE: 'serviceFeedback.tipTypeImproperPractice',
    UNCLASSIFIED_LEGACY: 'serviceFeedback.tipTypeUnclassified',
};
const TIP_BUCKETS: TipTypeBucket[] = ['CORRUPTION', 'IMPROPER_PRACTICE', 'UNCLASSIFIED_LEGACY'];

/**
 * The public submissions report (brief §83–87): one slice across the three
 * types with breakdowns by institution, category and handler, and queued
 * private exports. No per-employee ranking (§82).
 *
 * Tip-offs get their own section: counts by type that always add up, counts
 * per institution in name order, and — only once one institution is chosen —
 * employee-targeted Tip-off counts in name order for that institution's
 * reviewers. These are reports received, not findings; nothing here is sorted
 * by count, scored or labelled as conduct.
 */
export default function SubmissionReports({ filters, summary, byOrganization, byCategory, byHandler, byUnit, tipOffs, tipOffsByOrganization, employeeTargetedTipOffs, filterOptions, exports, can }: Props) {
    const { t, locale } = useLocale();
    const [draft, setDraft] = useState<Filters>({ ...filters });
    const exportForm = useForm({ format: 'csv' as 'csv' | 'xlsx' });
    const name = (row: { name_en: string | null; name_am: string | null }) => (locale === 'am' && row.name_am ? row.name_am : row.name_en) ?? '—';
    const hasFilters = Object.values(filters).some((value) => value !== undefined && value !== '');
    const clean = (values: Filters) => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== undefined && value !== ''));

    function apply(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('service-feedback.submission-reports.index'), clean(draft), { preserveScroll: true, preserveState: true });
    }

    function reset() {
        setDraft({});
        router.get(route('service-feedback.submission-reports.index'), {}, { preserveScroll: true });
    }

    function requestExport(event: FormEvent) {
        event.preventDefault();
        exportForm.transform((data) => ({ ...data, ...clean(filters) }));
        exportForm.post(route('service-feedback.submission-reports.export'), { preserveScroll: true });
    }

    const set = (key: keyof Filters) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceFeedback.submissionReportsTitle')} description={t('serviceFeedback.submissionReportsIntro')} />}>
            <Head title={t('serviceFeedback.submissionReportsTitle')} />

            <div className="space-y-5">
                <FilterPanel onSubmit={apply} onReset={reset} hasActiveFilters={hasFilters} label={t('common.filters')}>
                    <FilterField label={t('serviceFeedback.filterOrganization')} htmlFor="rf-organization">
                        <Select id="rf-organization" value={draft.organization_id ?? ''} onChange={(e) => set('organization_id')(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {filterOptions.organizations.map((o) => <option key={o.id} value={o.id}>{locale === 'am' && o.name_am ? o.name_am : o.name_en}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.appliesToTypes')} htmlFor="rf-type">
                        <Select id="rf-type" value={draft.submission_type ?? ''} onChange={(e) => set('submission_type')(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {filterOptions.types.map((type) => <option key={type} value={type}>{t(`serviceFeedback.${TYPE_KEY[type]}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.tipTypeLabel')} htmlFor="rf-tip-type">
                        <Select id="rf-tip-type" value={draft.tip_type ?? ''} onChange={(e) => set('tip_type')(e.target.value)}>
                            <option value="">{t('serviceFeedback.tipTypeAll')}</option>
                            {filterOptions.tipTypes.map((tipType) => <option key={tipType} value={tipType}>{t(TIP_LABEL[tipType])}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.appliesToTargets')} htmlFor="rf-target">
                        <Select id="rf-target" value={draft.target_type ?? ''} onChange={(e) => set('target_type')(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {filterOptions.targets.map((target) => <option key={target} value={target}>{t(target === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee')}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.category')} htmlFor="rf-category">
                        <Select id="rf-category" value={draft.category_id ?? ''} onChange={(e) => set('category_id')(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {filterOptions.categories.map((c) => <option key={c.id} value={c.id}>{locale === 'am' && c.name_am ? c.name_am : c.name_en}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.filterStatus')} htmlFor="rf-status">
                        <Select id="rf-status" value={draft.status ?? ''} onChange={(e) => set('status')(e.target.value)}>
                            <option value="">{t('common.all')}</option>
                            {filterOptions.statuses.map((status) => <option key={status} value={status}>{t(statusLabelKey(status))}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.effectiveFrom')} htmlFor="rf-from">
                        <LocalizedDatePicker id="rf-from" value={draft.date_from ?? ''} onChange={set('date_from')} />
                    </FilterField>
                    <FilterField label={t('serviceFeedback.effectiveTo')} htmlFor="rf-to">
                        <LocalizedDatePicker id="rf-to" value={draft.date_to ?? ''} min={draft.date_from || undefined} onChange={set('date_to')} />
                    </FilterField>
                </FilterPanel>

                <section aria-label={t('serviceFeedback.submissionCounts')} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label={t('serviceFeedback.totalSubmissions')} value={summary.total} />
                    <Stat label={t('serviceFeedback.adminTypeFeedback')} value={summary.by_type.feedback} />
                    <Stat label={t('serviceFeedback.adminTypeSuggestion')} value={summary.by_type.suggestion} />
                    <Stat label={t('serviceFeedback.adminTypeComplaint')} value={summary.by_type.complaint} />
                    <Stat label={t('serviceFeedback.employeeTargeted')} value={summary.by_target.employee} />
                    <Stat label={t('serviceFeedback.organizationTargeted')} value={summary.by_target.organization} />
                    <Stat label={t('serviceFeedback.statusUnrouted')} value={summary.by_status.unrouted ?? 0} tone={summary.by_status.unrouted > 0 ? 'warning' : undefined} />
                    <Stat label={t('serviceFeedback.statusClosed')} value={summary.by_status.closed ?? 0} />
                </section>

                <Card className="p-5">
                    <h2 className="font-semibold">{t('serviceFeedback.byStatus')}</h2>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {STATUS_ORDER.map((status) => <li key={status} className="inline-flex items-center gap-2 rounded-full border border-(--app-border) px-3 py-1 text-sm"><FeedbackStatusBadge status={status} /><span className="font-semibold tabular-nums">{(summary.by_status[status] ?? 0).toLocaleString()}</span></li>)}
                    </ul>
                </Card>

                <div className="grid gap-5 lg:grid-cols-3">
                    <Breakdown title={t('serviceFeedback.byOrganization')} rows={byOrganization} name={name} extra={(row) => `${t('serviceFeedback.openCount')}: ${row.open} · ${t('serviceFeedback.statusClosed')}: ${row.closed}`} empty={t('serviceFeedback.noSubmissions')} />
                    <Breakdown title={t('serviceFeedback.byCategory')} rows={byCategory} name={name} empty={t('serviceFeedback.noCategorisedSubmissions')} />
                    <Breakdown title={t('serviceFeedback.byHandler')} rows={byHandler} name={name} empty={t('serviceFeedback.noHandler')} />
                    <Breakdown title={t('serviceFeedback.byUnit')} rows={byUnit} name={(row) => (row.general ? t('serviceFeedback.generalNotSure') : name(row))} empty={t('serviceFeedback.noUnitSubmissions')} />
                </div>

                <Alert tone="info">{t('serviceFeedback.noRankingNote')}</Alert>

                <section aria-labelledby="tip-off-analytics" className="space-y-4">
                    <div>
                        <h2 id="tip-off-analytics" className="text-lg font-semibold">{t('serviceFeedback.tipOffAnalyticsTitle')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{t('serviceFeedback.tipOffAnalyticsNote')}</p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Stat label={t('serviceFeedback.tipOffTotal')} value={tipOffs.total} />
                        {TIP_BUCKETS.map((bucket) => <Stat key={bucket} label={t(TIP_LABEL[bucket])} value={tipOffs[bucket]} />)}
                    </div>

                    <TableCard title={t('serviceFeedback.tipOffsByInstitution')}>
                        {tipOffsByOrganization.length === 0 ? <EmptyState title={t('serviceFeedback.noSubmissions')} /> : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('serviceFeedback.filterOrganization')}</Th>
                                        <Th align="right">{t('serviceFeedback.tipOffTotal')}</Th>
                                        {TIP_BUCKETS.map((bucket) => <Th key={bucket} align="right">{t(TIP_LABEL[bucket])}</Th>)}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {tipOffsByOrganization.map((row) => (
                                        <Tr key={row.id ?? 'none'}>
                                            <Td>{name(row)}</Td>
                                            <Td align="right" numeric>{row.total.toLocaleString()}</Td>
                                            {TIP_BUCKETS.map((bucket) => <Td key={bucket} align="right" numeric>{row[bucket].toLocaleString()}</Td>)}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </TableCard>

                    <TableCard title={t('serviceFeedback.employeeTargetedTipOffs')} description={t('serviceFeedback.employeeTargetedTipOffsHint')}>
                        {!filters.organization_id || employeeTargetedTipOffs.length === 0 ? <EmptyState title={t(filters.organization_id ? 'serviceFeedback.noSubmissions' : 'serviceFeedback.employeeTargetedTipOffsHint')} /> : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('serviceFeedback.targetEmployee')}</Th>
                                        <Th align="right">{t('serviceFeedback.tipOffTotal')}</Th>
                                        {TIP_BUCKETS.map((bucket) => <Th key={bucket} align="right">{t(TIP_LABEL[bucket])}</Th>)}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {employeeTargetedTipOffs.map((row) => (
                                        <Tr key={row.employee_id}>
                                            <Td>{row.full_name ?? '—'}{row.employee_number && <span className="ml-2 text-xs text-(--app-muted-foreground)">{row.employee_number}</span>}</Td>
                                            <Td align="right" numeric>{row.total.toLocaleString()}</Td>
                                            {TIP_BUCKETS.map((bucket) => <Td key={bucket} align="right" numeric>{row[bucket].toLocaleString()}</Td>)}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </TableCard>
                </section>

                {can.export && (
                    <TableCard
                        title={t('serviceFeedback.exportsTitle')}
                        description={t('serviceFeedback.exportsIntro')}
                        actions={(
                            <form onSubmit={requestExport} className="flex items-center gap-2">
                                <Select aria-label={t('serviceFeedback.exportFormat')} value={exportForm.data.format} onChange={(e) => exportForm.setData('format', e.target.value as 'csv' | 'xlsx')} className="w-auto">
                                    <option value="csv">CSV</option>
                                    <option value="xlsx">Excel</option>
                                </Select>
                                <Button type="submit" variant="primary" loading={exportForm.processing}>{t('serviceFeedback.requestExport')}</Button>
                            </form>
                        )}
                    >
                        {exports.length === 0 ? <EmptyState title={t('serviceFeedback.noExports')} /> : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('serviceFeedback.submittedDate')}</Th>
                                        <Th>{t('serviceFeedback.exportFormat')}</Th>
                                        <Th>{t('serviceFeedback.filterStatus')}</Th>
                                        <Th align="right">{t('serviceFeedback.rows')}</Th>
                                        <Th align="right">{t('common.actions')}</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {exports.map((row) => (
                                        <Tr key={row.id}>
                                            <Td><LocalizedDateDisplay value={row.created_at} withTime /></Td>
                                            <Td className="uppercase">{row.format}</Td>
                                            <Td>
                                                <StatusBadge status={row.status === 'ready' ? 'active' : row.status === 'failed' ? 'rejected' : 'pending'} label={t(`serviceFeedback.export_${row.status}`)} />
                                                {row.error && <div className="mt-1 text-xs text-red-600">{row.error}</div>}
                                            </Td>
                                            <Td align="right" numeric>{row.row_count ?? '—'}</Td>
                                            <Td align="right">{row.status === 'ready' && <a href={route('service-feedback.submission-reports.download', row.id)} className="text-sm font-medium text-primary underline underline-offset-4">{t('serviceFeedback.download')}</a>}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </TableCard>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'warning' }) {
    return (
        <Card className="p-4">
            <p className="text-sm text-(--app-muted-foreground)">{label}</p>
            <p className={cx('mt-1 text-2xl font-semibold tabular-nums', tone === 'warning' && 'text-amber-700 dark:text-amber-300')}>{value.toLocaleString()}</p>
        </Card>
    );
}

function Breakdown<T extends NamePair>({ title, rows, name, extra, empty }: { title: string; rows: T[]; name: (row: T) => string; extra?: (row: T) => string; empty: string }) {
    const max = Math.max(...rows.map((row) => row.total), 1);

    return (
        <Card className="p-5">
            <h2 className="font-semibold">{title}</h2>
            {rows.length === 0 ? <p className="mt-3 text-sm text-(--app-muted-foreground)">{empty}</p> : (
                <ul className="mt-3 space-y-3">
                    {rows.map((row) => (
                        <li key={row.id ?? 'none'}>
                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="truncate font-medium">{name(row)}</span>
                                <span className="font-semibold tabular-nums">{row.total.toLocaleString()}</span>
                            </div>
                            <div className="mt-1 h-1.5 rounded-full bg-(--app-surface-muted)"><div className="h-1.5 rounded-full bg-primary" style={{ width: `${Math.round((row.total / max) * 100)}%` }} /></div>
                            {extra && <p className="mt-1 text-xs text-(--app-muted-foreground)">{extra(row)}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
