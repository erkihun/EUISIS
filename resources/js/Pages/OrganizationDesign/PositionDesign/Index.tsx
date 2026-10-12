import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Row = {
    id: string; job_position_code: string; title_en: string; title_am: string | null; grade_level: string | null;
    organization: Named | null; unit: Named | null; occupation: (Named & { isco_code: string | null }) | null; profile_status: string | null; job_description_status: string | null;
};
type Props = { positions: LaravelPageMeta & { data: Row[] }; filters: { organization_id?: string; organization_unit_id?: string; search?: string; state?: string }; organizations: Named[] };

export default function PositionDesignIndex({ positions, filters, organizations }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', search: filters.search ?? '', state: filters.state ?? '' });
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('positionDesign.title')} description={tt('positionDesign.description')} />}>
            <Head title={tt('positionDesign.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.position-design.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.position-design.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('positionDesign.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('positionDesign.state')}>
                        <Select value={draft.state} onChange={(e) => setDraft({ ...draft, state: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            <option value="without_profile">{tt('positionDesign.withoutProfile')}</option>
                            <option value="without_jd">{tt('positionDesign.withoutJd')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('positionDesign.title')}>
                    {positions.data.length === 0 ? (
                        <EmptyState title={tt('positionDesign.noPositions')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('positionDesign.noPositionsHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{tt('positionDesign.position')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('positionDesign.unit')}</Th>
                                    <Th>{tt('fields.occupation')}</Th>
                                    <Th>{tt('positionDesign.profile')}</Th>
                                    <Th>{tt('positionDesign.jobDescription')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {positions.data.map((p) => (
                                    <Tr key={p.id}>
                                        <Td className="font-mono text-xs">{p.job_position_code}</Td>
                                        <Td>
                                            <Link href={route('organization-design.position-design.show', p.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{titleOf(p, locale)}</Link>
                                            {p.grade_level && <p className="text-xs text-[color:var(--app-muted-foreground)]">{p.grade_level}</p>}
                                        </Td>
                                        <Td muted>{nameOf(p.organization, locale)}</Td>
                                        <Td muted>{p.unit ? `${p.unit.code} · ${nameOf(p.unit, locale)}` : '—'}</Td>
                                        <Td muted>{p.occupation ? `${p.occupation.isco_code ?? p.occupation.code} · ${nameOf(p.occupation, locale)}` : '—'}</Td>
                                        <Td>{p.profile_status ? <StatusPill status={p.profile_status} /> : <span className="text-xs text-[color:var(--app-muted-foreground)]">{tt('positionDesign.none')}</span>}</Td>
                                        <Td>{p.job_description_status ? <StatusPill status={p.job_description_status} /> : <span className="text-xs text-[color:var(--app-muted-foreground)]">{tt('positionDesign.none')}</span>}</Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: tt('positionDesign.design'), href: route('organization-design.position-design.show', p.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={positions} />
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
