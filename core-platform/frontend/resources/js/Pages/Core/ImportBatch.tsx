import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { AppMetricCard, AppTabNav, ListPagination, TBody, THead, Table, TableCard, Td, Th, Tr, laravelPageMeta } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { Alert, AppDialog, Button, EmptyState, FieldError, FormField, Textarea, toneSurfaceClassName, type PaginationMeta } from '@euisis/ui';
import type { ImportBatchSummary } from './Imports';

type RowFilter = 'all' | 'invalid' | 'valid' | 'processed' | 'failed';

type Row = {
    row_number: number;
    status: string;
    values: Record<string, string | null>;
    errors: Array<{ field: string; code: string; message: string }>;
    result_type: string | null;
};

const FILTERS: RowFilter[] = ['all', 'invalid', 'valid', 'processed', 'failed'];

/** One import: its counts, every row with its errors, and the next step the server allows. */
export default function ImportBatch({ batch, columns, rows, filter, can, urls }: {
    batch: ImportBatchSummary & { error_summary: Record<string, unknown> | null; cancel_reason: string | null; cancelled_by: string | null; hierarchy_version_url: string | null };
    columns: string[];
    rows: { data: Row[]; meta: PaginationMeta };
    filter: RowFilter;
    can: { approve: boolean; cancel: boolean };
    urls: { page: string; approve: string; cancel: string; errors: string; index: string };
}) {
    const { t } = useLocale();
    const label = (key: string) => t(`core.imports.${key}`);
    const [dialog, setDialog] = useState<'approve' | 'cancel' | null>(null);
    const form = useForm({ acknowledge_invalid: false, reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const summaryCode = typeof batch.error_summary?.code === 'string' ? batch.error_summary.code : null;

    const submit = () => {
        if (dialog === 'approve') form.post(urls.approve, { preserveScroll: true, onSuccess: () => setDialog(null) });
        if (dialog === 'cancel') form.post(urls.cancel, { preserveScroll: true, onSuccess: () => setDialog(null) });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                backHref={urls.index}
                title={batch.source_filename}
                description={`${label(`types.${batch.type}`)} · ${batch.uploaded_by ?? '—'}`}
                actions={(
                    <>
                        {(batch.invalid_rows > 0 || batch.failed_rows > 0) && <Button as="a" href={urls.errors} size="sm" variant="outline">{label('errorReport')}</Button>}
                        {can.cancel && <Button size="sm" variant="outline" onClick={() => { form.reset(); setDialog('cancel'); }}>{label('cancel')}</Button>}
                        {can.approve && <Button size="sm" variant="primary" onClick={() => { form.reset(); setDialog('approve'); }}>{label('approve')}</Button>}
                    </>
                )}
            />
        )}>
            <Head title={batch.source_filename} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={batch.status} label={label(`statuses.${batch.status}`)} />
                    <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={batch.uploaded_at} withTime /></span>
                    {batch.approved_by && <span className="text-xs text-(--app-muted-foreground)">{label('approvedBy')} {batch.approved_by} · <LocalizedDateDisplay value={batch.approved_at} withTime /></span>}
                </div>
                {['uploaded', 'validating', 'approved', 'processing'].includes(batch.status) && (
                    <Alert tone="info">
                        {label(`working.${batch.status}`)}{' '}
                        <Button variant="ghost" size="sm" onClick={() => router.reload()}>{label('refresh')}</Button>
                    </Alert>
                )}
                {summaryCode && <Alert tone="danger">{label(`summary.${summaryCode}`)}</Alert>}
                {batch.cancel_reason && <Alert tone="neutral">{label('cancelledBecause')} {batch.cancel_reason} ({batch.cancelled_by})</Alert>}
                {batch.hierarchy_version_url && (
                    <p><Button as={Link} href={batch.hierarchy_version_url} variant="ghost" size="sm">{label('hierarchyDraft')}</Button></p>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <AppMetricCard label={label('rows')} value={batch.total_rows} variant="neutral" />
                    <AppMetricCard label={label('valid')} value={batch.valid_rows} variant="primary" />
                    <AppMetricCard label={label('invalid')} value={batch.invalid_rows} variant={batch.invalid_rows > 0 ? 'warning' : 'neutral'} />
                    <AppMetricCard label={label('processed')} value={batch.processed_rows} variant="success" />
                    <AppMetricCard label={label('failed')} value={batch.failed_rows} variant={batch.failed_rows > 0 ? 'danger' : 'neutral'} />
                </div>

                <AppTabNav label={label('rowFilter')} current={filter} items={FILTERS.map((name) => ({ key: name, label: label(`rowFilters.${name}`), href: urls.page, data: { rows: name } }))} />

                <TableCard>
                    {rows.data.length === 0 ? <EmptyState title={label('noRows')} /> : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th align="right">{label('row')}</Th>
                                    <Th>{label('status')}</Th>
                                    {columns.map((column) => <Th key={column}><code className="text-xs">{column}</code></Th>)}
                                    <Th>{label('problems')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.data.map((row) => (
                                    <Tr key={row.row_number}>
                                        <Td numeric>{row.row_number}</Td>
                                        <Td><StatusBadge status={row.status} label={label(`rowStatuses.${row.status}`)} /></Td>
                                        {columns.map((column) => {
                                            const wrong = row.errors.some((error) => error.field === column);
                                            return <Td key={column} className={wrong ? toneSurfaceClassName('danger') : undefined}>{row.values[column] ?? ''}</Td>;
                                        })}
                                        <Td>
                                            {row.errors.map((error, index) => (
                                                <FieldError key={index} className="font-normal"><code>{error.code}</code> {error.message}</FieldError>
                                            ))}
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={laravelPageMeta(rows.meta)} onPageChange={(page) => router.get(urls.page, { rows: filter, page }, { preserveScroll: true, preserveState: true })} />
                </TableCard>
            </div>

            <AppDialog
                open={dialog !== null}
                onClose={() => setDialog(null)}
                title={label(dialog === 'cancel' ? 'cancelTitle' : 'approveTitle')}
                description={label(dialog === 'cancel' ? 'cancelDescription' : 'approveDescription')}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setDialog(null)} disabled={form.processing}>{label('close')}</Button>
                        <Button variant={dialog === 'cancel' ? 'destructive' : 'primary'} loading={form.processing} onClick={submit}>
                            {label(dialog === 'cancel' ? 'cancel' : 'approve')}
                        </Button>
                    </>
                )}
            >
                {dialog === 'approve' && (
                    <div className="space-y-3 text-sm">
                        <p>{label('approveCounts').replace(':valid', String(batch.valid_rows)).replace(':invalid', String(batch.invalid_rows))}</p>
                        {batch.invalid_rows > 0 && (
                            <label className="flex items-start gap-2">
                                <input type="checkbox" className="mt-0.5 h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                    checked={form.data.acknowledge_invalid} onChange={(event) => form.setData('acknowledge_invalid', event.target.checked)} />
                                {label('acknowledgeInvalid')}
                            </label>
                        )}
                        <FieldError>{errors.acknowledge_invalid ?? errors.batch}</FieldError>
                    </div>
                )}
                {dialog === 'cancel' && (
                    <FormField label={label('reason')} required error={errors.reason}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />}
                    </FormField>
                )}
            </AppDialog>
        </AuthenticatedLayout>
    );
}
