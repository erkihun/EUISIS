import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PlanForm, { emptyPlan, type MethodologyOption, type Option } from '@/Components/organizationDesign/PlanForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, type LaravelPageMeta } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

export type PlanSummary = {
    id: string; reference_number: string; title_en: string; title_am: string | null; status: string; organization: Option | null; organization_id: string;
    methodology_id: string; methodology: (Option & { version_no: number }) | null; planning_period_start: string | null; planning_period_end: string | null;
    prepared_by: string | null; prepared_by_id: number | null; lines_count: number | null; created_at: string | null;
};

type Props = { plans: LaravelPageMeta & { data: PlanSummary[] }; filters: { organization_id?: string; status?: string; search?: string }; organizations: Option[]; methodologies: MethodologyOption[]; statuses: string[]; can: { create: boolean } };

export default function ManpowerPlansIndex({ plans, filters, organizations, methodologies, statuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('plans.title')} description={tt('plans.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('plans.create')}</Button>} />}>
            <Head title={tt('plans.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.manpower-plans.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.manpower-plans.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('plans.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
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

                <TableCard title={tt('plans.title')}>
                    {plans.data.length === 0 ? (
                        <EmptyState title={tt('plans.noPlans')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('plans.noPlansHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.reference')}</Th>
                                    <Th>{tt('fields.titleEn')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('fields.methodology')}</Th>
                                    <Th>{tt('plans.period')}</Th>
                                    <Th>{tt('plans.lines')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {plans.data.map((p) => (
                                    <Tr key={p.id}>
                                        <Td className="font-mono text-xs">{p.reference_number}</Td>
                                        <Td>
                                            <Link href={route('organization-design.manpower-plans.show', p.id)} className="font-medium text-[color:var(--app-foreground)] hover:underline">{titleOf(p, locale)}</Link>
                                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{p.prepared_by ?? '—'}</p>
                                        </Td>
                                        <Td muted>{nameOf(p.organization, locale)}</Td>
                                        <Td muted>{p.methodology ? `${p.methodology.code} v${p.methodology.version_no}` : '—'}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={p.planning_period_start} /> – <LocalizedDateDisplay value={p.planning_period_end} /></Td>
                                        <Td muted>{p.lines_count ?? 0}</Td>
                                        <Td><StatusPill status={p.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu
                                                    items={[
                                                        { label: t('common.view'), href: route('organization-design.manpower-plans.show', p.id) },
                                                    ]}
                                                />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={plans} />
                </TableCard>
            </div>

            {can.create && <PlanForm show={creating} onClose={() => setCreating(false)} title={tt('plans.create')} initial={emptyPlan(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))} url={route('organization-design.manpower-plans.store')} method="post" organizations={organizations} methodologies={methodologies} />}
        </AuthenticatedLayout>
    );
}
