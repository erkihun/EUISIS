import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ProcessForm, { emptyProcess, type Option, type ServiceOption } from '@/Components/organizationDesign/ProcessForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

export type ProcessSummary = {
    id: string; code: string; version_no: number; lineage_key: string; name_en: string; name_am: string | null; status: string; process_type: string;
    service_id: string; service: Option | null; organization: Option | null; organization_id: string; owner_unit: Option | null;
    activities_count: number | null; tasks_count: number | null; effective_from: string | null; effective_to: string | null;
};

type Props = {
    processes: LaravelPageMeta & { data: ProcessSummary[] };
    filters: { organization_id?: string; service_id?: string; status?: string; process_type?: string; search?: string };
    organizations: Option[];
    services: ServiceOption[];
    statuses: string[];
    processTypes: string[];
    can: { create: boolean };
};

export default function ProcessesIndex({ processes, filters, organizations, services, statuses, processTypes, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', service_id: filters.service_id ?? '', status: filters.status ?? '', process_type: filters.process_type ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);
    const counts = (p: ProcessSummary) => tt('processes.counts').replace(':activities', String(p.activities_count ?? 0)).replace(':tasks', String(p.tasks_count ?? 0));

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('processes.title')} description={tt('processes.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('processes.create')}</Button>} />}>
            <Head title={tt('processes.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.processes.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.processes.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('processes.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value, service_id: '' })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.service')}>
                        <Select value={draft.service_id} onChange={(e) => setDraft({ ...draft, service_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {services.filter((s) => !draft.organization_id || s.organization_id === draft.organization_id).map((s) => <option key={s.id} value={s.id}>{s.code} · {nameOf(s, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.processType')}>
                        <Select value={draft.process_type} onChange={(e) => setDraft({ ...draft, process_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {processTypes.map((p) => <option key={p} value={p}>{tt(`processes.types.${p}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('processes.title')}>
                    {processes.data.length === 0 ? (
                        <EmptyState title={tt('processes.noProcesses')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('processes.noProcessesHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{t('common.name')}</Th>
                                    <Th>{tt('fields.service')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.processType')}</Th>
                                    <Th>{tt('processes.tree')}</Th>
                                    <Th>{tt('fields.effectiveFrom')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {processes.data.map((p) => (
                                    <Tr key={p.id}>
                                        <Td className="font-mono text-xs">{p.code} <span className="text-[color:var(--app-muted-foreground)]">v{p.version_no}</span></Td>
                                        <Td><Link href={route('organization-design.processes.show', p.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{nameOf(p, locale)}</Link></Td>
                                        <Td muted>{p.service ? `${p.service.code} · ${nameOf(p.service, locale)}` : '—'}</Td>
                                        <Td muted>{nameOf(p.organization, locale)}</Td>
                                        <Td muted>{tt(`processes.types.${p.process_type}`)}</Td>
                                        <Td muted className="whitespace-nowrap">{counts(p)}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={p.effective_from} /></Td>
                                        <Td><StatusPill status={p.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('organization-design.processes.show', p.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={processes} />
                </TableCard>
            </div>

            {can.create && (
                <ProcessForm
                    show={creating}
                    onClose={() => setCreating(false)}
                    title={tt('processes.create')}
                    initial={emptyProcess(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))}
                    url={route('organization-design.processes.store')}
                    method="post"
                    organizations={organizations}
                    services={services}
                    units={[]}
                    processTypes={processTypes}
                />
            )}
        </AuthenticatedLayout>
    );
}
