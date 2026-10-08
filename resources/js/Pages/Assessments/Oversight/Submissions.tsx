import { ExportButtons, Filters, OversightLayout, StatusBadge, named, oversightHref, pct, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Table, TablePanel, filterInputCls, smallBtn, smallPrimaryBtn, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Row = {
    id: string; revision_no: number; status: string; eligible_count: number; assessed_count: number; unassessed_count: number; coverage_percent: string | null; return_reason: string | null;
    organization: { id: string; code: string; name_en: string; name_am: string | null } | null; submitter: string | null;
    submitted_at: string | null; verified_at: string | null; finalized_at: string | null;
    actions: { return: boolean; reject: boolean; verify: boolean; finalize: boolean };
};

type Props = ShellProps & { rows: Paginator<Row> | null; filters: { status?: string } };

/** Institution Submissions and city verification: the snapshot totals each institution signed off. */
export default function OversightSubmissions(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, rows, filters } = props;
    const [comments, setComments] = useState<Record<string, string>>({});
    const move = (row: Row, action: 'return' | 'reject' | 'verify' | 'finalize') =>
        router.post(route('assessment-oversight.submissions.move', { submission: row.id, action }), { comment: comments[row.id] ?? '' }, { preserveScroll: true });

    return (
        <OversightLayout title={t('assessmentOversight.nav.submissions')} description={t('assessmentOversight.submissionsHelp')} active="submissions" shell={props}
            actions={cycle && can.export ? <ExportButtons report="submissions" cycle={cycle} /> : undefined}>
            <Filters routeName="assessment-oversight.submissions" cycle={cycle}>
                <select name="status" aria-label={t('assessmentOversight.status')} className={filterInputCls} defaultValue={filters.status ?? ''}>
                    <option value="">{t('assessmentOversight.allSubmissionStatuses')}</option>
                    {['submitted', 'returned', 'verified', 'finalized', 'rejected', 'outdated'].map((s) => <option key={s} value={s}>{t(`assessmentOversight.submissionStatuses.${s}`)}</option>)}
                </select>
            </Filters>
            {rows && (
                <TablePanel page={rows} empty={t('assessmentOversight.noSubmissions')}>
                    <Table head={<>
                        <th className={thCls}>{t('assessmentOversight.institution')}</th>
                        <th className={thCls}>{t('assessmentOversight.revision')}</th>
                        <th className={thCls}>{t('assessmentOversight.status')}</th>
                        <th className={thCls}>{t('assessmentOversight.eligible')}</th>
                        <th className={thCls}>{t('assessmentOversight.assessed')}</th>
                        <th className={thCls}>{t('assessmentOversight.unassessed')}</th>
                        <th className={thCls}>{t('assessmentOversight.coverage')}</th>
                        <th className={thCls}>{t('assessmentOversight.submittedAt')}</th>
                        <th className={thCls}>{t('assessmentOversight.actionsLabel')}</th>
                    </>}>
                        {rows.data.map((row) => {
                            const any = row.actions.return || row.actions.reject || row.actions.verify || row.actions.finalize;
                            return (
                                <tr key={row.id}>
                                    <td className={tdCls}>{row.organization ? <Link className="hover:underline" href={oversightHref('assessment-oversight.institution', cycle, { organization: row.organization.id })}>{named(row.organization, locale)}</Link> : '—'}<div className="text-xs text-gray-500">{row.submitter}</div></td>
                                    <td className={`${tdCls} tabular-nums`}>{row.revision_no}</td>
                                    <td className={tdCls}><StatusBadge group="submissionStatuses" value={row.status} />{row.return_reason && <div className="mt-1 text-xs text-amber-700">{row.return_reason}</div>}</td>
                                    <td className={`${tdCls} tabular-nums`}>{row.eligible_count}</td>
                                    <td className={`${tdCls} tabular-nums`}>{row.assessed_count}</td>
                                    <td className={`${tdCls} tabular-nums`}>{row.unassessed_count}</td>
                                    <td className={`${tdCls} tabular-nums`}>{pct(row.coverage_percent)}</td>
                                    <td className={tdCls}>{row.submitted_at ? <LocalizedDateDisplay value={row.submitted_at} /> : '—'}</td>
                                    <td className={tdCls}>
                                        {any && (
                                            <div className="flex min-w-56 flex-col gap-1.5">
                                                <input aria-label={t('assessmentOversight.reviewComment')} placeholder={t('assessmentOversight.reviewComment')} className="rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950" value={comments[row.id] ?? ''} onChange={(e) => setComments({ ...comments, [row.id]: e.target.value })} />
                                                <div className="flex flex-wrap gap-1.5">
                                                    {row.actions.verify && <button type="button" className={smallPrimaryBtn} onClick={() => move(row, 'verify')}>{t('assessmentOversight.actions.verify')}</button>}
                                                    {row.actions.finalize && <button type="button" className={smallPrimaryBtn} onClick={() => move(row, 'finalize')}>{t('assessmentOversight.actions.finalize')}</button>}
                                                    {row.actions.return && <button type="button" className={smallBtn} disabled={!(comments[row.id] ?? '').trim()} onClick={() => move(row, 'return')}>{t('assessmentOversight.actions.return')}</button>}
                                                    {row.actions.reject && <button type="button" className={smallBtn} disabled={!(comments[row.id] ?? '').trim()} onClick={() => move(row, 'reject')}>{t('assessmentOversight.actions.reject')}</button>}
                                                </div>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </Table>
                </TablePanel>
            )}
        </OversightLayout>
    );
}
