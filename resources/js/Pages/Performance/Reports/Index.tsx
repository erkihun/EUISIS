import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Empty, Pager, Section, Table, compactInputCls, nameOf, pageCls, secondaryBtn, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, router } from '@inertiajs/react';

type Props = {
    reports: string[];
    report: string;
    filters: { cycle_id: string | null };
    cycles: { id: string; name_en: string; name_am: string | null }[];
    rows: Paginator<Record<string, string | number | null>>;
    columns: string[];
    can: { export: boolean };
};

/** Scoped EPMS reports; the same query feeds the screen and the streamed CSV export. */
export default function ReportsIndex({ reports, report, filters, cycles, rows, columns, can }: Props) {
    const { t, locale } = useLocale();
    const go = (params: Record<string, string | null>) => router.get(route('performance.reports.index'), Object.fromEntries(Object.entries({ report, cycle_id: filters.cycle_id, ...params }).filter(([, v]) => v)), { preserveState: true });
    const exportUrl = route('performance.reports.export', Object.fromEntries(Object.entries({ report, cycle_id: filters.cycle_id }).filter(([, v]) => v)) as Record<string, string>);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.reports.title')} description={t('performance.reports.description')}
            actions={can.export && <a className={secondaryBtn} href={exportUrl}>{t('performance.actions.export')}</a>} />}>
            <Head title={t('performance.reports.title')} />
            <div className={pageCls}>
                <div className="flex flex-wrap items-center gap-2">
                    <nav className="flex flex-wrap gap-1" aria-label={t('performance.reports.title')}>
                        {reports.map((name) => (
                            <button key={name} type="button" onClick={() => go({ report: name })} aria-current={name === report ? 'page' : undefined}
                                className={`rounded-lg px-3 py-1.5 text-sm ${name === report ? 'bg-[color:var(--color-primary)] text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800'}`}>
                                {t(`performance.reports.names.${name}`)}
                            </button>
                        ))}
                    </nav>
                    <select aria-label={t('performance.fields.cycle')} className={`${compactInputCls} ml-auto`} value={filters.cycle_id ?? ''} onChange={(e) => go({ cycle_id: e.target.value || null })}>
                        <option value="">{t('performance.fields.cycle')}: —</option>
                        {cycles.map((c) => <option key={c.id} value={c.id}>{nameOf(c, locale)}</option>)}
                    </select>
                </div>

                <Section title={t(`performance.reports.names.${report}`)}>
                    {rows.data.length === 0 ? <Empty>{t('performance.reports.empty')}</Empty> : (
                        <Table head={<>{columns.map((c) => <th key={c} className={thCls}>{c.replace(/_/g, ' ')}</th>)}</>}>
                            {rows.data.map((row, i) => (
                                <tr key={i}>{columns.map((c) => <td key={c} className={`${tdCls} ${typeof row[c] === 'number' ? 'tabular-nums' : ''}`}>{row[c] ?? '—'}</td>)}</tr>
                            ))}
                        </Table>
                    )}
                    <Pager page={rows} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
