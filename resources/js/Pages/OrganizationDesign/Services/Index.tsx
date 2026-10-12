import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ServiceForm, { emptyService, type ClassificationOption, type Option } from '@/Components/organizationDesign/ServiceForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

export type ServiceSummary = {
    id: string; code: string; version_no: number; lineage_key: string; name_en: string; name_am: string | null; status: string;
    beneficiary_type: string | null; delivery_channel: string | null; service_classification_id: string | null;
    classification: Option | null; organization: Option | null; organization_id: string; owner_unit: Option | null;
    functions_count: number; effective_from: string | null; effective_to: string | null;
};

type Props = {
    services: LaravelPageMeta & { data: ServiceSummary[] };
    filters: { organization_id?: string; status?: string; service_classification_id?: string; search?: string };
    organizations: Option[];
    classifications: ClassificationOption[];
    cpcClassifications: ClassificationOption[];
    serviceTypes: Option[];
    statuses: string[];
    beneficiaryTypes: string[];
    deliveryChannels: string[];
    can: { create: boolean };
};

export default function ServicesIndex({ services, filters, organizations, classifications, cpcClassifications, serviceTypes, statuses, beneficiaryTypes, deliveryChannels, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', service_classification_id: filters.service_classification_id ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('services.title')} description={tt('services.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('services.create')}</Button>} />}>
            <Head title={tt('services.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.services.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.services.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('services.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.serviceClassification')}>
                        <Select value={draft.service_classification_id} onChange={(e) => setDraft({ ...draft, service_classification_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {classifications.map((c) => <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('services.title')}>
                    {services.data.length === 0 ? (
                        <EmptyState title={tt('services.noServices')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('services.noServicesHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{t('common.name')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.serviceClassification')}</Th>
                                    <Th>{tt('fields.functions')}</Th>
                                    <Th>{tt('fields.effectiveFrom')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {services.data.map((s) => (
                                    <Tr key={s.id}>
                                        <Td className="font-mono text-xs">{s.code} <span className="text-[color:var(--app-muted-foreground)]">v{s.version_no}</span></Td>
                                        <Td>
                                            <Link href={route('organization-design.services.show', s.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{nameOf(s, locale)}</Link>
                                            {s.owner_unit && <p className="text-xs text-[color:var(--app-muted-foreground)]">{nameOf(s.owner_unit, locale)}</p>}
                                        </Td>
                                        <Td muted>{nameOf(s.organization, locale)}</Td>
                                        <Td muted>{s.classification ? `${s.classification.code} · ${nameOf(s.classification, locale)}` : '—'}</Td>
                                        <Td muted>{s.functions_count}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={s.effective_from} /></Td>
                                        <Td><StatusPill status={s.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('organization-design.services.show', s.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={services} />
                </TableCard>
            </div>

            {can.create && (
                <ServiceForm
                    show={creating}
                    onClose={() => setCreating(false)}
                    title={tt('services.create')}
                    initial={emptyService(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))}
                    url={route('organization-design.services.store')}
                    method="post"
                    lookups={{ organizations, functions: [], units: [], classifications, cpcClassifications, isicOptions: [], serviceTypes, beneficiaryTypes, deliveryChannels }}
                />
            )}
        </AuthenticatedLayout>
    );
}
