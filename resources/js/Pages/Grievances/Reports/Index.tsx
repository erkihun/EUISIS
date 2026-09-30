import { useLocale } from '@/hooks/useLocale';
import { ActionForm, TabLinks, Workspace } from '@/Components/grievances/Workspace';
import { Empty, Section, Stat, Table, fmt, nameOf, secondaryBtn, tdCls, thCls, useEnumLabel } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { ReportsIndexProps } from '@/types/grievances';

const ENUM_COLUMNS: Record<string, string> = { key: 'status', status: 'letter_status', movement_type: 'movement_type', handler_type: 'handler_type', type: 'handler_type', letter_type: 'letter_type' };

/**
 * Aggregate, process-focused reports. No report ranks or lists employees;
 * small groups are suppressed server-side.
 */
export default function Index({ report, reports, result, filters, minGroupSize, options, can }: ReportsIndexProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const w = (k: string) => t(`grievanceWork.${k}`);
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) as Record<string, string>;

    const cell = (column: string, value: unknown) => {
        if (value === null || value === undefined || value === '') return '—';
        if (column.endsWith('_at') || column === 'due_at') return <DateDisplay value={String(value)} />;
        if (column === 'name_en') return String(value);
        if (ENUM_COLUMNS[column]) return label(report === 'by_status' && column === 'key' ? 'status' : ENUM_COLUMNS[column], String(value));
        if (typeof value === 'object') return nameOf(value as { name_en?: string; name_am?: string }, locale);
        return String(value);
    };

    return <Workspace title={w('reports')}>
        <p className="text-sm text-gray-600 dark:text-slate-400">{w('reports_note')}</p>
        <TabLinks items={reports.map(r => ({ label: w(`report_${r}`), href: route('grievances.reports.index', { ...query, report: r }), active: r === report }))} />
        <ActionForm method="get" url={route('grievances.reports.index')} initial={{ ...query, report }} submitLabel={t('grievances.common.filter')} fields={[
            { name: 'from', label: t('grievances.common.from'), type: 'date' }, { name: 'to', label: t('grievances.common.to'), type: 'date' },
            { name: 'organization_id', label: t('grievances.common.organization'), type: 'select', options: options.organizations.map(o => ({ value: o.id!, label: nameOf(o, locale) })) },
            { name: 'category_id', label: t('grievances.common.category'), type: 'select', options: options.categories.map(o => ({ value: o.id!, label: nameOf(o, locale) })) },
        ]} />
        {can.export && <div className="flex flex-wrap gap-2">{(['csv', 'xlsx', 'pdf'] as const).map(f => <a key={f} className={secondaryBtn} href={route('grievances.reports.export', { ...query, report, format: f })}>{w(`export_${f}`)}</a>)}</div>}

        <Section title={w(`report_${report}`)} flush={report !== 'summary'}>
            {report === 'summary' && result.totals
                ? <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">{Object.entries(result.totals).map(([k, v]) => <Stat key={k} label={w(`col_${k}`)} value={v} />)}</div>
                : result.rows.length
                    ? <Table head={<>{result.columns.map(c => <th key={c} className={thCls}>{w(`col_${c}`)}</th>)}</>}>
                        {result.rows.map((row, i) => <tr key={i}>{result.columns.map(c => <td key={c} className={tdCls}>{c === 'name_en' ? nameOf(row as { name_en?: string; name_am?: string }, locale) : cell(c, row[c])}</td>)}</tr>)}
                    </Table>
                    : <Empty>{w('no_rows')}</Empty>}
        </Section>
        {result.suppressed > 0 && <p role="note" className="text-sm text-gray-600 dark:text-slate-400">{fmt(w('suppressed'), { count: result.suppressed, min: minGroupSize })}</p>}
    </Workspace>;
}
