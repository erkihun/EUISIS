import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Alert, Button, Card, CardHeader, CardTitle, FileUpload, FilterField, FilterPanel, FormField, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Row = {
    id: string; code: string; level: number | string; name_en: string; name_am: string | null; is_active: boolean; parent_code: string | null;
};
type Batch = {
    id: string; revision: string; file_name: string; dry_run: boolean; total_rows: number; created_rows: number; updated_rows: number; unchanged_rows: number; failed_rows: number;
    status: string; error_report: { line: number; column: string; message: string }[] | null; uploaded_by: string | null; at: string | null;
};
type Props = {
    scheme: string;
    revision: string;
    revisions: string[];
    rows: LaravelPageMeta & { data: Row[] };
    filters: { scheme?: string; revision?: string; search?: string };
    batches: Batch[];
    can: { manage: boolean };
};

const SCHEMES = ['LOCAL', 'CPC', 'ISIC', 'ISCO'];

export default function ClassificationsIndex({ scheme, revision, revisions, rows, filters, batches, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ scheme, revision, search: filters.search ?? '' });
    const [importing, setImporting] = useState(false);
    const [report, setReport] = useState<Batch | null>(null);
    const hasActiveFilters = Boolean(filters.search);
    // ISIC and ISCO are official datasets: imported, never typed in.
    const editable = scheme === 'LOCAL' || scheme === 'CPC';

    const go = (params: Record<string, string>) => router.get(route('organization-design.classifications.index'), Object.fromEntries(Object.entries(params).filter(([, v]) => v)), { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('classifications.title')} description={tt('classifications.description')}
            actions={can.manage && (
                <div className="flex flex-wrap gap-2">
                    {editable && <Button as={Link} href={route('organization-design.classifications.create', { scheme, revision })} variant="outline">{tt('classifications.add')}</Button>}
                    <Button variant="primary" onClick={() => setImporting(true)}>{tt('classifications.import')}</Button>
                </div>
            )} />}>
            <Head title={tt('classifications.title')} />

            <div className="space-y-4">
                {!can.manage && <Alert tone="info">{tt('classifications.readOnly')}</Alert>}

                <FilterPanel onSubmit={() => go(draft)} onReset={() => go({ scheme, revision })} hasActiveFilters={hasActiveFilters}>
                    <FilterField label={tt('classifications.scheme')}>
                        <Select value={draft.scheme} onChange={(e) => { const next = { ...draft, scheme: e.target.value, revision: '' }; setDraft(next); go(next); }}>
                            {SCHEMES.map((s) => <option key={s} value={s}>{tt(`classifications.schemes.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('classifications.revision')}>
                        <Select value={draft.revision} onChange={(e) => { const next = { ...draft, revision: e.target.value }; setDraft(next); go(next); }}>
                            {revisions.length === 0 && <option value="">{tt('classifications.noRevision')}</option>}
                            {revisions.map((r) => <option key={r} value={r}>{r}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('classifications.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                </FilterPanel>

                <TableCard title={`${tt(`classifications.schemes.${scheme}`)}${revision ? ` · ${revision}` : ''}`}>
                    {rows.data.length === 0 ? (
                        <EmptyState title={tt('classifications.noRows')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('classifications.noRowsHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{t('common.name')}</Th>
                                    <Th>{tt('fields.level')}</Th>
                                    <Th>{tt('classifications.parentCode')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    {can.manage && editable && <Th align="right">{t('common.actions')}</Th>}
                                </tr>
                            </THead>
                            <TBody>
                                {rows.data.map((r) => (
                                    <Tr key={r.id}>
                                        <Td className="font-mono text-xs">{r.code}</Td>
                                        <Td>{can.manage && editable ? <Link href={route('organization-design.classifications.edit', r.id)} className="hover:underline">{nameOf(r, locale)}</Link> : nameOf(r, locale)}</Td>
                                        <Td muted>{r.level}</Td>
                                        <Td muted className="font-mono text-xs">{r.parent_code ?? '—'}</Td>
                                        <Td><StatusPill status={r.is_active ? 'active' : 'inactive'} /></Td>
                                        {can.manage && editable && <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.edit'), href: route('organization-design.classifications.edit', r.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>}
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={rows} />
                </TableCard>

                <Card>
                    <CardHeader><CardTitle>{tt('classifications.batches')}</CardTitle></CardHeader>
                    {batches.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('classifications.noBatches')}</p> : (
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {batches.map((b) => (
                                <li key={b.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-[color:var(--app-foreground)]">{b.file_name} <span className="font-normal text-[color:var(--app-muted-foreground)]">· {b.revision}</span></p>
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                            {tt('classifications.batchCounts').replace(':created', String(b.created_rows)).replace(':updated', String(b.updated_rows)).replace(':unchanged', String(b.unchanged_rows)).replace(':failed', String(b.failed_rows))}
                                            {' · '}{b.uploaded_by ?? '—'} · <LocalizedDateDisplay value={b.at} withTime />
                                        </p>
                                    </div>
                                    <span className={badgeClassName(b.status === 'completed' ? 'success' : b.status === 'previewed' ? 'neutral' : 'warning')}>{tt(`classifications.batchStatus.${b.status}`)}</span>
                                    {b.failed_rows > 0 && <button type="button" className={actionLinkClassName()} onClick={() => setReport(b)}>{tt('classifications.errors')}</button>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {importing && <ImportForm scheme={scheme} revision={revision} onClose={() => setImporting(false)} />}
            {report && (
                <Modal show onClose={() => setReport(null)} maxWidth="2xl">
                    <div className="p-6">
                        <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('classifications.errors')} · {report.file_name}</h2>
                        <ul className="mt-4 max-h-96 divide-y divide-[color:var(--app-border)] overflow-y-auto text-sm">
                            {(report.error_report ?? []).map((e, i) => (
                                <li key={i} className="flex gap-3 py-2"><span className="w-20 shrink-0 font-mono text-xs text-[color:var(--app-muted-foreground)]">{tt('classifications.line')} {e.line}</span><span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{e.column}</span><span>{e.message}</span></li>
                            ))}
                        </ul>
                        <div className="mt-6 flex justify-end"><Button variant="outline" onClick={() => setReport(null)}>{t('common.close')}</Button></div>
                    </div>
                </Modal>
            )}
        </AuthenticatedLayout>
    );
}

/** The CSV importer is an action on the list, not a record, so it stays a dialog. */
function ImportForm({ scheme, revision, onClose }: { scheme: string; revision: string; onClose: () => void }) {
    const { tt } = useDesignText();
    const form = useForm<{ scheme: string; revision: string; file: File | null; dry_run: boolean }>({ scheme, revision, file: null, dry_run: true });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.post(route('organization-design.classifications.import'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('classifications.import')}</h2>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{tt('classifications.importHelp')}</p>
                <div className="mt-5 space-y-4">
                    <FormField id="imp-scheme" label={tt('classifications.scheme')} required error={errors.scheme}>
                        {({ id }) => <Select id={id} value={form.data.scheme} onChange={(e) => form.setData('scheme', e.target.value)}>{SCHEMES.map((s) => <option key={s} value={s}>{tt(`classifications.schemes.${s}`)}</option>)}</Select>}
                    </FormField>
                    <FormField id="imp-revision" label={tt('classifications.importRevision')} required description={tt('classifications.importRevisionHelp')} error={errors.revision}>
                        {({ id }) => <Input id={id} value={form.data.revision} maxLength={20} onChange={(e) => form.setData('revision', e.target.value)} />}
                    </FormField>
                    <FileUpload id="imp-file" label={tt('classifications.file')} accept=".csv,text/csv" maxSizeMb={5} value={form.data.file} error={errors.file} onChange={(file) => form.setData('file', file)} />
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-0.5 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" checked={form.data.dry_run} onChange={(e) => form.setData('dry_run', e.target.checked)} />
                        <span>{tt('classifications.dryRun')}</span>
                    </label>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={!form.data.file}>{tt('classifications.runImport')}</Button>
                </div>
            </form>
        </Modal>
    );
}
