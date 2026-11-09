import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useForm } from '@inertiajs/react';
import { useId, type JSX } from 'react';
import type { ExportRow, ReportRange } from '@/types/serviceMonitoring';
import { panelCls, secondaryBtn, useSm } from './helpers';

/** Queues an official-form export (XLSX or PDF) and lists the user's recent exports with their download links. */
export default function ExportPanel({ scopeType, scopeId, fiscalYearId, quarter, range, canNamed, exports }: {
    scopeType: 'submission' | 'sub_city' | 'city';
    scopeId: string | null;
    fiscalYearId: string;
    quarter: number;
    range: ReportRange;
    canNamed: boolean;
    exports: ExportRow[];
}): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const form = useForm({ scope_type: scopeType, scope_id: scopeId ?? '', fiscal_year_id: fiscalYearId, quarter, range, format: 'xlsx', named: false });

    function queue(format: 'xlsx' | 'pdf') {
        form.transform((data) => ({ ...data, format, quarter, range, scope_id: scopeId ?? undefined }));
        form.post(route('service-monitoring.exports.store'), { preserveScroll: true });
    }

    return (
        <section className={`${panelCls} space-y-3 p-4`} aria-labelledby={`${id}-title`}>
            <h2 id={`${id}-title`} className="text-sm font-semibold text-(--app-foreground)">{sm('consolidation.export')}</h2>
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className={secondaryBtn} disabled={form.processing} onClick={() => queue('xlsx')}>XLSX</button>
                <button type="button" className={secondaryBtn} disabled={form.processing} onClick={() => queue('pdf')}>PDF</button>
                {canNamed && (
                    <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                        <input type="checkbox" checked={form.data.named} onChange={(e) => form.setData('named', e.target.checked)} />
                        {sm('consolidation.exportNamed')}
                    </label>
                )}
            </div>
            <h3 className="text-xs font-medium text-(--app-muted-foreground)">{sm('consolidation.exports')}</h3>
            {exports.length === 0 ? (
                <p className="text-sm text-(--app-muted-foreground)">{sm('consolidation.noExports')}</p>
            ) : (
                <ul className="divide-y divide-(--app-border) text-sm">
                    {exports.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>
                                {row.format.toUpperCase()} · {sm(`range.${row.range}`)} · {sm('periods.quarterLabel', { n: row.quarter })} · <LocalizedDateDisplay value={row.created_at} withTime />
                            </span>
                            <span className="flex items-center gap-2">
                                <StatusBadge status={row.status === 'ready' ? 'active' : row.status === 'failed' ? 'rejected' : 'pending'} label={sm(`enums.export_status.${row.status}`)} />
                                {row.download_url && <a className="font-medium underline" href={row.download_url}>{sm('consolidation.download')}</a>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
