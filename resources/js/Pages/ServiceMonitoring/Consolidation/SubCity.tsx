import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ConsolidationForms from '@/Components/serviceMonitoring/ConsolidationForms';
import ExportPanel from '@/Components/serviceMonitoring/ExportPanel';
import { panelCls, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import RangePicker from '@/Components/serviceMonitoring/RangePicker';
import ReasonDialog from '@/Components/serviceMonitoring/ReasonDialog';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import { useConfirm } from '@/hooks/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Compliance, ExportRow, FiscalYearRow, Named, NamedWithId, OfficialForm, Period, ReportingLevel, ReportSummary, Selection } from '@/types/serviceMonitoring';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    sub_cities: NamedWithId[];
    selection: Selection;
    years: FiscalYearRow[];
    period?: Period;
    report: ReportSummary | null;
    forms?: OfficialForm[];
    woredas?: { participant_id: string; level: ReportingLevel; organization: Named; compliance: Compliance }[];
    certification?: { certified_at: string; certified_by: string | null; snapshot_id: string | null } | null;
    exports?: ExportRow[];
    can?: { certify: boolean; export: boolean; export_named: boolean; record_not_submitted: boolean };
}

/** A sub-city's consolidation (its own report and its woredas), with certification (D8) and exports (D25). */
export default function SubCityConsolidation({ sub_cities, selection, years, period, report, forms, woredas, certification, exports, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const { confirm } = useConfirm();
    const [recording, setRecording] = useState<string | null>(null);

    async function certify() {
        if (!period || !selection.participant_id) return;
        const { confirmed } = await confirm({ title: sm('consolidation.certify'), description: sm('consolidation.certifyConfirm'), confirmLabel: sm('consolidation.certify'), cancelLabel: sm('common.cancel') });
        if (confirmed) router.post(route('service-monitoring.consolidation.certify', [period.id, selection.participant_id]), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('consolidation.subCityTitle')} description={sm('consolidation.description')}
            actions={can?.certify ? <button type="button" className={primaryBtn} onClick={certify}>{sm('consolidation.certify')}</button> : undefined} />}>
            <Head title={sm('consolidation.subCityTitle')} />
            <div className="space-y-4">
                <RangePicker years={years} selection={selection} routeName="service-monitoring.consolidation.sub-city" extra={{ participant_id: selection.participant_id ?? '' }}
                    scope={{ param: 'participant_id', label: sm('consolidation.subCity'), value: selection.participant_id ?? '', options: sub_cities.map((c) => ({ value: c.id, label: name(c) })) }} />

                {report === null ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('consolidation.noData')}</p> : (
                    <>
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            <span>{sm('consolidation.participants', { count: report.participants })}</span>
                            {report.from_snapshot && <span className="text-(--app-muted-foreground)">{sm('consolidation.fromSnapshot')}</span>}
                            {certification && <span className="text-emerald-700 dark:text-emerald-300">{sm('consolidation.certified', { date: '', name: certification.certified_by ?? '' })}<LocalizedDateDisplay value={certification.certified_at} withTime /></span>}
                            {selection.range !== 'quarter' && <span className="text-xs text-(--app-muted-foreground)">{sm('consolidation.selectQuarterOnly')}</span>}
                        </div>

                        {woredas && (
                            <section aria-labelledby="sm-woredas" className="space-y-2">
                                <h2 id="sm-woredas" className="text-sm font-semibold">{sm('consolidation.woredas')}</h2>
                                <div className={scrollCls}>
                                    <table className={tableCls}>
                                        <thead><tr>{['common.institution', 'common.status', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr></thead>
                                        <tbody>
                                            {woredas.map((w) => (
                                                <tr key={w.participant_id}>
                                                    <td className={tdCls}>{name(w.organization)}</td>
                                                    <td className={tdCls}><SmStatusBadge kind="compliance" status={w.compliance.status} /></td>
                                                    <td className={tdCls}>
                                                        <div className="flex flex-wrap gap-2">
                                                            {w.compliance.submission_id && <a className={secondaryBtn} href={route('service-monitoring.submissions.show', w.compliance.submission_id)}>{sm('common.view')}</a>}
                                                            {can?.record_not_submitted && ['missing', 'pending'].includes(w.compliance.status) && (
                                                                <button type="button" className={secondaryBtn} onClick={() => setRecording(w.participant_id)}>{sm('consolidation.notSubmitted')}</button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}

                        {forms && <ConsolidationForms forms={forms} />}
                        {can?.export && selection.fiscal_year_id && (
                            <ExportPanel scopeType="sub_city" scopeId={selection.participant_id ?? null} fiscalYearId={selection.fiscal_year_id} quarter={selection.quarter} range={selection.range} canNamed={can.export_named} exports={exports ?? []} />
                        )}
                    </>
                )}
            </div>
            {period && (
                <ReasonDialog show={recording !== null} title={sm('consolidation.notSubmitted')} label={sm('consolidation.notSubmittedReason')}
                    url={recording ? route('service-monitoring.consolidation.not-submitted', [period.id, recording]) : '#'} onClose={() => setRecording(null)} />
            )}
        </AuthenticatedLayout>
    );
}
