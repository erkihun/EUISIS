import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import { inputCls, labelCls, panelCls, scrollCls, secondaryBtn, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FiscalYearRow, NamedWithId, Paginated, ReportingLevel, SubmissionListRow, SubmissionStatus } from '@/types/serviceMonitoring';
import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

interface Props {
    submissions: Paginated<SubmissionListRow>;
    filters: { period_id: string | null; level: string; status: string; sub_city_id: string };
    years: FiscalYearRow[];
    sub_cities: NamedWithId[];
}

const LEVELS: ReportingLevel[] = ['center', 'sub_city', 'woreda'];
const STATUSES: SubmissionStatus[] = ['draft', 'submitted', 'returned', 'accepted', 'superseded'];

/** The current revision of every report in scope, filterable by period, level, sub-city and status. */
export default function SubmissionsIndex({ submissions, filters, years, sub_cities }: Props): JSX.Element {
    const { sm, name } = useSm();
    const filter = (changes: Record<string, string>) => router.get(route('service-monitoring.submissions.index'), { ...filters, ...changes } as Record<string, string>, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('submissions.title')} description={sm('submissions.description')} />}>
            <Head title={sm('submissions.title')} />
            <ActionErrors />
            <div className="space-y-4">
                <div className={`${panelCls} grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4`}>
                    <div>
                        <label htmlFor="sm-f-period" className={labelCls}>{sm('common.period')}</label>
                        <select id="sm-f-period" className={inputCls} value={filters.period_id ?? ''} onChange={(e) => filter({ period_id: e.target.value })}>
                            {years.flatMap((y) => y.periods).map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="sm-f-level" className={labelCls}>{sm('common.level')}</label>
                        <select id="sm-f-level" className={inputCls} value={filters.level} onChange={(e) => filter({ level: e.target.value })}>
                            <option value="">{sm('common.all')}</option>
                            {LEVELS.map((l) => <option key={l} value={l}>{sm(`level.${l}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="sm-f-sub" className={labelCls}>{sm('submissions.subCity')}</label>
                        <select id="sm-f-sub" className={inputCls} value={filters.sub_city_id} onChange={(e) => filter({ sub_city_id: e.target.value })}>
                            <option value="">{sm('common.all')}</option>
                            {sub_cities.map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="sm-f-status" className={labelCls}>{sm('common.status')}</label>
                        <select id="sm-f-status" className={inputCls} value={filters.status} onChange={(e) => filter({ status: e.target.value })}>
                            <option value="">{sm('common.all')}</option>
                            {STATUSES.map((s) => <option key={s} value={s}>{sm(`status.${s}`)}</option>)}
                        </select>
                    </div>
                </div>

                {submissions.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('submissions.empty')}</p> : (
                    <div className={scrollCls}>
                        <table className={tableCls}>
                            <thead>
                                <tr>{['common.institution', 'common.level', 'common.period', 'common.status', 'common.submittedAt', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr>
                            </thead>
                            <tbody>
                                {submissions.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className={tdCls}>{name(row.organization)}</td>
                                        <td className={tdCls}>{sm(`level.${row.level}`)} · {sm(`formType.${row.form_type}`)}</td>
                                        <td className={tdCls}>{row.period_label}{row.revision_no > 1 ? ` · ${sm('common.revision', { n: row.revision_no })}` : ''}</td>
                                        <td className={tdCls}><SmStatusBadge kind="submission" status={row.status} />{row.submitted_late && <span className="ms-1 text-xs text-(--app-muted-foreground)">({sm('common.late')})</span>}</td>
                                        <td className={tdCls}><LocalizedDateDisplay value={row.submitted_at} withTime /></td>
                                        <td className={tdCls}>
                                            <div className="flex gap-2">
                                                <Link className={secondaryBtn} href={route('service-monitoring.submissions.show', row.id)}>{sm('common.view')}</Link>
                                                {row.can_edit && <Link className={secondaryBtn} href={route('service-monitoring.submissions.edit', row.id)}>{sm('submissions.edit')}</Link>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={submissions} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
