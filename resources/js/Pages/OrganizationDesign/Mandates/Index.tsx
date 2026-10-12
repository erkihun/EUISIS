import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import MandateForm, { emptyMandate, type InstrumentType } from '@/Components/organizationDesign/MandateForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

export type MandateSummary = {
    id: string; code: string; version_no: number; lineage_key: string; title_en: string; title_am: string | null; status: string;
    legal_instrument_number: string; legal_instrument_type_id: string;
    instrument_type: { code: string; name_en: string; name_am: string | null } | null;
    organization: Organization | null; organization_id: string;
    effective_from: string | null; effective_to: string | null;
};

type Props = {
    mandates: LaravelPageMeta & { data: MandateSummary[] };
    filters: { organization_id?: string; status?: string; search?: string };
    organizations: Organization[];
    instrumentTypes: InstrumentType[];
    statuses: string[];
    can: { create: boolean };
};

export default function MandatesIndex({ mandates, filters, organizations, instrumentTypes, statuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('mandates.title')} description={tt('mandates.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('mandates.create')}</Button>} />}>
            <Head title={tt('mandates.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.mandates.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.mandates.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('mandates.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('mandates.title')}>
                    {mandates.data.length === 0 ? (
                        <EmptyState title={tt('mandates.noMandates')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('mandates.noMandatesHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{tt('mandates.title')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.instrumentType')}</Th>
                                    <Th>{tt('fields.effectiveFrom')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {mandates.data.map((m) => (
                                    <Tr key={m.id}>
                                        <Td className="font-mono text-xs">{m.code} <span className="text-[color:var(--app-muted-foreground)]">v{m.version_no}</span></Td>
                                        <Td>
                                            <Link href={route('organization-design.mandates.show', m.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{titleOf(m, locale)}</Link>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{m.legal_instrument_number}</p>
                                        </Td>
                                        <Td muted>{nameOf(m.organization, locale)}</Td>
                                        <Td muted>{nameOf(m.instrument_type, locale)}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={m.effective_from} /></Td>
                                        <Td><StatusPill status={m.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('organization-design.mandates.show', m.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={mandates} />
                </TableCard>
            </div>

            {can.create && (
                <MandateForm
                    show={creating}
                    onClose={() => setCreating(false)}
                    title={tt('mandates.create')}
                    initial={emptyMandate(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))}
                    url={route('organization-design.mandates.store')}
                    method="post"
                    organizations={organizations}
                    instrumentTypes={instrumentTypes}
                />
            )}
        </AuthenticatedLayout>
    );
}
