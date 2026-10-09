import { CoverageBar, ExportButtons, Filters, OversightLayout, StatusBadge, named, oversightHref, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Table, TablePanel, filterInputCls, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { Link, router } from '@inertiajs/react';

type Row = {
    organization: { id: string; code: string; name_en: string; name_am: string | null };
    participation: string; submission_status: string; status: string; deadline_status: string | null;
    eligible: number; assigned: number; assessed: number; unassessed: number; coverage_percent: string | null;
    male_assessed: number | null; female_assessed: number | null;
    not_started: number; in_progress: number; awaiting_review: number; finalized: number;
    issues: { blocking: number; warning: number; info: number; total: number };
    submitted_at: string | null; verified_at: string | null;
};

type Props = ShellProps & {
    rows: Paginator<Row> | null;
    filters: { search?: string; submission_status?: string; participation?: string; sort?: string; direction?: string };
};

const SORTS = ['name', 'eligible', 'assessed', 'unassessed', 'coverage', 'submission_status'] as const;

/** Institution Monitoring: one row per participating institution, server-side search, sort and pagination. */
export default function OversightInstitutions(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, rows, filters } = props;

    function sortBy(column: (typeof SORTS)[number]) {
        const direction = filters.sort === column && filters.direction !== 'desc' ? 'desc' : 'asc';
        router.get(route('assessment-oversight.institutions'), { ...filters, cycle: cycle?.id, sort: column, direction }, { preserveState: true });
    }
    const head = (column: (typeof SORTS)[number] | null, label: string) => (
        <th className={thCls} aria-sort={column && filters.sort === column ? (filters.direction === 'desc' ? 'descending' : 'ascending') : undefined}>
            {column ? <button type="button" className="font-semibold hover:underline" onClick={() => sortBy(column)}>{label}{filters.sort === column ? (filters.direction === 'desc' ? ' ↓' : ' ↑') : ''}</button> : label}
        </th>
    );

    return (
        <OversightLayout title={t('assessmentOversight.nav.institutions')} description={t('assessmentOversight.institutionsHelp')} active="institutions" shell={props}
            actions={cycle && can.export ? <ExportButtons report="consolidated" cycle={cycle} /> : undefined}>
            <Filters routeName="assessment-oversight.institutions" cycle={cycle}>
                <input name="search" aria-label={t('assessmentOversight.searchInstitution')} placeholder={t('assessmentOversight.searchInstitution')} className={filterInputCls} defaultValue={filters.search ?? ''} />
                <select name="submission_status" aria-label={t('assessmentOversight.submissionStatus')} className={filterInputCls} defaultValue={filters.submission_status ?? ''}>
                    <option value="">{t('assessmentOversight.allSubmissionStatuses')}</option>
                    {['not_submitted', 'submitted', 'returned', 'verified', 'finalized', 'rejected', 'outdated'].map((s) => <option key={s} value={s}>{t(`assessmentOversight.submissionStatuses.${s}`)}</option>)}
                </select>
                <select name="participation" aria-label={t('assessmentOversight.participation')} className={filterInputCls} defaultValue={filters.participation ?? 'included'}>
                    {['included', 'excluded', 'all'].map((s) => <option key={s} value={s}>{t(`assessmentOversight.participationStatuses.${s}`)}</option>)}
                </select>
            </Filters>

            {rows && (
                <TablePanel page={rows} empty={t('assessmentOversight.noInstitutions')}>
                    <Table head={<>
                        {head('name', t('assessmentOversight.institution'))}
                        {head('eligible', t('assessmentOversight.eligible'))}
                        {head(null, t('assessmentOversight.kpi.assigned'))}
                        {head('assessed', t('assessmentOversight.assessed'))}
                        {head('unassessed', t('assessmentOversight.unassessed'))}
                        {head('coverage', t('assessmentOversight.coverage'))}
                        {can.demographics && head(null, t('assessmentOversight.maleAssessed'))}
                        {can.demographics && head(null, t('assessmentOversight.femaleAssessed'))}
                        {head(null, t('assessmentOversight.outcomes.not_started'))}
                        {head(null, t('assessmentOversight.outcomes.in_progress'))}
                        {head(null, t('assessmentOversight.outcomes.awaiting_review'))}
                        {head(null, t('assessmentOversight.dataIssues'))}
                        {head('submission_status', t('assessmentOversight.status'))}
                        {head(null, t('assessmentOversight.submittedAt'))}
                        {head(null, t('assessmentOversight.verifiedAt'))}
                    </>}>
                        {rows.data.map((row) => (
                            <tr key={row.organization.id}>
                                <td className={tdCls}>
                                    <Link className="font-medium text-[color:var(--color-primary)] hover:underline" href={oversightHref('assessment-oversight.institution', cycle, { organization: row.organization.id })}>{named(row.organization, locale)}</Link>
                                    <div className="text-xs text-gray-500">{row.organization.code}{row.participation === 'excluded' ? ` · ${t('assessmentOversight.participationStatuses.excluded')}` : ''}</div>
                                </td>
                                <td className={`${tdCls} tabular-nums`}>{row.eligible}</td>
                                <td className={`${tdCls} tabular-nums`}>{row.assigned}</td>
                                <td className={`${tdCls} tabular-nums`}>{row.assessed}</td>
                                <td className={`${tdCls} tabular-nums`}>
                                    {can.employees && row.unassessed > 0
                                        ? <Link className="hover:underline" href={oversightHref('assessment-oversight.employees', cycle, { organization_id: row.organization.id, outcome: 'unassessed' })}>{row.unassessed}</Link>
                                        : row.unassessed}
                                </td>
                                <td className={tdCls}><CoverageBar value={row.coverage_percent} /></td>
                                {can.demographics && <td className={`${tdCls} tabular-nums`}>{row.male_assessed ?? '—'}</td>}
                                {can.demographics && <td className={`${tdCls} tabular-nums`}>{row.female_assessed ?? '—'}</td>}
                                <td className={`${tdCls} tabular-nums`}>{row.not_started}</td>
                                <td className={`${tdCls} tabular-nums`}>{row.in_progress}</td>
                                <td className={`${tdCls} tabular-nums`}>{row.awaiting_review}</td>
                                <td className={`${tdCls} tabular-nums`}>
                                    {can.dataQuality && row.issues.total > 0
                                        ? <Link className={row.issues.blocking > 0 ? 'font-semibold text-red-600 hover:underline' : 'hover:underline'} href={oversightHref('assessment-oversight.data-quality', cycle, { organization_id: row.organization.id })}>
                                            {row.issues.blocking > 0 ? `${row.issues.blocking} / ${row.issues.total}` : row.issues.total}
                                        </Link>
                                        : row.issues.total}
                                </td>
                                <td className={tdCls}>
                                    <StatusBadge group="institutionStatuses" value={row.status} />
                                    {row.deadline_status && row.deadline_status !== 'open' && <div className="mt-1"><StatusBadge group="deadline" value={row.deadline_status} /></div>}
                                </td>
                                <td className={tdCls}>{row.submitted_at ? <LocalizedDateDisplay value={row.submitted_at} /> : '—'}</td>
                                <td className={tdCls}>{row.verified_at ? <LocalizedDateDisplay value={row.verified_at} /> : '—'}</td>
                            </tr>
                        ))}
                    </Table>
                </TablePanel>
            )}
        </OversightLayout>
    );
}
