import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import { nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FieldError, FormField, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, badgeClassName, type LaravelPageMeta } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { BATCH_TONES, type BatchSummary } from './Index';

type RowError = { field: string; message: string; suggestions?: { id: string; code: string; name: string | null }[] };
type Row = {
    id: string; row_number: number; status: string; data: Record<string, string | null>; errors: RowError[]; resolved: Record<string, unknown>;
    legacy_code: string | null; matched_id: string | null; match_source: string | null; created_id: string | null;
};

type Props = {
    batch: BatchSummary & { error_summary: Record<string, unknown> | null; committed_by: string | null; committed_at: string | null; parsed_at: string | null };
    columns: string[];
    referenceColumns: string[];
    rows: LaravelPageMeta & { data: Row[] };
    rowStatus: string;
    rowStatuses: string[];
    can: { map: boolean; revalidate: boolean; commit: boolean; cancel: boolean };
};

const ROW_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = { pending: 'neutral', valid: 'success', invalid: 'danger', mapping_required: 'warning', committed: 'success', matched: 'info', skipped: 'neutral' };

/**
 * One staged batch: its counts, every row with its errors and what it
 * matched, a mapping dialog for unresolved references (suggestions are only
 * hints), and the commit that turns valid rows into draft records.
 */
export default function DesignImportShow({ batch, columns, referenceColumns, rows, rowStatus, rowStatuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [mapping, setMapping] = useState<{ row: Row; error: RowError } | null>(null);
    const mapForm = useForm<{ column: string; canonical_id: string }>({ column: '', canonical_id: '' });
    // A wide sheet shows its first columns; the full row is in each row's errors and data.
    const shown = columns.filter((c) => c !== 'description_en' && c !== 'description_am').slice(0, 6);

    function openMap(row: Row, error: RowError) {
        setMapping({ row, error });
        mapForm.setData({ column: error.field, canonical_id: error.suggestions?.[0]?.id ?? '' });
        mapForm.clearErrors();
    }

    async function commit() {
        const { confirmed } = await confirm({ title: tt('designImports.commit'), description: tt('designImports.commitConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.imports.commit', batch.id), {}, { preserveScroll: true });
    }
    async function cancel() {
        const { confirmed } = await confirm({ title: tt('designImports.cancel'), description: tt('designImports.cancelConfirm'), variant: 'danger' });
        if (confirmed) router.post(route('organization-design.imports.cancel', batch.id));
    }

    const missing = (batch.error_summary?.missing_columns as string[] | undefined) ?? [];
    const exception = batch.error_summary?.exception as string | undefined;
    const counts: [string, number][] = [
        ['total', batch.total_rows], ['valid', batch.valid_rows], ['invalid', batch.invalid_rows], ['mapping_required', batch.mapping_required_rows], ['committed', batch.committed_rows], ['matched', batch.matched_rows],
    ];

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.imports.index')}
                title={`${batch.reference_number} · ${tt(`designImports.types.${batch.entity_type}`)}`}
                description={`${nameOf(batch.organization, locale)} · ${batch.file_name}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.revalidate && <Button variant="outline" onClick={() => router.post(route('organization-design.imports.revalidate', batch.id), {}, { preserveScroll: true })}>{tt('designImports.revalidate')}</Button>}
                        {can.cancel && <Button variant="destructiveOutline" onClick={cancel}>{tt('designImports.cancel')}</Button>}
                        {can.commit && <Button variant="primary" onClick={commit}>{tt('designImports.commit')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={batch.reference_number} />

            <div className="space-y-4">
                <Card>
                    <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                        <CardTitle>{tt('designImports.details')}</CardTitle>
                        <span className={badgeClassName(BATCH_TONES[batch.status] ?? 'neutral')}>{tt(`designImports.status.${batch.status}`)}</span>
                    </CardHeader>
                    <dl className="grid grid-cols-2 gap-4 p-5 sm:grid-cols-3 lg:grid-cols-6">
                        {counts.map(([key, value]) => (
                            <div key={key}>
                                <dt className="text-xs text-(--app-muted-foreground)">{tt(`designImports.counts.${key}`)}</dt>
                                <dd className="text-xl font-semibold tabular-nums text-(--app-foreground)">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="flex flex-wrap gap-x-6 gap-y-1 border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">
                        <span>{tt('designImports.uploadedBy')}: {batch.uploaded_by ?? '—'} · <LocalizedDateDisplay value={batch.created_at} /></span>
                        {batch.committed_by && <span>{tt('designImports.committedBy')}: {batch.committed_by} · <LocalizedDateDisplay value={batch.committed_at} /></span>}
                        {batch.baseline && <span>{tt('designImports.baseline')}: {batch.baseline.reference_number}</span>}
                    </div>
                    {(missing.length > 0 || exception) && (
                        <div role="alert" className="border-t border-(--app-border) px-5 py-3 text-sm text-red-700 dark:text-red-300">
                            {missing.length > 0 && <p>{tt('designImports.missingColumns')} <span className="font-mono">{missing.join(', ')}</span></p>}
                            {exception && <p>{tt('designImports.failedReason')}: {exception}</p>}
                        </div>
                    )}
                </Card>

                <TableCard title={tt('designImports.rows')} description={tt('designImports.rowsHelp')}
                    actions={(
                        <Select aria-label={tt('designImports.filterRows')} value={rowStatus} onChange={(e) => router.get(route('organization-design.imports.show', batch.id), e.target.value ? { row_status: e.target.value } : {}, { preserveState: true, preserveScroll: true, replace: true })}>
                            <option value="">{tt('designImports.allRows')}</option>
                            {rowStatuses.map((s) => <option key={s} value={s}>{tt(`designImports.rowStatus.${s}`)}</option>)}
                        </Select>
                    )}>
                    <Table>
                        <THead>
                            <tr>
                                <Th>#</Th>
                                {shown.map((c) => <Th key={c}><span className="font-mono text-xs">{c}</span></Th>)}
                                <Th>{tt('fields.status')}</Th>
                                <Th>{tt('designImports.rows')}</Th>
                            </tr>
                        </THead>
                        <TBody>
                            {rows.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="tabular-nums text-xs">{row.row_number}</Td>
                                    {shown.map((c) => <Td key={c} className="max-w-48 truncate text-xs">{row.data[c] ?? '—'}</Td>)}
                                    <Td>
                                        <span className={badgeClassName(ROW_TONES[row.status] ?? 'neutral')}>{tt(`designImports.rowStatus.${row.status}`)}</span>
                                        {row.match_source && <p className="mt-1 text-[11px] text-(--app-muted-foreground)">{tt(`designImports.matchSource.${row.match_source}`)}</p>}
                                    </Td>
                                    <Td className="min-w-64">
                                        {row.errors.length === 0 ? <span className="text-xs text-(--app-muted-foreground)">—</span> : (
                                            <ul className="space-y-1 text-xs">
                                                {row.errors.map((error, i) => (
                                                    <li key={i} className="flex flex-wrap items-center gap-2">
                                                        <span className="text-(--app-foreground)">{error.message}</span>
                                                        {can.map && referenceColumns.includes(error.field) && <Button size="sm" variant="outline" onClick={() => openMap(row, error)}>{tt('designImports.map')}</Button>}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                    <ListPagination meta={rows} />
                </TableCard>
            </div>

            <Modal show={mapping !== null} onClose={() => setMapping(null)} maxWidth="md">
                {mapping && (
                    <form onSubmit={(e) => { e.preventDefault(); mapForm.put(route('organization-design.imports.rows.map', [batch.id, mapping.row.id]), { preserveScroll: true, onSuccess: () => setMapping(null) }); }} className="p-6">
                        <h2 className="text-base font-semibold text-(--app-foreground)">{tt('designImports.mapTitle')}</h2>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('designImports.mapHelp')}</p>
                        <p className="mt-3 text-sm"><span className="text-(--app-muted-foreground)">{tt('designImports.mapColumn')}:</span> <span className="font-mono">{mapping.error.field}</span> = <span className="font-medium">{mapping.row.data[mapping.error.field]}</span></p>
                        <div className="mt-4 space-y-4">
                            {(mapping.error.suggestions ?? []).length > 0 ? (
                                <FormField id="map-suggestion" label={tt('designImports.suggestions')}>
                                    {({ id }) => (
                                        <Select id={id} value={mapForm.data.canonical_id} onChange={(e) => mapForm.setData('canonical_id', e.target.value)}>
                                            <option value="">—</option>
                                            {(mapping.error.suggestions ?? []).map((s) => <option key={s.id} value={s.id}>{s.code}{s.name ? ` · ${s.name}` : ''}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                            ) : <p className="text-sm text-(--app-muted-foreground)">{tt('designImports.noSuggestions')}</p>}
                            <FormField id="map-id" label={tt('designImports.pickRecordId')} error={mapForm.errors.canonical_id}>
                                {({ id }) => <Input id={id} value={mapForm.data.canonical_id} onChange={(e) => mapForm.setData('canonical_id', e.target.value.trim())} />}
                            </FormField>
                            <FieldError>{mapForm.errors.column}</FieldError>
                        </div>
                        <div className="mt-6 flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setMapping(null)}>{tt('actions.cancel')}</Button>
                            <Button type="submit" variant="primary" disabled={!mapForm.data.canonical_id} loading={mapForm.processing}>{tt('designImports.map')}</Button>
                        </div>
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
