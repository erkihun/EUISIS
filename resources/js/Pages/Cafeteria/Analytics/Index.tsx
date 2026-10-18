import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, router } from '@inertiajs/react';
import { Button, EmptyState, FilterField, FilterPanel, Select } from '@euisis/ui';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { inputCls, Money, SelectOptions, StatusPill } from '@/Components/Cafeteria/PolicyUi';

import { tableBodyClassName, tableClassName, tableHeadClassName } from '@/Components/ui';
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
                <FilterPanel onSubmit={apply} submitLabel={tt('run')}>
                    <FilterField label={tt('reportType')}>
                        <Select value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
                            {types.map((type) => <option key={type} value={type}>{t(`cafeteriaPolicy.reports.${type}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('dateFrom')} htmlFor="analytics-from">
                        <LocalizedDatePicker id="analytics-from" className={inputCls} value={f.date_from} onChange={(v) => setF({ ...f, date_from: v })} />
                    </FilterField>
                    <FilterField label={tt('dateTo')} htmlFor="analytics-to">
                        <LocalizedDatePicker id="analytics-to" className={inputCls} value={f.date_to} onChange={(v) => setF({ ...f, date_to: v })} />
                    </FilterField>
                    <FilterField label={tt('organization')}>
                        <Select value={f.organization_id ?? ''} onChange={(e) => setF({ ...f, organization_id: e.target.value || null })}>
                            <SelectOptions items={organizations.map((o) => ({ ...o, id: o.id }))} placeholder={tt('all')} />
                        </Select>
                    </FilterField>
                    <FilterField label={tt('provider')}>
                        <Select value={f.provider_id ?? ''} onChange={(e) => setF({ ...f, provider_id: e.target.value || null })}>
                            <SelectOptions items={providers.map((p) => ({ ...p, code: p.provider_code ?? p.code }))} placeholder={tt('all')} />
                        </Select>
                    </FilterField>
                    <FilterField label={tt('network')}>
                        <Select value={f.network_id ?? ''} onChange={(e) => setF({ ...f, network_id: e.target.value || null })}>
                            <SelectOptions items={networks} placeholder={tt('all')} />
                        </Select>
                    </FilterField>
                    <FilterField label={tt('cafeteria')}>
                        <Select value={f.cafeteria_id ?? ''} onChange={(e) => setF({ ...f, cafeteria_id: e.target.value || null })}>
                            <SelectOptions items={cafeterias} placeholder={tt('all')} />
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className="overflow-x-auto rounded-card border border-(--app-border) bg-(--app-surface)">
                    {report.rows.length === 0 ? <EmptyState title={tt('noResults')} /> : (
                        <table className={tableClassName}>
                            <thead className={tableHeadClassName}>
                                <tr>{report.columns.map((column) => <th key={column} className={`px-4 py-3 ${MONEY.has(column) ? 'text-end' : 'text-start'}`}>{t(`cafeteriaPolicy.columns.${column}`)}</th>)}</tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
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
