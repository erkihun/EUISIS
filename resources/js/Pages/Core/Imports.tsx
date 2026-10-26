import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppTabNav, ListPagination, TBody, THead, Table, TableCard, Td, Th, Tr, actionLinkClassName, laravelPageMeta } from '@/Components/ui';
import { Button, Card, CardHeader, CardTitle, EmptyState, FileUpload, FormField, Select, type PaginationMeta } from '@euisis/ui';

type Tab = 'validation' | 'ready' | 'processing' | 'completed' | 'failed';

export type ImportBatchSummary = {
    id: string;
    type: string;
    status: string;
    source_filename: string;
    file_size: number;
    checksum: string;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    processed_rows: number;
    failed_rows: number;
    uploaded_by: string | null;
    uploaded_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    completed_at: string | null;
    url: string;
};

type ImportType = { type: string; columns: string[]; can_upload: boolean; template_url: string };

const TABS: Tab[] = ['validation', 'ready', 'processing', 'completed', 'failed'];

/**
 * Core › Imports: upload a file, then follow it from validation through
 * approval by a second person to processing. Nothing is written before
 * approval; every step is the server's.
 */
export default function Imports({ tab, counts, batches, types, urls }: {
    tab: Tab;
    counts: Record<Tab, number>;
    batches: { data: ImportBatchSummary[]; meta: PaginationMeta };
    types: ImportType[];
    urls: { page: string; store: string };
}) {
    const { t } = useLocale();
    const label = (key: string) => t(`core.imports.${key}`);
    const uploadable = types.filter((type) => type.can_upload);
    const form = useForm<{ type: string; file: File | null }>({ type: uploadable[0]?.type ?? '', file: null });
    const chosen = types.find((type) => type.type === form.data.type);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(urls.store, { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} />}>
            <Head title={label('title')} />

            <div className="space-y-6">
                {uploadable.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle>{label('newImport')}</CardTitle></CardHeader>
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-[14rem_1fr_auto] md:items-start">
                            <FormField label={label('type')} required error={form.errors.type}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                                        {uploadable.map((type) => <option key={type.type} value={type.type}>{label(`types.${type.type}`)}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <FileUpload
                                id="import-file"
                                label={label('file')}
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                value={form.data.file}
                                error={form.errors.file}
                                onChange={(file) => form.setData('file', file)}
                            />
                            <Button type="submit" variant="primary" loading={form.processing} className="md:mt-6">{label('upload')}</Button>
                        </form>
                        <p className="mt-3 text-xs text-(--app-muted-foreground)">
                            {label('fileHint')}
                            {chosen && (
                                <>
                                    {' · '}{label('columns')} <code className="text-xs">{chosen.columns.join(', ')}</code> ·{' '}
                                    <a href={chosen.template_url} className={actionLinkClassName()}>{label('template')}</a>
                                </>
                            )}
                        </p>
                    </Card>
                )}

                <AppTabNav label={label('tabsLabel')} current={tab} items={TABS.map((name) => ({ key: name, label: label(`tabs.${name}`), count: counts[name], href: urls.page, data: { tab: name } }))} />

                <TableCard>
                    {batches.data.length === 0 ? <EmptyState title={label('empty')} /> : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{label('fileName')}</Th>
                                    <Th>{label('type')}</Th>
                                    <Th>{label('status')}</Th>
                                    <Th align="right">{label('rows')}</Th>
                                    <Th align="right">{label('invalid')}</Th>
                                    <Th align="right">{label('processed')}</Th>
                                    <Th>{label('uploadedBy')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {batches.data.map((batch) => (
                                    <Tr key={batch.id}>
                                        <Td><Link href={batch.url} className="font-medium text-(--app-foreground) hover:text-primary">{batch.source_filename}</Link></Td>
                                        <Td>{label(`types.${batch.type}`)}</Td>
                                        <Td><StatusBadge status={batch.status} label={label(`statuses.${batch.status}`)} /></Td>
                                        <Td numeric>{batch.total_rows}</Td>
                                        <Td numeric>{batch.invalid_rows}</Td>
                                        <Td numeric>{batch.processed_rows}</Td>
                                        <Td muted>{batch.uploaded_by ?? '—'} · <LocalizedDateDisplay value={batch.uploaded_at} withTime /></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={laravelPageMeta(batches.meta)} onPageChange={(page) => router.get(urls.page, { tab, page }, { preserveScroll: true, preserveState: true })} />
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
