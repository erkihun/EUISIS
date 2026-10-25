import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, cx, EmptyState, FormField, Input, Pagination, Select, Textarea, type PaginationMeta } from '@euisis/ui';

type Option = { id: string; code?: string; name_en?: string; name_am?: string | null; version_name?: string; status?: string };
type ExportRow = { id: string; report: string; format: string; status: string; row_count: number | null; error: string | null; created_at: string | null; expires_at: string | null; include_sensitive: boolean; download_url: string | null };

const FILTER_TYPES: Record<string, 'organization' | 'select' | 'date' | 'checkbox' | 'text'> = {
    organization: 'organization', subtree: 'checkbox', from: 'date', to: 'date', as_of: 'date', hierarchy_version: 'select', job_grade: 'select', occupation: 'select',
    profession: 'select', employee_status: 'select', position_status: 'select', assignment_status: 'select', card_status: 'select', severity: 'select', action: 'text',
};

const STATUS_VALUES: Record<string, string[]> = {
    employee_status: ['draft', 'submitted', 'verified', 'active', 'retired', 'terminated', 'deceased', 'transferred'],
    position_status: ['draft', 'approved', 'suspended', 'abolished', 'archived'],
    assignment_status: ['active', 'pending', 'closed'],
    card_status: ['pending_print', 'printed', 'issued', 'active', 'suspended', 'lost', 'damaged', 'expired', 'revoked', 'replaced'],
    severity: ['critical', 'high', 'medium', 'low', 'info'],
};

/**
 * Core › Reports (ADR 0011): a report's filters and a paged preview limited
 * to the reader's organizations; exports are prepared in the background,
 * audited, and downloaded only by the person who asked.
 */
