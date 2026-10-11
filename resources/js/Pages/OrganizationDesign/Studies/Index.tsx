import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StudyForm, { emptyStudy, type Option } from '@/Components/organizationDesign/StudyForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export type StudySummary = {
    id: string; reference_number: string; title_en: string; title_am: string | null; study_type: string; status: string;
    organization: Option | null; organization_id: string; prepared_by: string | null; prepared_by_id: number | null;
    started_at: string | null; target_effective_date: string | null; proposals_count: number | null; created_at: string | null;
};

type Props = {
    studies: LaravelPageMeta & { data: StudySummary[] };
    filters: { organization_id?: string; status?: string; study_type?: string; search?: string };
    organizations: Option[];
    statuses: string[];
    studyTypes: string[];
    can: { create: boolean };
};

export default function StudiesIndex({ studies, filters, organizations, statuses, studyTypes, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', study_type: filters.study_type ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('studies.title')} description={tt('studies.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('studies.create')}</Button>} />}>
            <Head title={tt('studies.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.studies.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.studies.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('studies.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ ...draft, organization_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.studyType')}>
                        <Select value={draft.study_type} onChange={(e) => setDraft({ ...draft, study_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {studyTypes.map((s) => <option key={s} value={s}>{tt(`studies.types.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('studies.title')}>
                    {studies.data.length === 0 ? (
                        <EmptyState title={tt('studies.noStudies')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('studies.noStudiesHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.reference')}</Th>
                                    <Th>{tt('fields.titleEn')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.studyType')}</Th>
                                    <Th>{tt('studies.proposals')}</Th>
                                    <Th>{tt('fields.targetEffectiveDate')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {studies.data.map((s) => (
                                    <Tr key={s.id}>
                                        <Td className="font-mono text-xs">{s.reference_number}</Td>
                                        <Td>
                                            <Link href={route('organization-design.studies.show', s.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{titleOf(s, locale)}</Link>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{s.prepared_by ?? '—'}</p>
                                        </Td>
                                        <Td muted>{nameOf(s.organization, locale)}</Td>
                                        <Td muted>{tt(`studies.types.${s.study_type}`)}</Td>
                                        <Td muted>{s.proposals_count ?? 0}</Td>
                                        <Td muted className="whitespace-nowrap">{s.target_effective_date ? <LocalizedDateDisplay value={s.target_effective_date} /> : '—'}</Td>
                                        <Td><StatusPill status={s.status} /></Td>
                                        <Td align="right"><Link href={route('organization-design.studies.show', s.id)} className={actionLinkClassName()}>{t('common.view')}</Link></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={studies} />
                </TableCard>
            </div>

            {can.create && <StudyForm show={creating} onClose={() => setCreating(false)} title={tt('studies.create')} initial={emptyStudy(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))} url={route('organization-design.studies.store')} method="post" organizations={organizations} studyTypes={studyTypes} />}
        </AuthenticatedLayout>
    );
}
