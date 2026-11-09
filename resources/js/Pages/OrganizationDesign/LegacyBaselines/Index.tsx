import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import BaselineForm, { emptyBaseline } from '@/Components/organizationDesign/BaselineForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, badgeClassName, type LaravelPageMeta } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Organization = { id: string; code: string; name_en: string; name_am: string | null };

export type BaselineSummary = {
    id: string; reference_number: string; version_no: number; lineage_key: string; title_en: string; title_am: string | null; status: string; origin: string;
    effective_date: string | null; validation_status: string; mapping_required_count: number;
    organization: Organization | null; organization_id: string; hierarchy_version_id: string | null; legal_source_id: string | null;
    prepared_by: string | null; prepared_by_id: number | null; created_at: string | null;
};

type Props = {
    baselines: LaravelPageMeta & { data: BaselineSummary[] };
    filters: { organization_id?: string; status?: string; search?: string };
    organizations: Organization[];
    statuses: string[];
    can: { create: boolean };
};

const VALIDATION_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger'> = { not_run: 'neutral', passed: 'success', warnings: 'warning', failed: 'danger' };

export default function LegacyBaselinesIndex({ baselines, filters, organizations, statuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ organization_id: filters.organization_id ?? '', status: filters.status ?? '', search: filters.search ?? '' });
    const [creating, setCreating] = useState(false);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('legacyBaselines.title')} description={tt('legacyBaselines.description')}
            actions={can.create && <Button variant="primary" onClick={() => setCreating(true)}>{tt('legacyBaselines.create')}</Button>} />}>
            <Head title={tt('legacyBaselines.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.legacy-baselines.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.legacy-baselines.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('legacyBaselines.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
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

                <TableCard title={tt('legacyBaselines.title')}>
                    {baselines.data.length === 0 ? (
                        <EmptyState title={tt('legacyBaselines.none')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('legacyBaselines.noneHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.reference')}</Th>
                                    <Th>{tt('legacyBaselines.details')}</Th>
                                    <Th>{tt('fields.organization')}</Th>
                                    <Th>{tt('legacyBaselines.effectiveDate')}</Th>
                                    <Th>{tt('validation.title')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {baselines.data.map((b) => (
                                    <Tr key={b.id}>
                                        <Td className="font-mono text-xs">{b.reference_number} <span className="text-(--app-muted-foreground)">v{b.version_no}</span></Td>
                                        <Td>
                                            <Link href={route('organization-design.legacy-baselines.show', b.id)} className="font-medium text-(--app-foreground) hover:underline">{titleOf(b, locale)}</Link>
                                            <p className="text-xs text-(--app-muted-foreground)">{tt(`legacyBaselines.origin.${b.origin}`)}{b.prepared_by ? ` · ${b.prepared_by}` : ''}</p>
                                        </Td>
                                        <Td muted>{nameOf(b.organization, locale)}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={b.effective_date} /></Td>
                                        <Td><span className={badgeClassName(VALIDATION_TONES[b.validation_status] ?? 'neutral')}>{tt(`legacyBaselines.validationStatus.${b.validation_status}`)}</span></Td>
                                        <Td><StatusPill status={b.status} /></Td>
                                        <Td align="right">
                                            <div className="flex justify-end">
                                                <AppActionMenu items={[{ label: t('common.view'), href: route('organization-design.legacy-baselines.show', b.id) }]} />
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={baselines} />
                </TableCard>
            </div>

            {can.create && (
                <BaselineForm
                    show={creating}
                    onClose={() => setCreating(false)}
                    title={tt('legacyBaselines.create')}
                    initial={emptyBaseline(filters.organization_id ?? (organizations.length === 1 ? organizations[0].id : ''))}
                    url={route('organization-design.legacy-baselines.store')}
                    method="post"
                    organizations={organizations}
                    origins={['manual', 'imported', 'mixed']}
                />
            )}
        </AuthenticatedLayout>
    );
}
