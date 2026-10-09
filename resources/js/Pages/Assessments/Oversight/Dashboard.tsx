import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import DashboardOverview from '@/Components/assessmentOversight/DashboardOverview';
import { CoverageBar, CoverageBars, Filters, GenderTable, GENDERS, IssueTile, Kpi, KpiGroup, OUTCOMES, OversightLayout, Reconciliation, SeverityBadge, StatusBadge, named, oversightHref, pct, type Distribution, type Metrics, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Pager, Section, Table, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useChartColors } from '@/hooks/useChartColors';
import { useLocale } from '@/hooks/useLocale';
import { Link } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type InstitutionRow = {
    organization: { id: string; code: string; name_en: string; name_am: string | null };
    eligible: number; assessed: number; unassessed: number; coverage_percent: string | null; status: string; submission_status: string;
    issues: { blocking: number; warning: number; info: number; total: number } | null;
};

type Props = ShellProps & {
    filters: Record<string, string>;
    organizations: Array<{ id: string; name_en: string; name_am: string | null }>;
    data: null | {
        totals: Metrics;
        unavailable: string[];
        actions: Array<{ priority: string; code: string; count: number; route: string; filters: Record<string, string> }>;
        institutions: Record<string, number>;
        issues: { blocking: number; warning: number; info: number; missing_evaluator: number; no_applicable_form: number; assignment_errors: number; evaluator: Record<string, number> } | null;
        distribution: Distribution | null;
        comparison: InstitutionRow[];
        institutionPage: Paginator<InstitutionRow>;
        units: Array<Metrics & { unit: { id: string; name_en: string; name_am: string | null } | null }>;
        forms: Array<{ version_id: string; form_en: string; form_am: string | null; version_no: number; assigned: number; assessed: number; completion_percent: string | null }>;
        peers: Array<{ version_id: string; name_en: string; name_am: string | null; required: number; assigned: number; completed: number; missing: number }>;
        reasons: Record<'exceptions' | 'reported' | 'excluded', Array<{ code: string; name_en: string; name_am: string | null; n: number }>>;
        trend: Array<{ cycle: { id: string; code: string; name_en: string; name_am: string | null }; eligible: number; assessed: number; coverage_percent: string | null; eligibility_rules_differ: boolean; band_policy_differs: boolean }>;
        reconciliation: Array<{ check: string; ok: boolean; expected: number; actual: number }>;
    };
};

/**
 * City-level oversight dashboard. Real aggregates for the selected cycle;
 * each figure links to the filtered list it counts.
 */
