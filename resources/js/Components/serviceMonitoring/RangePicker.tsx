import { router } from '@inertiajs/react';
import { useId, type JSX } from 'react';
import type { FiscalYearRow, ReportRange, Selection } from '@/types/serviceMonitoring';
import { inputCls, labelCls, panelCls, useSm } from './helpers';

const RANGES: ReportRange[] = ['quarter', 'six_months', 'nine_months', 'annual'];

/** Fiscal year, quarter and range (Q / 6M / 9M / Annual) for the consolidation and report pages. */
export default function RangePicker({ years, selection, routeName, extra = {} }: {
    years: { id: string; ec_year: number }[] | FiscalYearRow[];
    selection: Selection;
    routeName: string;
    extra?: Record<string, string>;
}): JSX.Element {
    const { sm } = useSm();
    const id = useId();
    const go = (changes: Partial<Selection>) => router.get(route(routeName), { ...extra, ...selection, ...changes } as Record<string, string | number>, { preserveScroll: true, preserveState: true });

    return (
        <div className={`${panelCls} grid gap-3 p-3 sm:grid-cols-3`}>
            <div>
                <label htmlFor={`${id}-year`} className={labelCls}>{sm('common.fiscalYear')}</label>
                <select id={`${id}-year`} className={inputCls} value={selection.fiscal_year_id ?? ''} onChange={(e) => go({ fiscal_year_id: e.target.value })}>
                    {years.map((y) => <option key={y.id} value={y.id}>{y.ec_year}</option>)}
                </select>
            </div>
            <div>
                <label htmlFor={`${id}-quarter`} className={labelCls}>{sm('common.quarter')}</label>
                <select id={`${id}-quarter`} className={inputCls} value={selection.quarter} onChange={(e) => go({ quarter: Number(e.target.value) })}>
                    {[1, 2, 3, 4].map((q) => <option key={q} value={q}>{sm('periods.quarterLabel', { n: q })}</option>)}
                </select>
            </div>
            <div>
                <label htmlFor={`${id}-range`} className={labelCls}>{sm('common.range')}</label>
                <select id={`${id}-range`} className={inputCls} value={selection.range} onChange={(e) => go({ range: e.target.value as ReportRange })}>
                    {RANGES.map((r) => <option key={r} value={r}>{sm(`range.${r}`)}</option>)}
                </select>
            </div>
        </div>
    );
}
