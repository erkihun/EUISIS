import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ConsolidationForms from '@/Components/serviceMonitoring/ConsolidationForms';
import ExportPanel from '@/Components/serviceMonitoring/ExportPanel';
import { panelCls, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import RangePicker from '@/Components/serviceMonitoring/RangePicker';
import ReasonDialog from '@/Components/serviceMonitoring/ReasonDialog';
import { useConfirm } from '@/hooks/useConfirm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ExportRow, FiscalYearRow, OfficialForm, Period, ReportSummary, Selection } from '@/types/serviceMonitoring';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

interface Props {
    selection: Selection;
    years: FiscalYearRow[];
    period?: Period;
    report: ReportSummary | null;
    forms?: OfficialForm[];
    sub_cities?: { participant_id: string; name_en: string | null; name_am: string | null; certified_at: string | null }[];
    exports?: ExportRow[];
    can?: { lock: boolean; reopen: boolean; export: boolean; export_named: boolean };
}

/** The city consolidation (Form A and Form B), the sub-city certifications, the lock (D8, D24) and exports (D25). */
export default function CityConsolidation({ selection, years, period, report, forms, sub_cities, exports, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const { confirm } = useConfirm();
    const [reopening, setReopening] = useState(false);

    async function lock() {
        if (!period) return;
        const { confirmed } = await confirm({ title: sm('periods.lock'), description: sm('periods.lockConfirm'), confirmLabel: sm('periods.lock'), cancelLabel: sm('common.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('service-monitoring.periods.lock', period.id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('consolidation.cityTitle')} description={sm('consolidation.description')}
            actions={<div className="flex gap-2">
                {can?.lock && <button type="button" className={primaryBtn} onClick={lock}>{sm('consolidation.lock')}</button>}
                {can?.reopen && <button type="button" className={secondaryBtn} onClick={() => setReopening(true)}>{sm('periods.reopen')}</button>}
            </div>} />}>
            <Head title={sm('consolidation.cityTitle')} />
            <div className="space-y-4">
                <RangePicker years={years} selection={selection} routeName="service-monitoring.consolidation.city" />
                {report === null ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('consolidation.noData')}</p> : (
                    <>
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            <span>{sm('consolidation.participants', { count: report.participants })}</span>
                            {report.from_snapshot && <span className="text-(--app-muted-foreground)">{sm('consolidation.fromSnapshot')}</span>}
                            {selection.range !== 'quarter' && <span className="text-xs text-(--app-muted-foreground)">{sm('consolidation.selectQuarterOnly')}</span>}
                        </div>
                        {sub_cities && (
                            <section className={`${panelCls} p-4`} aria-labelledby="sm-certs">
                                <h2 id="sm-certs" className="mb-2 text-sm font-semibold">{sm('consolidation.subCities')}</h2>
                                <ul className="grid gap-1 text-sm sm:grid-cols-2">
                                    {sub_cities.map((s) => (
                                        <li key={s.participant_id} className="flex justify-between gap-2">
                                            <span>{name(s)}</span>
                                            <span className={s.certified_at ? 'text-emerald-700 dark:text-emerald-300' : 'text-(--app-muted-foreground)'}>{s.certified_at ? <LocalizedDateDisplay value={s.certified_at} /> : sm('consolidation.notCertified')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {forms && <ConsolidationForms forms={forms} />}
                        {can?.export && selection.fiscal_year_id && (
                            <ExportPanel scopeType="city" scopeId={null} fiscalYearId={selection.fiscal_year_id} quarter={selection.quarter} range={selection.range} canNamed={can.export_named} exports={exports ?? []} />
                        )}
                    </>
                )}
            </div>
            {period && <ReasonDialog show={reopening} title={sm('periods.reopen')} label={sm('periods.reopenReason')} url={route('service-monitoring.periods.reopen', period.id)} onClose={() => setReopening(false)} />}
        </AuthenticatedLayout>
    );
}
