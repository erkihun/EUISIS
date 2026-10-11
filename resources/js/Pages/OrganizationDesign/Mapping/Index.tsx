import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Flag = 'UNMAPPED_EXISTING_RECORD' | 'NEEDS_DATA_REVIEW';
type Suggestion = Named & { process_code: string | null; match: 'code' | 'name' };
type PositionTask = { id: string; code: string; name_en: string; name_am: string | null; service: { id: string; service_no: string; name_en: string; name_am: string | null } | null; position: { id: string; code: string; title_en: string; title_am: string | null } | null; suggestions: Suggestion[]; needs_review: boolean; flag: Flag };
type UnmappedPosition = { id: string; code: string; title_en: string; title_am: string | null; unit: Named | null; flag: Flag };
type UnmappedUnit = Named & { flag: Flag };
type OccupationRow = { id: string; code: string; isco_code: string | null; name_en: string; name_am: string | null; suggestions: (Named & { revision: string })[]; needs_review: boolean; flag: Flag };
type CompetencyRow = Named & { framework_code: string | null; flag: Flag };
type MasterTask = Named & { process_code: string | null };
type Props = {
    organizationId: string | null;
    organizations: Named[];
    unmapped: { position_tasks: PositionTask[]; positions: UnmappedPosition[]; units: UnmappedUnit[] } | null;
    quality: Report | null;
    masterTasks: MasterTask[];
    catalog: { occupations: OccupationRow[]; competencies: CompetencyRow[]; iscoGroups: (Named & { revision: string })[]; competencyTypes: Named[] } | null;
    can: { map: boolean; apply: boolean; catalog: boolean; occupations: boolean; competencies: boolean };
};

