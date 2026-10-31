import PageHeader from '@/Components/PageHeader';
import { panelCls, scrollCls, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import RangePicker from '@/Components/serviceMonitoring/RangePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ComplianceStatus, ComplianceTally, FiscalYearRow, FinalAction, Named, ReportingLevel, RoleCategory, Selection, ServiceLevel, Summary, VerificationStatus } from '@/types/serviceMonitoring';
import { Head } from '@inertiajs/react';
import { useState, type JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

type Row = { participant_id: string; name_en: string | null; name_am: string | null; level: ReportingLevel; digital: { main: number; sub: number; systems: number }; section4: Record<string, number> };

interface Props {
    years: FiscalYearRow[];
    selection: Selection | null;
    compliance: { period_id: string; label: string; tally: ComplianceTally; participants: number }[];
    report: { rows: Row[]; summary: Summary; totals: Record<string, { digital: { main: number; sub: number; systems: number }; section4: Record<string, number> }> } | null;
    catalog_gaps: { id: string; service_name_am: string | null; service_name_en: string | null; level: ServiceLevel; verification_status: VerificationStatus; quarter: number; organization: Named }[];
}

const TABS = ['compliance', 'accountability', 'digital', 'gaps', 'standards'] as const;
const STATUSES: ComplianceStatus[] = ['on_time', 'late_extension', 'in_review', 'returned', 'pending', 'missing', 'not_submitted'];
const ACTIONS: FinalAction[] = ['warning', 'salary_fine', 'demotion', 'removal_from_responsibility', 'dismissal', 'legal_action', 'other'];
const ROLES: RoleCategory[] = ['appointee_leader', 'merit_leader', 'professional', 'customer'];
const LEVELS: ReportingLevel[] = ['center', 'sub_city', 'woreda'];

/** Compliance, accountability, digital services, catalog gaps and standard performance in scope, without names. */
export default function ReportsIndex({ years, selection, compliance, report, catalog_gaps }: Props): JSX.Element {
    const { sm, name } = useSm();
    const [tab, setTab] = useState<(typeof TABS)[number]>('compliance');

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('reports.title')} description={sm('reports.description')} />}>
            <Head title={sm('reports.title')} />
            <ActionErrors />
            <div className="space-y-4">
                {selection && <RangePicker years={years} selection={selection} routeName="service-monitoring.reports.index" />}
                <div role="tablist" aria-label={sm('reports.title')} className="flex flex-wrap gap-1 border-b border-(--app-border)">
                    {TABS.map((t) => (
                        <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
                            className={`px-3 py-2 text-sm ${tab === t ? 'border-b-2 border-(--app-foreground) font-semibold' : 'text-(--app-muted-foreground)'}`}>{sm(`reports.tabs.${t}`)}</button>
                    ))}
                </div>

                {tab === 'compliance' && (
                    <div className={scrollCls} role="tabpanel">
                        <table className={tableCls}>
                            <thead><tr><th scope="col" className={thCls}>{sm('common.period')}</th><th scope="col" className={thCls}>{sm('reports.participants')}</th>{STATUSES.map((s) => <th key={s} scope="col" className={thCls}>{sm(`compliance.${s}`)}</th>)}</tr></thead>
                            <tbody>{compliance.map((c) => <tr key={c.period_id}><th scope="row" className={thCls}>{c.label}</th><td className={`${tdCls} text-end`}>{c.participants}</td>{STATUSES.map((s) => <td key={s} className={`${tdCls} text-end tabular-nums`}>{c.tally[s]}</td>)}</tr>)}</tbody>
                        </table>
                    </div>
                )}

                {tab === 'accountability' && report && (
                    <div role="tabpanel" className="space-y-2">
                        <p className="text-sm">{sm('reports.people')}: <strong>{report.summary.grand_total}</strong></p>
                        <div className={scrollCls}>
                            <table className={tableCls}>
                                <thead>
                                    <tr><th scope="col" rowSpan={2} className={thCls}>{sm('edit.section1.finalAction')}</th>{LEVELS.map((l) => <th key={l} scope="colgroup" colSpan={ROLES.length} className={`${thCls} text-center`}>{sm(`level.${l}`)}</th>)}</tr>
                                    <tr>{LEVELS.flatMap((l) => ROLES.map((r) => <th key={`${l}-${r}`} scope="col" className={thCls}>{sm(`enums.role_category.${r}`)}</th>))}</tr>
                                </thead>
                                <tbody>
                                    {ACTIONS.map((a) => (
                                        <tr key={a}><th scope="row" className={thCls}>{sm(`enums.final_action.${a}`)}</th>{LEVELS.flatMap((l) => ROLES.map((r) => <td key={`${l}-${r}`} className={`${tdCls} text-end tabular-nums`}>{report.summary.matrix[l]?.[a]?.[r] ?? 0}</td>))}</tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {tab === 'digital' && report && (
                    <div className={scrollCls} role="tabpanel">
                        <table className={tableCls}>
                            <thead><tr>{['common.institution', 'reports.digitalMain', 'reports.digitalSub', 'reports.systems'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr></thead>
                            <tbody>
                                {report.rows.map((r) => <tr key={r.participant_id}><td className={tdCls}>{name(r)}</td><td className={`${tdCls} text-end`}>{r.digital.main}</td><td className={`${tdCls} text-end`}>{r.digital.sub}</td><td className={`${tdCls} text-end`}>{r.digital.systems}</td></tr>)}
                                <tr className="font-semibold"><th scope="row" className={thCls}>{sm('common.all')}</th><td className={`${tdCls} text-end`}>{report.totals.all.digital.main}</td><td className={`${tdCls} text-end`}>{report.totals.all.digital.sub}</td><td className={`${tdCls} text-end`}>{report.totals.all.digital.systems}</td></tr>
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'gaps' && (
                    catalog_gaps.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('reports.gapsEmpty')}</p> : (
                        <div className={scrollCls} role="tabpanel">
                            <table className={tableCls}>
                                <thead><tr>{['common.institution', 'reports.service', 'common.level', 'common.quarter', 'common.status'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr></thead>
                                <tbody>{catalog_gaps.map((g) => <tr key={g.id}><td className={tdCls}>{name(g.organization)}</td><td className={tdCls}>{name({ name_am: g.service_name_am, name_en: g.service_name_en })}</td><td className={tdCls}>{sm(`enums.service_level.${g.level}`)}</td><td className={tdCls}>{g.quarter}</td><td className={tdCls}>{sm(`enums.verification_status.${g.verification_status}`)}</td></tr>)}</tbody>
                            </table>
                        </div>
                    )
                )}

                {tab === 'standards' && report && (
                    <div className={scrollCls} role="tabpanel">
                        <table className={tableCls}>
                            <thead>
                                <tr><th scope="col" rowSpan={2} className={thCls}>{sm('common.institution')}</th><th scope="colgroup" colSpan={3} className={`${thCls} text-center`}>{sm('reports.main')}</th><th scope="colgroup" colSpan={3} className={`${thCls} text-center`}>{sm('reports.sub')}</th></tr>
                                <tr>{['main', 'sub'].flatMap((lv) => ['above', 'at', 'below'].map((p) => <th key={`${lv}-${p}`} scope="col" className={thCls}>{sm(`reports.${p}`)}</th>))}</tr>
                            </thead>
                            <tbody>
                                {report.rows.map((r) => <tr key={r.participant_id}><td className={tdCls}>{name(r)}</td>{['main_above', 'main_at', 'main_below', 'sub_above', 'sub_at', 'sub_below'].map((f) => <td key={f} className={`${tdCls} text-end tabular-nums`}>{r.section4[f] ?? 0}</td>)}</tr>)}
                                <tr className="font-semibold"><th scope="row" className={thCls}>{sm('common.all')}</th>{['main_above', 'main_at', 'main_below', 'sub_above', 'sub_at', 'sub_below'].map((f) => <td key={f} className={`${tdCls} text-end tabular-nums`}>{report.totals.all.section4[f] ?? 0}</td>)}</tr>
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
