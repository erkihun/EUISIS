import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Empty, Pager, Section, Table, compactInputCls, formatScore, nameOf, pageCls, secondaryBtn, tdCls, thCls, useEnumLabel, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Cell = string | number | null;

type Props = {
    reports: string[];
    report: string;
    filters: { cycle_id: string | null };
    cycles: { id: string; name_en: string; name_am: string | null }[];
    rows: Paginator<Record<string, Cell>>;
    columns: string[];
    /** column → `enum:<group>`, `date` or `score`; other columns are plain text. */
    formats: Record<string, string>;
    can: { export: boolean };
};

/** Scoped EPMS reports; the same query feeds the screen and the streamed CSV export. */
export default function ReportsIndex({ reports, report, filters, cycles, rows, columns, formats, can }: Props) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const go = (params: Record<string, string | null>) => router.get(route('performance.reports.index'), Object.fromEntries(Object.entries({ report, cycle_id: filters.cycle_id, ...params }).filter(([, v]) => v)), { preserveState: true });
    const exportUrl = route('performance.reports.export', Object.fromEntries(Object.entries({ report, cycle_id: filters.cycle_id }).filter(([, v]) => v)) as Record<string, string>);

    const header = (column: string): string => {
        const key = `performance.reports.columns.${column}`;
        const text = t(key);
        return text === key ? column.replace(/_/g, ' ') : text;
    };

    const cell = (column: string, value: Cell): ReactNode => {
        if (value === null || value === '') return '—';
        const format = formats[column];
        if (format?.startsWith('enum:')) return label(format.slice(5), String(value));
        if (format === 'date') return <LocalizedDateDisplay value={String(value)} />;
        if (format === 'score') return formatScore(value);
        return String(value);
    };
    const numeric = (column: string, value: Cell) => typeof value === 'number' || formats[column] === 'score';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.reports.title')} description={t('performance.reports.description')}
            actions={can.export && <a className={secondaryBtn} href={exportUrl}>{t('performance.actions.export')}</a>} />}>
            <Head title={`${t(`performance.reports.names.${report}`)} · ${t('performance.reports.title')}`} />
            <div className={pageCls}>
                <div className="flex flex-wrap items-start gap-3">
                    <nav className="flex min-w-0 flex-1 flex-wrap gap-1" aria-label={t('performance.reports.title')}>
                        {reports.map((name) => (
                            <button key={name} type="button" onClick={() => go({ report: name })} aria-current={name === report ? 'page' : undefined}
                                className={`rounded-lg px-3 py-1.5 text-sm ${name === report ? 'bg-[color:var(--color-primary)] text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800'}`}>
                                {t(`performance.reports.names.${name}`)}
                            </button>
                        ))}
                    </nav>
                    <select aria-label={t('performance.fields.cycle')} className={compactInputCls} value={filters.cycle_id ?? ''} onChange={(e) => go({ cycle_id: e.target.value || null })}>
                        <option value="">{t('performance.fields.cycle')}: —</option>
                        {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}
                    </select>
                </div>

                <Section title={t(`performance.reports.names.${report}`)} description={t(`performance.reports.help.${report}`)} flush>
                    {rows.data.length === 0 ? <Empty>{t('performance.reports.empty')}</Empty> : (
                        <Table head={<>{columns.map((c) => <th key={c} scope="col" className={thCls}>{header(c)}</th>)}</>}>
                            {rows.data.map((row, i) => (
                                <tr key={i}>
                                    {columns.map((c) => <td key={c} className={`${tdCls} ${numeric(c, row[c]) ? 'whitespace-nowrap tabular-nums' : ''}`}>{cell(c, row[c])}</td>)}
                                </tr>
                            ))}
                        </Table>
                    )}
                    {rows.last_page > 1 && <div className="border-t border-gray-100 px-4 py-3 dark:border-slate-800"><Pager page={rows} /></div>}
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
