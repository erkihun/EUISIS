import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router } from '@inertiajs/react';
import { Button, EmptyState, FilterBar } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, Money, SelectOptions, StatusPill } from '@/Components/Cafeteria/PolicyUi';

type Option = { id: string; code?: string; provider_code?: string; name_en: string; name_am: string | null };
type Filters = { type: string; date_from: string; date_to: string; organization_id: string | null; provider_id: string | null; network_id: string | null; cafeteria_id: string | null };

const MONEY = new Set(['subsidy', 'employee_share', 'provider_total', 'provider_price']);
const DATES = new Set(['date', 'effective_from', 'effective_to']);

export default function AnalyticsIndex({ filters, types, report, organizations, providers, networks, cafeterias, can }: {
    filters: Filters;
    types: string[];
    report: { columns: string[]; rows: Array<Record<string, string | number | null>> };
    organizations: Option[]; providers: Option[]; networks: Option[]; cafeterias: Option[];
    can: { export: boolean };
}) {
    const { t } = useLocale();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const [f, setF] = useState<Filters>(filters);
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== ''));
    const apply = () => router.get(route('cafeteria.analytics.index'), params, { preserveState: true });

    const cell = (column: string, value: string | number | null) => {
        if (value === null || value === '') return column === 'organization' ? tt('unattributed') : '—';
        if (MONEY.has(column)) return <Money value={value} />;
        if (DATES.has(column)) return <LocalizedDateDisplay value={String(value)} />;
        if (column === 'status') return <StatusPill status={String(value)} />;
        if (column === 'usage_type' || column === 'problem' || column === 'cross_location') return t(`cafeteriaPolicy.values.${value}`);
        return value;
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('navAnalytics')} description={tt('analyticsDescription')}
            actions={can.export ? <Button as="a" href={route('cafeteria.analytics.export', params)} size="sm" variant="outline">{tt('exportCsv')}</Button> : undefined} />}>
            <Head title={tt('navAnalytics')} />
            <div className="space-y-4">
                <FilterBar onSubmit={apply} actions={<Button type="submit" size="sm" variant="primary">{tt('run')}</Button>}>
                    <select aria-label={tt('reportType')} className={`${inputCls} w-auto`} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
                        {types.map((type) => <option key={type} value={type}>{t(`cafeteriaPolicy.reports.${type}`)}</option>)}
                    </select>
                    <label className="flex items-center gap-1 text-sm">{tt('dateFrom')}
                        <LocalizedDatePicker id="analytics-from" className={`${inputCls} w-40`} value={f.date_from} onChange={(v) => setF({ ...f, date_from: v })} />
                    </label>
                    <label className="flex items-center gap-1 text-sm">{tt('dateTo')}
                        <LocalizedDatePicker id="analytics-to" className={`${inputCls} w-40`} value={f.date_to} onChange={(v) => setF({ ...f, date_to: v })} />
                    </label>
                    <select aria-label={tt('organization')} className={`${inputCls} w-auto`} value={f.organization_id ?? ''} onChange={(e) => setF({ ...f, organization_id: e.target.value || null })}>
                        <SelectOptions items={organizations.map((o) => ({ ...o, id: o.id }))} placeholder={tt('organization')} />
                    </select>
                    <select aria-label={tt('provider')} className={`${inputCls} w-auto`} value={f.provider_id ?? ''} onChange={(e) => setF({ ...f, provider_id: e.target.value || null })}>
                        <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code ?? p.code }))} placeholder={tt('provider')} />
                    </select>
                    <select aria-label={tt('network')} className={`${inputCls} w-auto`} value={f.network_id ?? ''} onChange={(e) => setF({ ...f, network_id: e.target.value || null })}>
                        <SelectOptions items={networks} placeholder={tt('network')} />
                    </select>
                    <select aria-label={tt('cafeteria')} className={`${inputCls} w-auto`} value={f.cafeteria_id ?? ''} onChange={(e) => setF({ ...f, cafeteria_id: e.target.value || null })}>
                        <SelectOptions items={cafeterias} placeholder={tt('cafeteria')} />
                    </select>
                </FilterBar>

                <div className="overflow-x-auto rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {report.rows.length === 0 ? <EmptyState title={tt('noResults')} /> : (
                        <table className="w-full text-sm">
                            <thead className="border-b border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] text-xs">
                                <tr>{report.columns.map((column) => <th key={column} className={`px-4 py-3 ${MONEY.has(column) ? 'text-end' : 'text-start'}`}>{t(`cafeteriaPolicy.columns.${column}`)}</th>)}</tr>
                            </thead>
                            <tbody className="divide-y divide-[color:var(--app-border)]">
                                {report.rows.map((row, index) => (
                                    <tr key={index}>
                                        {report.columns.map((column) => <td key={column} className={`px-4 py-2 ${MONEY.has(column) ? 'text-end' : ''}`}>{cell(column, row[column] ?? null)}</td>)}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