export default function MappingIndex({ organizationId, organizations, unmapped, quality, masterTasks, catalog, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [draft, setDraft] = useState({ organization_id: organizationId ?? '' });
    const [choices, setChoices] = useState<Record<string, string>>({});
    const flag = (value: Flag) => <span className={badgeClassName(value === 'NEEDS_DATA_REVIEW' ? 'warning' : 'info')}>{tt(`mapping.flags.${value}`)}</span>;
    const taskLabel = (s: MasterTask | Suggestion) => `${s.process_code ? `${s.process_code} · ` : ''}${s.code} · ${nameOf(s, locale)}`;

    const linkTask = (task: PositionTask, masterId: string | null) => router.put(route('organization-design.position-tasks.link', task.id), { master_task_id: masterId }, { preserveScroll: true });
    const linkOccupation = (row: OccupationRow, groupId: string | null) => router.put(route('organization-design.mapping.occupations.link', row.id), { isco_group_id: groupId }, { preserveScroll: true });
    const linkCompetency = (row: CompetencyRow, typeId: string | null) => router.put(route('organization-design.mapping.competencies.link', row.id), { competency_type_id: typeId }, { preserveScroll: true });
    async function applyAll() {
        const { confirmed } = await confirm({ title: tt('mapping.applySuggestions'), description: tt('mapping.applyConfirm'), variant: 'warning' });
        if (confirmed && organizationId) router.post(route('organization-design.mapping.apply'), { organization_id: organizationId }, { preserveScroll: true });
    }
    const chosen = (row: PositionTask | OccupationRow) => choices[row.id] ?? (row.suggestions.length === 1 ? row.suggestions[0].id : '');

    return (
        <AuthenticatedLayout header={
            <PageHeader title={tt('mapping.title')} description={tt('mapping.description')}
                actions={can.apply && unmapped && unmapped.position_tasks.some((p) => !p.needs_review && p.suggestions.length === 1) && <Button variant="primary" onClick={applyAll}>{tt('mapping.applySuggestions')}</Button>} />
        }>
            <Head title={tt('mapping.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.mapping.index'), draft.organization_id ? { organization_id: draft.organization_id } : {}, { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.mapping.index'))}
                    hasActiveFilters={Boolean(organizationId)}
                >
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draft.organization_id} onChange={(e) => setDraft({ organization_id: e.target.value })}>
                            <option value="">—</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                {!organizationId || !unmapped ? <EmptyState title={tt('mapping.selectOrganization')} description="" /> : (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="space-y-4 lg:col-span-2">
                            <Card>
                                <CardHeader><CardTitle>{tt('mapping.positionTasks')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('mapping.positionTasksHelp')}</p></CardHeader>
                                {unmapped.position_tasks.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('mapping.noPositionTasks')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('mapping.position')}</Th><Th>{tt('mapping.suggestions')}</Th><Th>{tt('mapping.masterTask')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                        <TBody>
                                            {unmapped.position_tasks.map((task) => (
                                                <Tr key={task.id}>
                                                    <Td>
                                                        <p className="font-mono text-xs">{task.code}</p>
                                                        <p className="text-sm">{nameOf(task, locale)}</p>
                                                        <p className="mt-1">{flag(task.flag)}</p>
                                                    </Td>
                                                    <Td muted>{task.position ? `${task.position.code} · ${titleOf(task.position, locale)}` : '—'}{task.service && <p className="text-xs">{task.service.service_no} · {nameOf(task.service, locale)}</p>}</Td>
                                                    <Td muted className="text-xs">
                                                        {task.suggestions.length === 0 ? tt('mapping.noSuggestions') : (
                                                            <ul className="space-y-0.5">{task.suggestions.map((s) => <li key={s.id}>{taskLabel(s)} <span className={badgeClassName(s.match === 'code' ? 'success' : 'neutral')}>{tt(s.match === 'code' ? 'mapping.matchCode' : 'mapping.matchName')}</span></li>)}</ul>
                                                        )}
                                                    </Td>
                                                    <Td>
                                                        {can.map && (
                                                            <Select aria-label={tt('mapping.masterTask')} value={chosen(task)} onChange={(e) => setChoices({ ...choices, [task.id]: e.target.value })}>
                                                                <option value="">—</option>
                                                                {masterTasks.map((m) => <option key={m.id} value={m.id}>{taskLabel(m)}</option>)}
                                                            </Select>
                                                        )}
                                                    </Td>
                                                    <Td align="right">{can.map && <button type="button" className={actionLinkClassName()} disabled={!chosen(task)} onClick={() => linkTask(task, chosen(task) || null)}>{tt('mapping.map')}</button>}</Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </Card>

                            <Card>
                                <CardHeader><CardTitle>{tt('mapping.positions')}</CardTitle></CardHeader>
                                {unmapped.positions.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('mapping.noPositions')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('mapping.position')}</Th><Th>{tt('mapping.unit')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                        <TBody>
                                            {unmapped.positions.map((p) => (
                                                <Tr key={p.id}>
                                                    <Td className="font-mono text-xs">{p.code}</Td>
                                                    <Td>{titleOf(p, locale)} <span className="ml-1">{flag(p.flag)}</span></Td>
                                                    <Td muted>{p.unit ? `${p.unit.code} · ${nameOf(p.unit, locale)}` : '—'}</Td>
                                                    <Td align="right"><Link href={route('organization-design.position-design.show', p.id)} className={actionLinkClassName()}>{tt('mapping.openPosition')}</Link></Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </Card>

                            <Card>
                                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle>{tt('mapping.units')}</CardTitle>
                                    <Link href={route('organization-design.responsibilities.index', { organization_id: organizationId })} className={actionLinkClassName()}>{tt('mapping.openResponsibilities')}</Link>
                                </CardHeader>
                                {unmapped.units.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('mapping.noUnits')}</p> : (
                                    <ul className="divide-y divide-[color:var(--app-border)]">
                                        {unmapped.units.map((u) => <li key={u.id} className="flex items-center justify-between gap-3 px-5 py-2 text-sm"><span><span className="font-mono text-xs">{u.code}</span> · {nameOf(u, locale)}</span>{flag(u.flag)}</li>)}
                                    </ul>
                                )}
                            </Card>

                            {can.catalog && catalog && (
                                <Card>
                                    <CardHeader><CardTitle>{tt('mapping.catalog')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('mapping.catalogHelp')}</p></CardHeader>
                                    <div className="divide-y divide-[color:var(--app-border)]">
                                        <div>
                                            <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{tt('mapping.occupations')}</p>
                                            {catalog.occupations.length === 0 ? <p className="px-5 py-4 text-sm text-[color:var(--app-muted-foreground)]">{tt('mapping.noOccupations')}</p> : (
                                                <Table>
                                                    <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('fields.occupation')}</Th><Th>{tt('mapping.iscoGroup')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                                    <TBody>
                                                        {catalog.occupations.map((o) => (
                                                            <Tr key={o.id}>
                                                                <Td className="font-mono text-xs">{o.isco_code ?? o.code}</Td>
                                                                <Td>{nameOf(o, locale)} <span className="ml-1">{flag(o.flag)}</span></Td>
                                                                <Td>
                                                                    {can.occupations && (
                                                                        <Select aria-label={tt('mapping.iscoGroup')} value={chosen(o)} onChange={(e) => setChoices({ ...choices, [o.id]: e.target.value })}>
                                                                            <option value="">—</option>
                                                                            {catalog.iscoGroups.map((g) => <option key={g.id} value={g.id}>{g.revision} · {g.code} · {nameOf(g, locale)}</option>)}
                                                                        </Select>
                                                                    )}
                                                                </Td>
                                                                <Td align="right">{can.occupations && <button type="button" className={actionLinkClassName()} disabled={!chosen(o)} onClick={() => linkOccupation(o, chosen(o) || null)}>{tt('mapping.map')}</button>}</Td>
                                                            </Tr>
                                                        ))}
                                                    </TBody>
                                                </Table>
                                            )}
                                        </div>
                                        <div>
                                            <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{tt('mapping.competencies')}</p>
                                            {catalog.competencies.length === 0 ? <p className="px-5 py-4 text-sm text-[color:var(--app-muted-foreground)]">{tt('mapping.noCompetencies')}</p> : (
                                                <Table>
                                                    <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('fields.competency')}</Th><Th>{tt('mapping.competencyType')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                                    <TBody>
                                                        {catalog.competencies.map((c) => (
                                                            <Tr key={c.id}>
                                                                <Td className="font-mono text-xs">{c.framework_code ? `${c.framework_code} · ` : ''}{c.code}</Td>
                                                                <Td>{nameOf(c, locale)} <span className="ml-1">{flag(c.flag)}</span></Td>
                                                                <Td>
                                                                    {can.competencies && (
                                                                        <Select aria-label={tt('mapping.competencyType')} value={choices[c.id] ?? ''} onChange={(e) => setChoices({ ...choices, [c.id]: e.target.value })}>
                                                                            <option value="">—</option>
                                                                            {catalog.competencyTypes.map((ct) => <option key={ct.id} value={ct.id}>{ct.code} · {nameOf(ct, locale)}</option>)}
                                                                        </Select>
                                                                    )}
                                                                </Td>
                                                                <Td align="right">{can.competencies && <button type="button" className={actionLinkClassName()} disabled={!choices[c.id]} onClick={() => linkCompetency(c, choices[c.id] || null)}>{tt('mapping.map')}</button>}</Td>
                                                            </Tr>
                                                        ))}
                                                    </TBody>
                                                </Table>
                                            )}
                                        </div>
                                    </div>
                                </Card>
                            )}
                        </div>

                        <div className="space-y-4">
                            {quality && <ValidationReport report={quality} compact />}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
