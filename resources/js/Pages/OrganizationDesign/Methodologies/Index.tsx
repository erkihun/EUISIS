import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import MethodologyForm, { type MethodologyRecord, type Strategy } from '@/Components/organizationDesign/MethodologyForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, FilterField, FilterPanel, Input, ListPagination, Select, TableCard, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName, type LaravelPageMeta } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Row = MethodologyRecord & { id: string; version_no: number; lineage_key: string; status: string; created_by: string | null; approved_by: string | null; missing_parameters: string[]; can: { update: boolean; publish: boolean; version: boolean } };
type Props = { methodologies: LaravelPageMeta & { data: Row[] }; filters: { status?: string; calculation_type?: string; search?: string }; strategies: Strategy[]; roundingRules: string[]; statuses: string[]; can: { manage: boolean; approve: boolean } };

export default function MethodologiesIndex({ methodologies, filters, strategies, roundingRules, statuses, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [draft, setDraft] = useState({ status: filters.status ?? '', calculation_type: filters.calculation_type ?? '', search: filters.search ?? '' });
    const [editing, setEditing] = useState<{ record: Row | null; mode: 'create' | 'edit' | 'version' } | null>(null);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    async function publish(row: Row) {
        const { confirmed } = await confirm({ title: tt('methodologies.publish'), description: tt('methodologies.publishConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.methodologies.publish', row.id), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('methodologies.title')} description={tt('methodologies.description')}
            actions={can.manage && <Button variant="primary" onClick={() => setEditing({ record: null, mode: 'create' })}>{tt('methodologies.create')}</Button>} />}>
            <Head title={tt('methodologies.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.methodologies.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.methodologies.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('methodologies.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.calculationType')}>
                        <Select value={draft.calculation_type} onChange={(e) => setDraft({ ...draft, calculation_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {strategies.map((s) => <option key={s.type} value={s.type}>{tt(`methodologies.strategies.${s.type}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={tt('fields.status')}>
                        <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            {statuses.map((s) => <option key={s} value={s}>{tt(`status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard title={tt('methodologies.title')}>
                    {methodologies.data.length === 0 ? (
                        <EmptyState title={tt('methodologies.noMethodologies')} description={hasActiveFilters ? t('common.noMatchingRecords') : tt('methodologies.noMethodologiesHint')} />
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{tt('fields.code')}</Th>
                                    <Th>{t('common.name')}</Th>
                                    <Th>{tt('methodologies.strategy')}</Th>
                                    <Th>{tt('methodologies.parameters')}</Th>
                                    <Th>{tt('methodologies.rounding')}</Th>
                                    <Th>{tt('fields.effectiveFrom')}</Th>
                                    <Th>{tt('fields.status')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </tr>
                            </THead>
                            <TBody>
                                {methodologies.data.map((m) => (
                                    <Tr key={m.id}>
                                        <Td className="font-mono text-xs">{m.code} <span className="text-[color:var(--app-muted-foreground)]">v{m.version_no}</span></Td>
                                        <Td>{nameOf(m, locale)}</Td>
                                        <Td muted>{tt(`methodologies.strategies.${m.calculation_type}`)}</Td>
                                        <Td muted>
                                            <ul className="space-y-0.5 text-xs">{Object.entries(m.parameters).map(([k, v]) => <li key={k}>{tt(`methodologies.parameterNames.${k}`)}: <span className="font-mono">{v}</span></li>)}</ul>
                                            {m.missing_parameters.length > 0 && <span className={badgeClassName('warning')}>{tt('methodologies.missingParameters').replace(':keys', m.missing_parameters.map((k) => k.replace('parameters.', '')).join(', '))}</span>}
                                        </Td>
                                        <Td muted>{m.rounding_rule ? tt(`methodologies.roundingRules.${m.rounding_rule}`) : '—'}</Td>
                                        <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={m.effective_from} /></Td>
                                        <Td><StatusPill status={m.status} /></Td>
                                        <Td align="right">
                                            <span className="flex justify-end gap-3">
                                                {m.can.update && <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ record: m, mode: 'edit' })}>{t('common.edit')}</button>}
                                                {m.can.version && <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ record: m, mode: 'version' })}>{tt('methodologies.newVersion')}</button>}
                                                {m.can.publish && <button type="button" className={actionLinkClassName({ tone: 'success' })} onClick={() => publish(m)}>{tt('methodologies.publish')}</button>}
                                            </span>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <ListPagination meta={methodologies} />
                </TableCard>
            </div>

            {editing && <MethodologyForm record={editing.record} mode={editing.mode} strategies={strategies} roundingRules={roundingRules} onClose={() => setEditing(null)} />}
        </AuthenticatedLayout>
    );
}
