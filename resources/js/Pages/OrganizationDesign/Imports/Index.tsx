import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import { nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FileUpload, FilterField, FilterPanel, FormField, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

export type BatchSummary = {
    id: string; reference_number: string; entity_type: string; status: string; file_name: string;
    total_rows: number; valid_rows: number; invalid_rows: number; mapping_required_rows: number; committed_rows: number; matched_rows: number;
    organization: Organization | null; organization_id: string; baseline: { id: string; reference_number: string } | null;
    uploaded_by: string | null; created_at: string | null;
};

type Props = {
    batches: LaravelPageMeta & { data: BatchSummary[] };
    filters: { organization_id?: string; entity_type?: string; status?: string };
    organizations: Organization[];
    entityTypes: string[];
    statuses: string[];
    baselines: { id: string; organization_id: string; reference_number: string; title_en: string }[];
    allowedExtensions: string[];
    maxFileKb: number;
    can: { create: boolean };
};

export const BATCH_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
    uploaded: 'neutral', parsing: 'info', validated: 'success', mapping_required: 'warning', committing: 'info', committed: 'success', failed: 'danger', cancelled: 'neutral',
};

/** Staged imports: upload a legacy catalog, see how its rows fared, open a batch to map and commit. */
export default function DesignImportsIndex({ batches, filters, organizations, entityTypes, statuses, baselines, allowedExtensions, maxFileKb, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', entity_type: filters.entity_type ?? '', status: filters.status ?? '' });
    const [uploading, setUploading] = useState(false);
    const form = useForm<{ organization_id: string; entity_type: string; baseline_id: string; file: File | null }>({
        organization_id: filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''), entity_type: entityTypes[0] ?? 'mandates', baseline_id: '', file: null,
    });
    const hasActiveFilters = Object.values(filters).some(Boolean);
    const baselineOptions = baselines.filter((b) => b.organization_id === form.data.organization_id);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, baseline_id: data.baseline_id || null }));
        form.post(route('organization-design.imports.store'), { forceFormData: true, onSuccess: () => { form.reset('file'); setUploading(false); } });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('designImports.title')} description={tt('designImports.description')}
            actions={can.create && <Button variant="primary" onClick={() => setUploading(true)}>{tt('designImports.upload')}</Button>} />}>
            <Head title={tt('designImports.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.imports.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.imports.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('designImports.entityType')}>
                        <Select value={draft.entity_type} onChange={(e) => setDraft({ ...draft, entity_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {entityTypes.map((type) => <option key={type} value={type}>{tt(`designImports.types.${type}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`designImports.status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('designImports.title')}>
                    {batches.data.length === 0 ? (
                        <EmptyState title={tt('designImports.none')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('designImports.noneHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.reference')}</Th>
                                    <Th>{tt('designImports.entityType')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th align="right">{tt('designImports.counts.total')}</Th>
                                    <Th align="right">{tt('designImports.counts.valid')}</Th>
                                    <Th align="right">{tt('designImports.counts.invalid')}</Th>
                                    <Th align="right">{tt('designImports.counts.mapping_required')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {batches.data.map((b) => (
                                    <Tr key={b.id}>
                                        <Td>
                                            <Link href={route('organization-design.imports.show', b.id)} className="font-mono text-xs font-medium text-(--app-foreground) hover:underline">{b.reference_number}</Link>
                                            <p className="truncate text-xs text-(--app-muted-foreground)">{b.file_name} · <LocalizedDateDisplay value={b.created_at} /></p>
                                        </Td>
                                        <Td muted>{tt(`designImports.types.${b.entity_type}`)}</Td>
                                        <Td muted>{nameOf(b.organization, locale)}</Td>
                                        <Td align="right" className="tabular-nums">{b.total_rows}</Td>
                                        <Td align="right" className="tabular-nums">{b.valid_rows}</Td>
                                        <Td align="right" className="tabular-nums">{b.invalid_rows}</Td>
                                        <Td align="right" className="tabular-nums">{b.mapping_required_rows}</Td>
                                        <Td><span className={badgeClassName(BATCH_TONES[b.status] ?? 'neutral')}>{tt(`designImports.status.${b.status}`)}</span></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[{ label: t('common.view'), href: route('organization-design.imports.show', b.id) }]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={batches} />
                </TableCard>
            </div>

            <Modal show={uploading} onClose={() => setUploading(false)} maxWidth="lg">
                <form onSubmit={submit} className="p-6">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{tt('designImports.upload')}</h2>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('designImports.uploadHelp')}</p>
                    <div className="mt-5 space-y-4">
                        <FormField id="import-organization" label={tt('fields.organization')} required error={form.errors.organization_id}>
                            {({ id }) => (
                                <Select id={id} value={form.data.organization_id} onChange={(e) => form.setData((d) => ({ ...d, organization_id: e.target.value, baseline_id: '' }))}>
                                    <option value="">—</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="import-type" label={tt('designImports.entityType')} required error={form.errors.entity_type}>
                            {({ id }) => (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Select id={id} value={form.data.entity_type} onChange={(e) => form.setData('entity_type', e.target.value)} className="min-w-0 flex-1">
                                        {entityTypes.map((type) => <option key={type} value={type}>{tt(`designImports.types.${type}`)}</option>)}
                                    </Select>
                                    <a href={route('organization-design.imports.template', { entityType: form.data.entity_type, locale })} className={actionLinkClassName()}>{tt('designImports.template')}</a>
                                </div>
                            )}
                        </FormField>
                        {baselineOptions.length > 0 && (
                            <FormField id="import-baseline" label={tt('designImports.baseline')} error={form.errors.baseline_id}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.baseline_id} onChange={(e) => form.setData('baseline_id', e.target.value)}>
                                        <option value="">—</option>
                                        {baselineOptions.map((b) => <option key={b.id} value={b.id}>{b.reference_number} · {b.title_en}</option>)}
                                    </Select>
                                )}
                            </FormField>
                        )}
                        <FileUpload id="import-file" label={tt('designImports.file')} value={form.data.file} accept={allowedExtensions.map((e) => `.${e}`).join(',')} maxSizeMb={Math.floor(maxFileKb / 1024)} error={form.errors.file} onChange={(file) => form.setData('file', file)} />
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setUploading(false)}>{tt('actions.cancel')}</Button>
                        <Button type="submit" variant="primary" disabled={!form.data.file || !form.data.organization_id} loading={form.processing}>{tt('designImports.upload')}</Button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
