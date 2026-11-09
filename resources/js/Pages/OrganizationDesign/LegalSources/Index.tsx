import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LegalSourceForm, { emptyLegalSource, type SourceType } from '@/Components/organizationDesign/LegalSourceForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

export type LegalSourceSummary = {
    id: string; code: string; title_en: string; title_am: string | null; status: string;
    source_type_id: string; source_type: { code: string; name_en: string; name_am: string | null } | null;
    organization: Organization | null; organization_id: string | null;
    issuing_authority: string | null; document_number: string | null;
    issue_date: string | null; effective_from: string | null; effective_to: string | null;
    attachments_count: number | null; citations_count: number | null;
};

type Props = {
    sources: LaravelPageMeta & { data: LegalSourceSummary[] };
    filters: { organization_id?: string; status?: string; source_type_id?: string; search?: string };
    organizations: Organization[];
    sourceTypes: SourceType[];
    statuses: string[];
    can: { create: boolean; createCityLevel: boolean };
};

/**
 * The legal source register: every proclamation, regulation, directive,
 * decision, approved structure or study the design cites. City-level
 * sources show for everyone; an institution's sources stay in its scope.
 */
export default function LegalSourcesIndex({ sources, filters, organizations, sourceTypes, statuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', source_type_id: filters.source_type_id ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('legalSources.title')} description={tt('legalSources.description')}
            actions={(can.create || can.createCityLevel) && <Button variant="primary" onClick={() => setCreating(true)}>{tt('legalSources.create')}</Button>} />}>
            <Head title={tt('legalSources.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.legal-sources.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.legal-sources.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('legalSources.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            <option value="city">{tt('legalSources.cityLevel')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.sourceType')}>
                        <Select value={draft.source_type_id} onChange={(e) => setDraft({ ...draft, source_type_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {sourceTypes.map((s) => <option key={s.id} value={s.id}>{nameOf(s, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`legalSources.status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('legalSources.title')}>
                    {sources.data.length === 0 ? (
                        <EmptyState title={tt('legalSources.none')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('legalSources.noneHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{tt('legalSources.source')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.sourceType')}</Th>
                                    <Th>{tt('fields.effectiveFrom')}</Th>
                                    <Th align="right">{tt('legalSources.documents')}</Th>
                                    <Th align="right">{tt('legalSources.citations')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {sources.data.map((s) => (
                                    <Tr key={s.id}>
                                        <Td className="font-mono text-xs">{s.code}</Td>
                                        <Td>
                                            <Link href={route('organization-design.legal-sources.show', s.id)} className="font-medium text-(--app-foreground) hover:underline">{titleOf(s, locale)}</Link>
                                            {s.document_number && <p className="text-xs text-(--app-muted-foreground)">{s.document_number}</p>}
                                        </Td>
                                        <Td muted>{s.organization ? nameOf(s.organization, locale) : tt('legalSources.cityLevel')}</Td>
                                        <Td muted>{nameOf(s.source_type, locale)}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={s.effective_from} /></Td>
                                        <Td align="right" className="tabular-nums">{s.attachments_count ?? 0}</Td>
                                        <Td align="right" className="tabular-nums">{s.citations_count ?? 0}</Td>
                                        <Td><StatusPill status={s.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[{ label: t('common.view'), href: route('organization-design.legal-sources.show', s.id) }]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={sources} />
                </TableCard>
            </div>

            {(can.create || can.createCityLevel) && (
                <LegalSourceForm
                    show={creating}
                    onClose={() => setCreating(false)}
                    title={tt('legalSources.create')}
                    initial={emptyLegalSource(filters.organization_id && filters.organization_id !== 'city' ? filters.organization_id : (organizations.length === 1 && !can.createCityLevel ? organizations[0].id : ''))}
                    url={route('organization-design.legal-sources.store')}
                    method="post"
                    organizations={can.create ? organizations : []}
                    sourceTypes={sourceTypes}
                    allowCityLevel={can.createCityLevel}
                />
            )}
        </AuthenticatedLayout>
    );
}
