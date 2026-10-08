import { ExportButtons, Filters, IssueTile, Kpi, KpiGroup, OversightLayout, named, oversightHref, pct, type Band, type Metrics, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Section, Table, filterInputCls, tdCls, thCls } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';

type Row = { organization: { id: string; code?: string; name_en?: string; name_am?: string | null }; metrics: Metrics; bands: Record<string, number> | null };
type ReasonRow = { code: string | null; name_en: string | null; name_am: string | null; n: number };

type Props = ShellProps & {
    rows: Row[];
    bands: Band[];
    totals: Metrics | null;
    reasons: { exceptions: ReasonRow[]; reported: ReasonRow[]; excluded: ReasonRow[] } | null;
    filters: { organization_id?: string };
    organizations: Array<{ id: string; code: string; name_en: string; name_am: string | null }>;
    summary: null | {
        institutions: Record<string, number>;
        quality: null | { blocking: number; warning: number; info: number };
    };
};

const REPORTS = ['consolidated', 'unassessed', 'distribution', 'gender', 'data_quality', 'submissions'] as const;

/**
 * Reports: the institutional consolidated report (eligible, assessed,
 * unassessed with reasons, result bands, gender) generated from records,
 * plus the exports. No paper form is reproduced.
 */
export default function OversightReports(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, rows, bands, totals, reasons, organizations, summary, filters } = props;
    const allowed = (report: (typeof REPORTS)[number]) => ({ unassessed: can.employees, distribution: can.results, gender: can.demographics, data_quality: can.dataQuality } as Record<string, boolean>)[report] ?? true;
    const label = (b: Band) => (locale === 'am' && b.label_am) || b.label_en;

    return (
        <OversightLayout title={t('assessmentOversight.nav.reports')} description={t('assessmentOversight.reportsHelp')} active="reports" shell={props}>
            {cycle && (
                <>
                    <Filters routeName="assessment-oversight.reports" cycle={cycle}>
                        <select name="organization_id" aria-label={t('assessmentOversight.institution')} className={filterInputCls} defaultValue={filters.organization_id ?? ''}>
                            <option value="">{t('assessmentOversight.allInstitutions')}</option>
                            {organizations.map((organization) => <option key={organization.id} value={organization.id}>{named(organization, locale)}</option>)}
                        </select>
                    </Filters>

                    {totals && <>
                        <KpiGroup title={t('assessmentOversight.sections.overview')} columns={4}>
                            <Kpi label={t('assessmentOversight.eligible')} value={totals.eligible} />
                            <Kpi label={t('assessmentOversight.assessed')} value={totals.assessed}
                                href={can.employees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: 'assessed' }) : undefined} />
                            <Kpi label={t('assessmentOversight.coverage')} value={totals.coverage_percent} suffix="%" />
                            <Kpi label={t('assessmentOversight.unassessed')} value={totals.unassessed}
                                href={can.employees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: 'unassessed' }) : undefined}
                                tone={totals.unassessed > 0 ? 'warning' : undefined} />
                        </KpiGroup>

                        {summary && <div className="grid gap-4 xl:grid-cols-2">
                            <Section title={t('assessmentOversight.charts.submissionStatus')}>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <IssueTile label={t('assessmentOversight.kpi.completed')} value={summary.institutions.completed ?? 0} tone="success"
                                        href={can.institutions ? oversightHref('assessment-oversight.institutions', cycle, filters) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.inProgress')} value={summary.institutions.in_progress ?? 0} tone="info"
                                        href={can.institutions ? oversightHref('assessment-oversight.institutions', cycle, filters) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.returned')} value={(summary.institutions.returned ?? 0) + (summary.institutions.outdated ?? 0)} tone="warning"
                                        href={can.submissions ? oversightHref('assessment-oversight.submissions', cycle, filters) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.overdue')} value={summary.institutions.overdue ?? 0} tone="danger"
                                        href={can.institutions ? oversightHref('assessment-oversight.institutions', cycle, filters) : undefined} />
                                </div>
                            </Section>
                            {summary.quality && <Section title={t('assessmentOversight.sections.dataQuality')}>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    <IssueTile label={t('assessmentOversight.kpi.blockingIssues')} value={summary.quality.blocking} tone="danger"
                                        href={can.dataQuality ? oversightHref('assessment-oversight.data-quality', cycle, filters) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.warnings')} value={summary.quality.warning} tone="warning"
                                        href={can.dataQuality ? oversightHref('assessment-oversight.data-quality', cycle, filters) : undefined} />
                                    <IssueTile label={t('assessmentOversight.severity.info')} value={summary.quality.info} tone="info"
                                        href={can.dataQuality ? oversightHref('assessment-oversight.data-quality', cycle, filters) : undefined} />
                                </div>
                            </Section>}
                        </div>}
                    </>}

                    {can.export && (
                        <Section title={t('assessmentOversight.exportsTitle')} description={t('assessmentOversight.exportsHelp')}>
                            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                {REPORTS.filter(allowed).map((report) => (
                                    <li key={report} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                        <span className="text-sm font-medium">{t(`assessmentOversight.reports.${report}`)}</span>
                                        <ExportButtons report={report} cycle={cycle} filters={props.filters} formats={report === 'unassessed' ? ['csv', 'xlsx'] : ['csv', 'xlsx', 'pdf']} />
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}

                    <Section title={t('assessmentOversight.reports.consolidated')} description={t('assessmentOversight.consolidatedHelp')} flush>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.institution')}</th>
                            <th className={thCls}>{t('assessmentOversight.eligible')}</th>
                            <th className={thCls}>{t('assessmentOversight.assessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.unassessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.coverage')}</th>
                            <th className={thCls}>{t('assessmentOversight.outcomes.approved_exception')}</th>
                            <th className={thCls}>{t('assessmentOversight.outcomes.reported_unassessed')}</th>
                            {can.demographics && <><th className={thCls}>{t('assessmentOversight.maleAssessed')}</th><th className={thCls}>{t('assessmentOversight.femaleAssessed')}</th><th className={thCls}>{t('assessmentOversight.unknownAssessed')}</th></>}
                            {bands.map((b) => <th key={b.code} className={thCls}>{label(b)}</th>)}
                        </>}>
                            {rows.map((row) => {
                                const g = row.metrics.gender;
                                return (
                                    <tr key={row.organization.id}>
                                        <td className={tdCls}>{named(row.organization, locale)}</td>
                                        <td className={`${tdCls} tabular-nums`}>{row.metrics.eligible}</td>
                                        <td className={`${tdCls} tabular-nums`}>{row.metrics.assessed}</td>
                                        <td className={`${tdCls} tabular-nums`}>{row.metrics.unassessed}</td>
                                        <td className={`${tdCls} tabular-nums`}>{pct(row.metrics.coverage_percent)}</td>
                                        <td className={`${tdCls} tabular-nums`}>{row.metrics.outcomes.approved_exception}</td>
                                        <td className={`${tdCls} tabular-nums`}>{row.metrics.outcomes.reported_unassessed}</td>
                                        {can.demographics && g && <>
                                            <td className={`${tdCls} tabular-nums`}>{g.male.suppressed ? '—' : g.male.assessed}</td>
                                            <td className={`${tdCls} tabular-nums`}>{g.female.suppressed ? '—' : g.female.assessed}</td>
                                            <td className={`${tdCls} tabular-nums`}>{g.unknown.suppressed || g.other.suppressed ? '—' : (g.unknown.assessed ?? 0) + (g.other.assessed ?? 0)}</td>
                                        </>}
                                        {bands.map((b) => <td key={b.code} className={`${tdCls} tabular-nums`}>{row.bands?.[b.code] ?? 0}</td>)}
                                    </tr>
                                );
                            })}
                            {totals && (
                                <tr className="bg-gray-50 font-semibold dark:bg-slate-950">
                                    <td className={tdCls}>{t('assessmentOversight.total')}</td>
                                    <td className={`${tdCls} tabular-nums`}>{totals.eligible}</td>
                                    <td className={`${tdCls} tabular-nums`}>{totals.assessed}</td>
                                    <td className={`${tdCls} tabular-nums`}>{totals.unassessed}</td>
                                    <td className={`${tdCls} tabular-nums`}>{pct(totals.coverage_percent)}</td>
                                    <td className={`${tdCls} tabular-nums`}>{totals.outcomes.approved_exception}</td>
                                    <td className={`${tdCls} tabular-nums`}>{totals.outcomes.reported_unassessed}</td>
                                    {can.demographics && totals.gender && <>
                                        <td className={`${tdCls} tabular-nums`}>{totals.gender.male.assessed ?? '—'}</td>
                                        <td className={`${tdCls} tabular-nums`}>{totals.gender.female.assessed ?? '—'}</td>
                                        <td className={`${tdCls} tabular-nums`}>{(totals.gender.unknown.assessed ?? 0) + (totals.gender.other.assessed ?? 0)}</td>
                                    </>}
                                    {bands.map((b) => <td key={b.code} className={`${tdCls} tabular-nums`}>{b.count}</td>)}
                                </tr>
                            )}
                        </Table>
                    </Section>

                    {reasons && (
                        <Section title={t('assessmentOversight.sections.reasons')}>
                            <div className="grid gap-4 md:grid-cols-3">
                                {(['exceptions', 'reported', 'excluded'] as const).map((group) => (
                                    <div key={group}>
                                        <h4 className="text-xs font-semibold uppercase text-gray-500">{t(`assessmentOversight.reasonGroups.${group}`)}</h4>
                                        <ul className="mt-1 text-sm">
                                            {reasons[group].length === 0 && <li className="text-gray-400">—</li>}
                                            {reasons[group].map((r, i) => <li key={`${r.code}-${i}`} className="flex justify-between py-1"><span>{(locale === 'am' && r.name_am) || r.name_en || r.code || t('assessmentOversight.noReason')}</span><span className="tabular-nums">{r.n}</span></li>)}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        </Section>
                    )}
                </>
            )}
        </OversightLayout>
    );
}