export default function OversightDashboard(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, data } = props;
    const colors = useChartColors();
    const href = (name: string, params: Record<string, string> = {}) => oversightHref(name, cycle, { ...props.filters, ...params });

    return (
        <OversightLayout title={t('assessmentOversight.dashboardTitle')} description={t('assessmentOversight.dashboardDescription')} active="dashboard" shell={props} needsCycle={false}>
            {!cycle && <>
                <Section title={t('assessmentOversight.dashboardNoCycle')} description={t('assessmentOversight.dashboardSetupHelp')}>
                    {can.setup && <Link className="inline-flex rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white" href={route('assessment-oversight.setup')}>{t('assessmentOversight.nav.setup')}</Link>}
                </Section>
                <KpiGroup title={t('assessmentOversight.kpi.employees')}>
                    {['eligible', 'assessed', 'unassessed', 'coverage'].map((key) => <Kpi key={key} label={t(`assessmentOversight.${key}`)} value={null} />)}
                </KpiGroup>
                <KpiGroup title={t('assessmentOversight.kpi.institutions')}>
                    {['expected', 'started', 'completed', 'submitted', 'verified', 'returned'].map((key) => <Kpi key={key} label={t(`assessmentOversight.kpi.${key}`)} value={null} />)}
                </KpiGroup>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {['dashboardProgress', 'dashboardEvaluators', 'dashboardForms', 'dashboardReasons', ...(can.demographics ? ['kpi.gender'] : []), ...(can.results ? ['charts.resultDistribution'] : [])].map((key) => <Section key={key} title={t(`assessmentOversight.${key}`)}><p className="text-sm text-gray-500">{t('assessmentOversight.dashboardWaiting')}</p></Section>)}
                </div>
            </>}
            {cycle && props.organizations.length > 0 && <Filters routeName="assessment-oversight.dashboard" cycle={cycle}>
                <select aria-label={t('assessmentOversight.institution')} name="organization_id" defaultValue={props.filters.organization_id ?? ''} className="rounded-lg border-gray-300 text-sm dark:bg-slate-900">
                    <option value="">{t('assessmentOversight.allInstitutions')}</option>
                    {props.organizations.map((org) => <option key={org.id} value={org.id}>{named(org, locale)}</option>)}
                </select>
            </Filters>}
            {data && cycle && (
                <>
                    {data.totals.population === 0 && <Section title={t('assessmentOversight.noData')}>
                        {can.setup && <Link href={href('assessment-oversight.setup')}>{t('assessmentOversight.nav.setup')}</Link>}
                    </Section>}
                    {data.unavailable.length > 0 && <p role="status">{t('assessmentOversight.dashboardUnavailable')}</p>}
                    {/* Layout follows the Claude Design canvas: institutions, employees, coverage + compliance, comparison. */}
                    {!props.filters.organization_unit_id && !props.scope.unitLimited && <KpiGroup title={t('assessmentOversight.kpi.institutions')} columns={6}>
                        <Kpi label={t('assessmentOversight.kpi.expected')} value={data.institutions.expected} href={can.institutions ? href('assessment-oversight.institutions') : undefined} />
                        <Kpi label={t('assessmentOversight.kpi.started')} value={data.institutions.started} href={can.institutions ? href('assessment-oversight.institutions') : undefined} />
                        <Kpi label={t('assessmentOversight.kpi.inProgress')} value={data.institutions.in_progress} />
                        <Kpi label={t('assessmentOversight.kpi.completed')} value={data.institutions.completed} hint={t('assessmentOversight.kpi.completedHint')} />
                        <Kpi label={t('assessmentOversight.kpi.submitted')} value={data.institutions.submitted} href={can.submissions ? href('assessment-oversight.submissions', { status: 'submitted' }) : undefined} />
                        <Kpi label={t('assessmentOversight.kpi.verified')} value={data.institutions.verified + data.institutions.finalized} href={can.submissions ? href('assessment-oversight.submissions', { status: 'verified' }) : undefined}
                            hint={data.institutions.finalized > 0 ? `${t('assessmentOversight.institutionStatuses.finalized')}: ${data.institutions.finalized}` : undefined} />
                    </KpiGroup>}
                    {!props.filters.organization_unit_id && !props.scope.unitLimited && (data.institutions.not_started + data.institutions.returned + data.institutions.outdated + (cycle.submission_deadline ? data.institutions.overdue : 0)) > 0 && (
                        <div className="flex flex-wrap gap-2 text-sm" aria-label={t('assessmentOversight.kpi.attention')}>
                            {data.institutions.not_started > 0 && <span className="inline-flex items-center gap-1.5"><StatusBadge group="institutionStatuses" value="not_started" /><span className="tabular-nums">{data.institutions.not_started}</span></span>}
                            {data.institutions.returned > 0 && <Link href={can.submissions ? href('assessment-oversight.submissions', { status: 'returned' }) : '#'} className="inline-flex items-center gap-1.5"><StatusBadge group="institutionStatuses" value="returned" /><span className="tabular-nums">{data.institutions.returned}</span></Link>}
                            {data.institutions.outdated > 0 && <span className="inline-flex items-center gap-1.5"><StatusBadge group="institutionStatuses" value="outdated" /><span className="tabular-nums">{data.institutions.outdated}</span></span>}
                            {cycle.submission_deadline && data.institutions.overdue > 0 && <span className="inline-flex items-center gap-1.5"><StatusBadge group="deadline" value="overdue" /><span className="tabular-nums">{data.institutions.overdue}</span></span>}
                        </div>
                    )}

                    <KpiGroup title={t('assessmentOversight.kpi.employees')} columns={5}>
                        <Kpi label={t('assessmentOversight.eligible')} value={data.totals.eligible} href={can.employees ? href('assessment-oversight.employees', { eligibility_status: 'eligible' }) : undefined}
                            hint={data.totals.excluded > 0 ? `${t('assessmentOversight.excluded')}: ${data.totals.excluded}` : t('assessmentOversight.kpi.fromSnapshot')} />
                        <Kpi label={t('assessmentOversight.kpi.assigned')} value={data.totals.assigned} />
                        <Kpi label={t('assessmentOversight.assessed')} value={data.totals.assessed} href={can.employees ? href('assessment-oversight.employees', { outcome: 'assessed' }) : undefined} hint={t('assessmentOversight.kpi.assessedHint')} />
                        <Kpi label={t('assessmentOversight.unassessed')} value={data.totals.unassessed} href={can.employees ? href('assessment-oversight.employees', { outcome: 'unassessed' }) : undefined} tone="warning" />
                        <Kpi label={t('assessmentOversight.coverage')} value={data.totals.coverage_percent} suffix="%"
                            hint={<span className="block"><span className="block">{`${data.totals.assessed.toLocaleString()} / ${data.totals.eligible.toLocaleString()}`}{data.totals.approved_exclusions > 0 ? ` · ${t('assessmentOversight.grossCoverage')} ${pct(data.totals.gross_coverage_percent)}` : ''}</span>
                                <span className="mt-2 block h-1.5 rounded-full bg-gray-100 dark:bg-slate-800"><span className="block h-1.5 rounded-full bg-[color:var(--color-primary)]" style={{ width: `${Math.max(0, Math.min(100, Number(data.totals.coverage_percent ?? 0)))}%` }} /></span></span>} />
                    </KpiGroup>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <Section title={t('assessmentOversight.charts.coverageByInstitution')} description={t('assessmentOversight.charts.lowestFirst')}>
                            {data.comparison.length === 0 ? <p className="text-sm text-gray-500">{t('assessmentOversight.noData')}</p> : (
                                <CoverageBars rows={data.comparison.map((r) => ({ id: r.organization.id, label: named(r.organization, locale), value: r.coverage_percent,
                                    href: can.institutions ? oversightHref('assessment-oversight.institution', cycle, { organization: r.organization.id }) : undefined }))} />
                            )}
                        </Section>
                        <Section title={t('assessmentOversight.kpi.compliance')} description={t('assessmentOversight.kpi.complianceHelp')}>
                            {data.issues ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <IssueTile label={t('assessmentOversight.kpi.assignmentErrors')} value={data.issues.assignment_errors} tone="danger" href={can.dataQuality ? href('assessment-oversight.data-quality') : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.missingEvaluators')} value={data.issues.missing_evaluator} tone="danger" href={can.dataQuality ? href('assessment-oversight.data-quality', { code: 'MISSING_EVALUATOR' }) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.noApplicableForm')} value={data.issues.no_applicable_form} tone="danger" href={can.dataQuality ? href('assessment-oversight.data-quality', { code: 'NO_APPLICABLE_FORM' }) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.blockingIssues')} value={data.issues.blocking} tone="danger" href={can.dataQuality ? href('assessment-oversight.data-quality', { severity: 'blocking' }) : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.warnings')} value={data.issues.warning} tone="warning" href={can.dataQuality ? href('assessment-oversight.data-quality') : undefined} />
                                    <IssueTile label={t('assessmentOversight.kpi.evaluatorPending')} value={data.issues.evaluator.EVALUATOR_NOT_COMPLETED} tone="info" />
                                </div>
                            ) : <p className="text-sm text-gray-500">{t('assessmentOversight.notAvailable')}</p>}
                            {data.actions.length > 0 && (
                                <div className="mt-4 border-t border-gray-100 pt-3 dark:border-slate-800">
                                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{t('assessmentOversight.dashboardActions')}</h4>
                                    <ul className="space-y-1.5 text-sm">{data.actions.map((action) => <li key={action.code}>
                                        <Link href={href(action.route, action.filters)} className="inline-flex items-center gap-2 hover:underline"><SeverityBadge severity={action.priority} />{t(`assessmentOversight.rules.${action.code}`)}: <span className="tabular-nums">{action.count}</span></Link>
                                    </li>)}</ul>
                                </div>
                            )}
                        </Section>
                    </div>

                    <Section title={t('assessmentOversight.comparison.title')} description={t('assessmentOversight.comparison.help')} flush>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.institution')}</th>
                            <th className={thCls}>{t('assessmentOversight.eligible')}</th>
                            <th className={thCls}>{t('assessmentOversight.assessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.coverage')}</th>
                            <th className={thCls}>{t('assessmentOversight.unassessed')}</th>
                            {can.dataQuality && <th className={thCls}>{t('assessmentOversight.dataIssues')}</th>}
                            <th className={thCls}>{t('assessmentOversight.status')}</th>
                        </>}>
                            {data.comparison.map((row) => (
                                <tr key={row.organization.id}>
                                    <td className={tdCls}>{can.institutions ? <Link className="font-medium hover:underline" href={oversightHref('assessment-oversight.institution', cycle, { organization: row.organization.id })}>{named(row.organization, locale)}</Link> : named(row.organization, locale)}</td>
                                    <td className={`${tdCls} tabular-nums`}>{row.eligible}</td>
                                    <td className={`${tdCls} tabular-nums`}>{row.assessed}</td>
                                    <td className={tdCls}><CoverageBar value={row.coverage_percent} /></td>
                                    <td className={`${tdCls} tabular-nums`}>{row.unassessed}</td>
                                    {can.dataQuality && <td className={`${tdCls} tabular-nums`}>{row.issues && (row.issues.blocking > 0 ? <span className="text-red-600">{row.issues.blocking}</span> : row.issues.total)}</td>}
                                    <td className={tdCls}><StatusBadge group="institutionStatuses" value={row.status} /></td>
                                </tr>
                            ))}
                        </Table>
                        <Pager page={data.institutionPage} />
                    </Section>

                    <DashboardOverview cycle={cycle} totals={data.totals} peers={data.peers} evaluatorsAvailable={!data.unavailable.includes('evaluators')} canEmployees={can.employees} filters={props.filters} />

                    {data.totals.gender && (
                        <KpiGroup title={t('assessmentOversight.kpi.gender')}>
                            {GENDERS.filter((g) => g !== 'other' || (data.totals.gender?.other.eligible ?? 0) > 0).map((g) => (
                                <Kpi key={g} label={`${t(`assessmentOversight.genders.${g}`)} · ${t('assessmentOversight.assessed')}`} value={data.totals.gender?.[g].assessed}
                                    hint={data.totals.gender?.[g].suppressed ? t('assessmentOversight.suppressed') : `${t('assessmentOversight.coverage')} ${pct(data.totals.gender?.[g].coverage_percent ?? null)}`}
                                    href={can.demographics ? href('assessment-oversight.gender') : undefined} />
                            ))}
                        </KpiGroup>
                    )}

                    <div className="grid gap-4 xl:grid-cols-2">
                        {data.totals.gender && <Section title={t('assessmentOversight.kpi.gender')}>
                            <div className="h-64"><ResponsiveContainer width="100%" height="100%"><BarChart data={GENDERS.filter((g) => !data.totals.gender?.[g].suppressed).map((g) => ({ name: t(`assessmentOversight.genders.${g}`), eligible: data.totals.gender?.[g].eligible, assessed: data.totals.gender?.[g].assessed }))}>
                                <CartesianGrid stroke={colors.grid} vertical={false} /><XAxis dataKey="name" /><YAxis allowDecimals={false} /><Tooltip /><Legend />
                                <Bar dataKey="eligible" name={t('assessmentOversight.eligible')} fill={colors.primary} /><Bar dataKey="assessed" name={t('assessmentOversight.assessed')} fill={colors.accent} />
                            </BarChart></ResponsiveContainer></div>
                            <GenderTable gender={data.totals.gender} />
                        </Section>}
                        <Section title={t('assessmentOversight.charts.assessedVsUnassessed')}>
                            <StatusDistribution data={OUTCOMES.map((o) => ({ key: o, value: data.totals.outcomes[o] ?? 0 })).filter((d) => d.value > 0)} labelFor={(k) => t(`assessmentOversight.outcomes.${k}`)} />
                        </Section>
                        {data.distribution && data.distribution.policy && (
                            <Section title={t('assessmentOversight.charts.resultDistribution')} description={`${named(data.distribution.policy, locale)} v${data.distribution.policy.version_no}`}>
                                <div className="h-64">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={data.distribution.bands.map((b) => ({ name: (locale === 'am' && b.label_am) || b.label_en, count: b.count }))}>
                                            <CartesianGrid stroke={colors.grid} vertical={false} />
                                            <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                            <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                                            <Tooltip />
                                            <Bar dataKey="count" fill={colors.accent} radius={[3, 3, 0, 0]} />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </Section>
                        )}
                        <Section title={t('assessmentOversight.charts.submissionStatus')}>
                            <StatusDistribution data={['not_started', 'in_progress', 'ready_for_submission', 'submitted', 'returned', 'verified', 'finalized', 'rejected', 'outdated']
                                .map((k) => ({ key: k, value: data.institutions[k] ?? 0 })).filter((d) => d.value > 0)} labelFor={(k) => t(`assessmentOversight.institutionStatuses.${k}`)} />
                        </Section>
                        {data.trend.length > 1 && (
                            <Section title={t('assessmentOversight.charts.trend')} description={data.trend.some((p) => p.eligibility_rules_differ) ? t('assessmentOversight.charts.trendRulesDiffer') : t('assessmentOversight.charts.trendHelp')}>
                                <div className="h-64">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart data={data.trend.map((p) => ({ name: p.cycle.code, coverage: Number(p.coverage_percent ?? 0) }))}>
                                            <CartesianGrid stroke={colors.grid} vertical={false} />
                                            <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} />
                                            <Tooltip formatter={(v) => `${v}%`} />
                                            <Line type="monotone" dataKey="coverage" stroke={colors.primary} strokeWidth={2} dot />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </Section>
                        )}
                    </div>

                    <div className="grid gap-4 xl:grid-cols-2">
                        {data.units.length > 0 && <Section title={t('assessmentOversight.sections.units')}>
                            {data.units.map((unit) => <div key={unit.group_key ?? 'unassigned'} className="border-b py-3 text-sm dark:border-slate-800">
                                {unit.unit && can.employees ? <Link href={href('assessment-oversight.employees', { organization_unit_id: unit.unit.id, eligibility_status: 'eligible' })}>{named(unit.unit, locale)}</Link> : named(unit.unit, locale)}
                                <p>{t('assessmentOversight.eligible')}: {unit.eligible} · {t('assessmentOversight.assessed')}: {unit.assessed} · {t('assessmentOversight.unassessed')}: {unit.unassessed}</p>
                                <CoverageBar value={unit.coverage_percent} />
                            </div>)}
                        </Section>}
                        <Section title={t('assessmentOversight.dashboardForms')}>
                            {data.forms.map((form) => <div key={form.version_id} className="border-b py-3 text-sm dark:border-slate-800">
                                <p>{named({ name_en: form.form_en, name_am: form.form_am }, locale)} · v{form.version_no}</p>
                                <p>{t('assessmentOversight.kpi.assigned')}: {form.assigned} · {t('assessmentOversight.assessed')}: {form.assessed}</p>
                                <CoverageBar value={form.completion_percent} />
                            </div>)}
                            {data.forms.length === 0 && <p>{t('assessmentOversight.noData')}</p>}
                        </Section>
                        <Section title={t('assessmentOversight.dashboardEvaluators')}>
                            {data.peers.map((peer) => <div key={peer.version_id} className="border-b py-3 text-sm dark:border-slate-800">
                                <p>{named(peer, locale)}</p>
                                <p>{t('assessmentOversight.dashboardRequired')}: {peer.required} · {t('assessmentOversight.kpi.assigned')}: {peer.assigned} · {t('assessmentOversight.kpi.submitted')}: {peer.completed}</p>
                            </div>)}
                            {data.peers.length === 0 && <p>{t('assessmentOversight.noData')}</p>}
                        </Section>
                        <Section title={t('assessmentOversight.dashboardReasons')}>
                            {[...data.reasons.exceptions, ...data.reasons.reported].map((reason, index) => <div key={`${reason.code}-${index}`} className="flex justify-between py-2 text-sm"><span>{named(reason, locale)}</span><span>{reason.n}</span></div>)}
                            {data.reasons.exceptions.length + data.reasons.reported.length === 0 && <p>{t('assessmentOversight.noData')}</p>}
                        </Section>
                    </div>

                    <Reconciliation checks={data.reconciliation} />
                </>
            )}
        </OversightLayout>
    );
}