export default function Reports({ reports, report, definition, filters, preview, options, can, exports, export_url, url }: {
    reports: Array<{ key: string; citywide: boolean }>;
    report: string | null;
    definition: { filters: string[]; columns: Array<{ key: string; label: string; sensitive: boolean }> } | null;
    filters: Record<string, string>;
    preview: { columns: Array<{ key: string; label: string }>; rows: Array<Record<string, string>>; meta: PaginationMeta } | null;
    options: Record<'organizations' | 'hierarchy_versions' | 'job_grades' | 'occupations' | 'professions', Option[]> | null;
    can: { export: boolean; sensitive: boolean };
    exports: ExportRow[];
    export_url: string | null;
    url: string;
}) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.reports.${key}`);
    const [values, setValues] = useState<Record<string, string>>(filters ?? {});
    const [exporting, setExporting] = useState(false);
    const form = useForm<{ format: string; include_sensitive: boolean; purpose: string; filters: Record<string, string> }>({ format: 'csv', include_sensitive: false, purpose: '', filters: filters ?? {} });
    const named = (option: Option) => (option.version_name ?? `${option.code ? option.code + ' · ' : ''}${locale === 'am' && option.name_am ? option.name_am : option.name_en ?? ''}`);
    const choices = (filter: string): Option[] | string[] => {
        if (filter === 'hierarchy_version') return options?.hierarchy_versions ?? [];
        if (filter === 'job_grade') return options?.job_grades ?? [];
        if (filter === 'occupation') return options?.occupations ?? [];
        if (filter === 'profession') return options?.professions ?? [];
        return STATUS_VALUES[filter] ?? [];
    };

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(url, { report, filters: Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== undefined)) }, { preserveState: true, preserveScroll: true });
    };
    const submitExport = (event: FormEvent) => {
        event.preventDefault();
        if (!export_url) return;
        form.transform((data) => ({ ...data, filters }));
        form.post(export_url, { preserveScroll: true, onSuccess: () => setExporting(false) });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} />}>
            <Head title={label('title')} />
            <div className="grid gap-5 lg:grid-cols-[16rem_1fr]">
                <Card className="p-2">
                    <nav aria-label={label('title')} className="flex flex-col">
                        {reports.map((item) => (
                            <Link key={item.key} href={url} data={{ report: item.key }} aria-current={report === item.key ? 'page' : undefined}
                                className={cx('rounded-md px-3 py-2 text-sm', report === item.key ? 'bg-(--app-surface-muted) font-medium text-(--app-foreground)' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                                {label(`names.${item.key}`)}
                            </Link>
                        ))}
                    </nav>
                </Card>

                <div className="space-y-5">
                    {!report && <Card><EmptyState title={label('choose')} /></Card>}
                    {report && definition && (
                        <>
                            <Card className="p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="font-semibold text-(--app-foreground)">{label(`names.${report}`)}</h2>
                                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{label(`descriptions.${report}`)}</p>
                                    </div>
                                    {can.export && export_url && <Button variant="primary" onClick={() => { form.reset(); form.clearErrors(); setExporting(true); }}>{label('export')}</Button>}
                                </div>
                                {definition.filters.length > 0 && (
                                    <form onSubmit={apply} className="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                        {definition.filters.map((filter) => {
                                            const type = FILTER_TYPES[filter] ?? 'text';
                                            const current = values[filter] ?? '';
                                            if (type === 'checkbox') return (
                                                <label key={filter} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={current === '1'} onChange={(e) => setValues({ ...values, [filter]: e.target.checked ? '1' : '' })} />{label(`filters.${filter}`)}</label>
                                            );
                                            if (type === 'date') return <FormField key={filter} label={label(`filters.${filter}`)}>{({ id }) => <Input id={id} type="date" value={current} onChange={(e) => setValues({ ...values, [filter]: e.target.value })} />}</FormField>;
                                            if (type === 'text') return <FormField key={filter} label={label(`filters.${filter}`)}>{({ id }) => <Input id={id} value={current} onChange={(e) => setValues({ ...values, [filter]: e.target.value })} />}</FormField>;
                                            const list = type === 'organization' ? options?.organizations ?? [] : choices(filter);
                                            return (
                                                <FormField key={filter} label={label(`filters.${filter}`)}>
                                                    {({ id }) => (
                                                        <Select id={id} value={current} onChange={(e) => setValues({ ...values, [filter]: e.target.value })}>
                                                            <option value="">{label('any')}</option>
                                                            {(list as Array<Option | string>).map((item) => typeof item === 'string'
                                                                ? <option key={item} value={item}>{t(`core.reports.values.${item}`)}</option>
                                                                : <option key={item.id} value={item.id}>{named(item)}</option>)}
                                                        </Select>
                                                    )}
                                                </FormField>
                                            );
                                        })}
                                        <div className="flex items-end"><Button type="submit" variant="outline">{label('apply')}</Button></div>
                                    </form>
                                )}
                            </Card>

                            {preview && (
                                <Card className="overflow-x-auto">
                                    {preview.rows.length === 0 ? <EmptyState title={label('noRows')} /> : (
                                        <table className="w-full min-w-[48rem] text-left text-sm">
                                            <thead><tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                                {preview.columns.map((column) => <th key={column.key} scope="col" className="px-3 py-2 font-medium">{column.label}</th>)}
                                            </tr></thead>
                                            <tbody className="divide-y divide-(--app-border)">
                                                {preview.rows.map((row, index) => (
                                                    <tr key={index}>{preview.columns.map((column) => <td key={column.key} className="px-3 py-1.5">{row[column.key] ?? ''}</td>)}</tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </Card>
                            )}
                            {preview && <Pagination meta={preview.meta} onPageChange={(page) => router.get(url, { report, filters, page }, { preserveScroll: true, preserveState: true })} />}
                        </>
                    )}

                    <Card>
                        <div className="border-b border-(--app-border) px-5 py-3"><h2 className="font-semibold text-(--app-foreground)">{label('myExports')}</h2></div>
                        {exports.length === 0 ? <EmptyState title={label('noExports')} /> : (
                            <ul className="divide-y divide-(--app-border) text-sm">
                                {exports.map((run) => (
                                    <li key={run.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                        <span>
                                            {label(`names.${run.report}`)} · {run.format.toUpperCase()} · <LocalizedDateDisplay value={run.created_at} withTime />
                                            {run.row_count !== null && <> · {run.row_count} {label('rows')}</>}
                                            {run.include_sensitive && <span className="ml-2"><StatusBadge status="warning" label={label('sensitive')} /></span>}
                                            {run.error && <span className="block text-xs text-red-600">{run.error}</span>}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <StatusBadge status={run.status === 'completed' ? 'active' : run.status === 'failed' ? 'danger' : 'pending'} label={label(`exportStatuses.${run.status}`)} />
                                            {run.download_url && <a href={run.download_url} className="text-primary hover:underline">{label('download')}</a>}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <AppDialog open={exporting} onClose={() => setExporting(false)} title={label('export')} description={label('exportDescription')}
                footer={<><Button variant="outline" onClick={() => setExporting(false)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="report-export" loading={form.processing}>{label('prepare')}</Button></>}>
                <form id="report-export" onSubmit={submitExport} className="space-y-4">
                    <FormField label={label('format')} error={form.errors.format}>
                        {({ id }) => (
                            <Select id={id} value={form.data.format} onChange={(e) => form.setData('format', e.target.value)}>
                                <option value="csv">CSV</option><option value="xlsx">Excel (.xlsx)</option><option value="html">{label('printable')}</option>
                            </Select>
                        )}
                    </FormField>
                    {can.sensitive && definition?.columns.some((c) => c.sensitive) && (
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.include_sensitive} onChange={(e) => form.setData('include_sensitive', e.target.checked)} />{label('includeSensitive')}</label>
                    )}
                    {(form.errors as Record<string, string | undefined>).include_sensitive && <p className="text-xs text-red-600">{(form.errors as Record<string, string | undefined>).include_sensitive}</p>}
                    {form.data.include_sensitive && (
                        <FormField label={label('purpose')} required description={label('purposeHelp')} error={form.errors.purpose}>
                            {({ id }) => <Textarea id={id} rows={2} maxLength={500} value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} />}
                        </FormField>
                    )}
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
