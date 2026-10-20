import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import DesignLookup from '@/Components/organizationDesign/DesignLookup';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Flag = 'UNMAPPED_EXISTING_RECORD' | 'NEEDS_DATA_REVIEW';
type Suggestion = Named & { process_code: string | null; match: 'code' | 'name' };
type PositionTask = { id: string; code: string; name_en: string; name_am: string | null; service: { id: string; service_no: string; name_en: string; name_am: string | null } | null; position: { id: string; code: string; title_en: string; title_am: string | null } | null; suggestions: Suggestion[]; needs_review: boolean; flag: Flag };
type UnmappedPosition = { id: string; code: string; title_en: string; title_am: string | null; unit: Named | null; flag: Flag };
type UnmappedUnit = Named & { flag: Flag };
type OccupationRow = { id: string; code: string; isco_code: string | null; name_en: string; name_am: string | null; suggestions: (Named & { revision: string })[]; needs_review: boolean; flag: Flag };
type CompetencyRow = Named & { framework_code: string | null; flag: Flag };
type UnmappedService = { id: string; service_no: string; name_en: string; name_am: string | null; tasks: number; tasks_mapped: number; position: { id: string; code: string; title_en: string; title_am: string | null } | null; flag: Flag };
type LegacyStatus = 'FULLY_MAPPED' | 'PARTIALLY_MAPPED' | 'UNMAPPED_LEGACY' | 'INVALID_REFERENCE';
type Props = {
    organizationId: string | null;
    organizations: Named[];
    unmapped: { position_tasks: PositionTask[]; positions: UnmappedPosition[]; units: UnmappedUnit[]; position_services: UnmappedService[] } | null;
    quality: Report | null;
    legacy: { summary: Record<'units' | 'positions' | 'position_services', Record<LegacyStatus, number>> } | null;
    catalog: { occupations: OccupationRow[]; competencies: CompetencyRow[]; iscoGroups: (Named & { revision: string })[]; competencyTypes: Named[] } | null;
    can: { map: boolean; apply: boolean; catalog: boolean; occupations: boolean; competencies: boolean };
};

const LEGACY_TONES: Record<LegacyStatus, 'success' | 'info' | 'neutral' | 'danger'> = { FULLY_MAPPED: 'success', PARTIALLY_MAPPED: 'info', UNMAPPED_LEGACY: 'neutral', INVALID_REFERENCE: 'danger' };

