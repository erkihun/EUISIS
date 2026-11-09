import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { FilterField, FilterPanel, ListPagination, TBody, THead, Table, TableCard, Td, Th, Tr, laravelPageMeta } from '@/Components/ui';
import { AppDialog, Button, Card, CardDescription, CardHeader, CardTitle, cx, EmptyState, FieldError, FormField, Input, Select, Textarea, type PaginationMeta } from '@euisis/ui';

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

const CHECKBOX = 'h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary';

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
    const hasFilters = Object.values(filters ?? {}).some((value) => value !== '' && value !== undefined);

    const apply = () => {
        router.get(url, { report, filters: Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== undefined)) }, { preserveState: true, preserveScroll: true });
    };
    const reset = () => router.get(url, { report }, { preserveState: false });
    const submitExport = (event: FormEvent) => {
        event.preventDefault();
        if (!export_url) return;
        form.transform((data) => ({ ...data, filters }));
        form.post(export_url, { preserveScroll: true, onSuccess: () => setExporting(false) });
    };
    const set = (filter: string) => (value: string) => setValues({ ...values, [filter]: value });

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} />}>
            <Head title={label('title')} />
            <div className="grid items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[14.5rem_minmax(0,1fr)]">
                {/* The report list beside the report, as System Settings lists its sections. */}
                <nav aria-label={label('title')} className="min-w-0 lg:sticky lg:top-20 lg:self-start">
                    <ul className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6 lg:mx-0 lg:flex-col lg:gap-0.5 lg:px-0 lg:pb-0">
                        {reports.map((item) => {
                            const selected = report === item.key;
                            return (
                                <li key={item.key}>
                                    <Link href={url} data={{ report: item.key }} aria-current={selected ? 'page' : undefined}
                                        className={cx('flex items-center whitespace-nowrap rounded-control px-3 py-2 text-sm transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                            selected ? 'bg-(--app-surface) font-semibold text-(--app-foreground) shadow-xs ring-1 ring-(--app-border)' : 'font-medium text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)')}>
                                        {label(`names.${item.key}`)}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="min-w-0 space-y-5">
                    {!report && <Card className="p-0"><EmptyState title={label('choose')} /></Card>}
                    {report && definition && (
                        <>
                            <Card>
                                <CardHeader className={definition.filters.length > 0 ? undefined : 'mb-0'}>
                                    <div className="min-w-0">
                                        <CardTitle>{label(`names.${report}`)}</CardTitle>
                                        <CardDescription>{label(`descriptions.${report}`)}</CardDescription>
                                    </div>
                                    {can.export && export_url && <Button variant="primary" size="sm" onClick={() => { form.reset(); form.clearErrors(); setExporting(true); }}>{label('export')}</Button>}
                                </CardHeader>
                                {definition.filters.length > 0 && (
                                    <FilterPanel label={label(`names.${report}`)} onSubmit={apply} onReset={reset} hasActiveFilters={hasFilters} className="border-0 p-0">
                                        {definition.filters.map((filter) => {
                                            const type = FILTER_TYPES[filter] ?? 'text';
                                            const current = values[filter] ?? '';
                                            const caption = label(`filters.${filter}`);
                                            if (type === 'checkbox') return (
                                                <FilterField key={filter} label={caption}>
                                                    <span className="flex h-10 items-center"><input type="checkbox" className={CHECKBOX} checked={current === '1'} onChange={(e) => set(filter)(e.target.checked ? '1' : '')} /></span>
                                                </FilterField>
                                            );
                                            if (type === 'date') return <FilterField key={filter} label={caption}><Input type="date" value={current} onChange={(e) => set(filter)(e.target.value)} /></FilterField>;
                                            if (type === 'text') return <FilterField key={filter} label={caption}><Input value={current} onChange={(e) => set(filter)(e.target.value)} /></FilterField>;
                                            const list = type === 'organization' ? options?.organizations ?? [] : choices(filter);
                                            return (
                                                <FilterField key={filter} label={caption}>
                                                    <Select value={current} onChange={(e) => set(filter)(e.target.value)}>
                                                        <option value="">{label('any')}</option>
                                                        {(list as Array<Option | string>).map((item) => typeof item === 'string'
                                                            ? <option key={item} value={item}>{t(`core.reports.values.${item}`)}</option>
                                                            : <option key={item.id} value={item.id}>{named(item)}</option>)}
                                                    </Select>
                                                </FilterField>
                                            );
                                        })}
                                    </FilterPanel>
                                )}
                            </Card>

                            {preview && (
                                <TableCard>
                                    {preview.rows.length === 0 ? <EmptyState title={label('noRows')} /> : (
                                        <Table className="min-w-[48rem]">
                                            <THead>
                                                <Tr>{preview.columns.map((column) => <Th key={column.key}>{column.label}</Th>)}</Tr>
                                            </THead>
                                            <TBody>
                                                {preview.rows.map((row, index) => (
                                                    <Tr key={index}>{preview.columns.map((column) => <Td key={column.key}>{row[column.key] ?? ''}</Td>)}</Tr>
                                                ))}
                                            </TBody>
                                        </Table>
                                    )}
                                    <ListPagination meta={laravelPageMeta(preview.meta)} onPageChange={(page) => router.get(url, { report, filters, page }, { preserveScroll: true, preserveState: true })} />
                </TableCard>
                            )}
                        </>
                    )}

                    <TableCard title={label('myExports')}>
                        {exports.length === 0 ? <EmptyState title={label('noExports')} /> : (
                            <ul className="divide-y divide-(--app-border) text-sm">
                                {exports.map((run) => (
                                    <li key={run.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <span>
                                            {label(`names.${run.report}`)} · {run.format.toUpperCase()} · <LocalizedDateDisplay value={run.created_at} withTime />
                                            {run.row_count !== null && <> · {run.row_count} {label('rows')}</>}
                                            {run.include_sensitive && <span className="ml-2"><StatusBadge status="warning" label={label('sensitive')} /></span>}
                                            <FieldError className="font-normal">{run.error}</FieldError>
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <StatusBadge status={run.status === 'completed' ? 'active' : run.status === 'failed' ? 'danger' : 'pending'} label={label(`exportStatuses.${run.status}`)} />
                                            {run.download_url && <Button as="a" href={run.download_url} variant="ghost" size="sm">{label('download')}</Button>}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </TableCard>
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
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className={CHECKBOX} checked={form.data.include_sensitive} onChange={(e) => form.setData('include_sensitive', e.target.checked)} />{label('includeSensitive')}</label>
                    )}
                    <FieldError>{(form.errors as Record<string, string | undefined>).include_sensitive}</FieldError>
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