export default function MappingIndex({ organizationId, organizations, unmapped, quality, legacy, catalog, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [draft, setDraft] = useState({ organization_id: organizationId ?? '' });
    const [choices, setChoices] = useState<Record<string, string>>({});
    const [labels, setLabels] = useState<Record<string, string>>({});
    const flag = (value: Flag) => <span className={badgeClassName(value === 'NEEDS_DATA_REVIEW' ? 'warning' : 'info')}>{tt(`mapping.flags.${value}`)}</span>;
    const taskLabel = (s: Suggestion) => `${s.process_code ? `${s.process_code} · ` : ''}${s.code} · ${nameOf(s, locale)}`;

    const linkTask = (task: PositionTask, masterId: string | null) => router.put(route('organization-design.position-tasks.link', task.id), { master_task_id: masterId }, { preserveScroll: true });
    const linkService = (service: UnmappedService, masterId: string | null) => router.put(route('organization-design.mapping.position-services.link', service.id), { master_service_id: masterId }, { preserveScroll: true });
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
                                <CardHeader><div><CardTitle>{tt('mapping.positionTasks')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('mapping.positionTasksHelp')}</p></div></CardHeader>
                                {unmapped.position_tasks.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('mapping.noPositionTasks')}</p> : (
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
                                                            <DesignLookup id={`map-task-${task.id}`} kind="tasks" organizationId={organizationId} value={chosen(task)} display={labels[chosen(task)] ?? task.suggestions.find((s) => s.id === chosen(task))?.code ?? ''}
                                                                onChange={(masterId, hit) => { setChoices({ ...choices, [task.id]: masterId }); if (hit) setLabels({ ...labels, [masterId]: `${hit.process_code ? `${hit.process_code} · ` : ''}${hit.code} · ${(locale === 'am' && hit.name_am) || hit.name_en}` }); }} />
                                                        )}
                                                    </Td>
                                                    <Td align="right">
                                                        <div className="flex justify-end">
                                                            <AppActionMenu
                                                                items={[
                                                                    { label: tt('mapping.map'), onClick: () => linkTask(task, chosen(task) || null), disabled: !chosen(task), show: can.map },
                                                                ]}
                                                            />
                                                        </div>
                                                    </Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </Card>

                            <Card>
                                <CardHeader><CardTitle>{tt('mapping.positionServices')}</CardTitle></CardHeader>
                                {unmapped.position_services.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('mapping.noPositionServices')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('mapping.position')}</Th><Th>{tt('mapping.masterService')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                        <TBody>
                                            {unmapped.position_services.map((s) => (
                                                <Tr key={s.id}>
                                                    <Td>
                                                        <p className="font-mono text-xs">{s.service_no}</p>
                                                        <p className="text-sm">{nameOf(s, locale)}</p>
                                                        <p className="mt-1 flex flex-wrap items-center gap-2">{flag(s.flag)}<span className="text-xs text-(--app-muted-foreground)">{tt('mapping.tasksMapped').replace(':mapped', String(s.tasks_mapped)).replace(':total', String(s.tasks))}</span></p>
                                                    </Td>
                                                    <Td muted>{s.position ? `${s.position.code} · ${titleOf(s.position, locale)}` : '—'}</Td>
                                                    <Td>
                                                        {can.map && (
                                                            <DesignLookup id={`map-service-${s.id}`} kind="services" organizationId={organizationId} value={choices[s.id] ?? ''} display={labels[choices[s.id] ?? ''] ?? ''}
                                                                onChange={(masterId, hit) => { setChoices({ ...choices, [s.id]: masterId }); if (hit) setLabels({ ...labels, [masterId]: `${hit.code} · ${(locale === 'am' && hit.name_am) || hit.name_en}` }); }} />
                                                        )}
                                                    </Td>
                                                    <Td align="right">
                                                        <div className="flex justify-end">
                                                            <AppActionMenu
                                                                items={[
                                                                    { label: tt('mapping.map'), onClick: () => linkService(s, choices[s.id] || null), disabled: !choices[s.id], show: can.map },
                                                                ]}
                                                            />
                                                        </div>
                                                    </Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </Card>

                            <Card>
                                <CardHeader><CardTitle>{tt('mapping.positions')}</CardTitle></CardHeader>
                                {unmapped.positions.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('mapping.noPositions')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.code')}</Th><Th>{tt('mapping.position')}</Th><Th>{tt('mapping.unit')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                        <TBody>
                                            {unmapped.positions.map((p) => (
                                                <Tr key={p.id}>
                                                    <Td className="font-mono text-xs">{p.code}</Td>
                                                    <Td>{titleOf(p, locale)} <span className="ml-1">{flag(p.flag)}</span></Td>
                                                    <Td muted>{p.unit ? `${p.unit.code} · ${nameOf(p.unit, locale)}` : '—'}</Td>
                                                    <Td align="right">
                                                        <div className="flex justify-end">
                                                            <AppActionMenu
                                                                items={[
                                                                    { label: tt('mapping.openPosition'), href: route('organization-design.position-design.show', p.id) },
                                                                ]}
                                                            />
                                                        </div>
                                                    </Td>
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
                                {unmapped.units.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('mapping.noUnits')}</p> : (
                                    <ul className="divide-y divide-(--app-border)">
                                        {unmapped.units.map((u) => <li key={u.id} className="flex items-center justify-between gap-3 px-5 py-2 text-sm"><span><span className="font-mono text-xs">{u.code}</span> · {nameOf(u, locale)}</span>{flag(u.flag)}</li>)}
                                    </ul>
                                )}
                            </Card>

                            {can.catalog && catalog && (
                                <Card>
                                    <CardHeader><div><CardTitle>{tt('mapping.catalog')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('mapping.catalogHelp')}</p></div></CardHeader>
                                    <div className="divide-y divide-(--app-border)">
                                        <div>
                                            <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{tt('mapping.occupations')}</p>
                                            {catalog.occupations.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('mapping.noOccupations')}</p> : (
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
                                                                <Td align="right">
                                                                    <div className="flex justify-end">
                                                                        <AppActionMenu
                                                                            items={[
                                                                                { label: tt('mapping.map'), onClick: () => linkOccupation(o, chosen(o) || null), disabled: !chosen(o), show: can.occupations },
                                                                            ]}
                                                                        />
                                                                    </div>
                                                                </Td>
                                                            </Tr>
                                                        ))}
                                                    </TBody>
                                                </Table>
                                            )}
                                        </div>
                                        <div>
                                            <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{tt('mapping.competencies')}</p>
                                            {catalog.competencies.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('mapping.noCompetencies')}</p> : (
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
                                                                <Td align="right">
                                                                    <div className="flex justify-end">
                                                                        <AppActionMenu
                                                                            items={[
                                                                                { label: tt('mapping.map'), onClick: () => linkCompetency(c, choices[c.id] || null), disabled: !choices[c.id], show: can.competencies },
                                                                            ]}
                                                                        />
                                                                    </div>
                                                                </Td>
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
                            {legacy && (
                                <Card>
                                    <CardHeader><div><CardTitle>{tt('mapping.legacy')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('mapping.legacyHelp')}</p></div></CardHeader>
                                    <div className="divide-y divide-(--app-border)">
                                        {(['units', 'positions', 'position_services'] as const).map((kind) => (
                                            <div key={kind} className="px-5 py-3">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{tt(`mapping.legacyKinds.${kind}`)}</p>
                                                <div className="mt-1 flex flex-wrap gap-2">
                                                    {(['FULLY_MAPPED', 'PARTIALLY_MAPPED', 'UNMAPPED_LEGACY', 'INVALID_REFERENCE'] as const).map((status) => (
                                                        <span key={status} className={badgeClassName(LEGACY_TONES[status])}>{tt(`mapping.legacyStatuses.${status}`)} {legacy.summary[kind][status]}</span>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </Card>
                            )}
                            {quality && <ValidationReport report={quality} compact />}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
