import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Relationship = { id: string; relationship_type: string; target_type: string; is_primary: boolean; target: Named | null };
type UnitNode = Named & { parent_unit_id: string | null; status: string; unit_type: { code: string; name_en: string; name_am: string | null } | null; active_positions: number; relationships: Relationship[]; children: UnitNode[] };
type Version = { id: string; version_name: string; status: string; effective_from: string | null; effective_to: string | null; parents: (Named & { relationship_type: string })[]; children: (Named & { relationship_type: string })[] };
type Props = { organizationId: string | null; organizations: Named[]; validation: Report | null; units: UnitNode[]; hierarchyVersions: Version[] };

export default function StructureIndex({ organizationId, organizations, validation, units, hierarchyVersions }: Props) {
    const { locale, tt } = useDesignText();
    const [draftOrganization, setDraftOrganization] = useState(organizationId ?? '');
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const toggle = (id: string) => setCollapsed((prev) => { const next = new Set(prev); if (next.has(id)) next.delete(id); else next.add(id); return next; });

    const renderUnit = (node: UnitNode, depth: number) => {
        const isCollapsed = collapsed.has(node.id);
        return (
            <li key={node.id}>
                <div className="rounded-card border border-(--app-border) p-3" style={{ marginLeft: `${depth * 16}px` }}>
                    <div className="flex flex-wrap items-start gap-2">
                        {node.children.length > 0 && <button type="button" className="mt-0.5 text-xs text-(--app-muted-foreground)" aria-expanded={!isCollapsed} onClick={() => toggle(node.id)}>{isCollapsed ? '▸' : '▾'}</button>}
                        <span className="font-mono text-xs text-(--app-muted-foreground)">{node.code}</span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-(--app-foreground)">{nameOf(node, locale)}</p>
                            <p className="text-xs text-(--app-muted-foreground)">
                                {node.unit_type ? `${nameOf(node.unit_type, locale)} · ` : ''}{tt('structure.activePositions').replace(':count', String(node.active_positions))}
                            </p>
                            {node.relationships.length > 0 && (
                                <ul className="mt-1 flex flex-wrap gap-1">
                                    {node.relationships.map((r) => <li key={r.id} className={badgeClassName(r.is_primary ? 'info' : 'neutral')}>{r.relationship_type.replace(/_/g, ' ')} → {r.target ? `${r.target.code} · ${nameOf(r.target, locale)}` : r.target_type}</li>)}
                                </ul>
                            )}
                        </div>
                        <StatusPill status={node.status} />
                    </div>
                </div>
                {!isCollapsed && node.children.length > 0 && <ul className="mt-2 space-y-2">{node.children.map((c) => renderUnit(c, depth + 1))}</ul>}
            </li>
        );
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('structure.title')} description={tt('structure.description')}
            actions={organizationId && <Link href={route('organizations.organogram', organizationId)} className={actionLinkClassName({ size: 'sm' })}>{tt('structure.openOrganogram')}</Link>} />}>
            <Head title={tt('structure.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.structure.index'), draftOrganization ? { organization_id: draftOrganization } : {}, { preserveState: false })}
                    onReset={() => router.get(route('organization-design.structure.index'))}
                    hasActiveFilters={organizationId !== null && organizations.length > 1}
                >
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draftOrganization} onChange={(e) => setDraftOrganization(e.target.value)}>
                            <option value="">—</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                {organizationId === null || validation === null ? (
                    <Card><EmptyState title={tt('structure.chooseOrganization')} /></Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="space-y-4 lg:col-span-2">
                            <ValidationReport report={validation} />
                            <Card>
                                <CardHeader><CardTitle>{tt('structure.units')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('structure.relationships')}</p></CardHeader>
                                <div className="p-3">
                                    {units.length === 0 ? <p className="px-2 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('structure.noUnits')}</p> : <ul className="space-y-2">{units.map((u) => renderUnit(u, 0))}</ul>}
                                </div>
                            </Card>
                        </div>
                        <div className="space-y-4">
                            <Card>
                                <CardHeader><CardTitle>{tt('structure.versions')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('structure.versionsHelp')}</p></CardHeader>
                                {hierarchyVersions.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('structure.noVersions')}</p> : (
                                    <ul className="divide-y divide-(--app-border)">
                                        {hierarchyVersions.map((v) => (
                                            <li key={v.id} className="px-5 py-3 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <Link href={route('hierarchy-versions.show', v.id)} className="font-medium text-(--app-foreground) hover:underline">{v.version_name}</Link>
                                                    <StatusPill status={v.status} />
                                                </div>
                                                <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={v.effective_from} />{v.effective_to && <> – <LocalizedDateDisplay value={v.effective_to} /></>}</p>
                                                {v.parents.length > 0 && <p className="mt-1 text-xs"><span className="text-(--app-muted-foreground)">{tt('structure.parents')}:</span> {v.parents.map((p) => `${p.code} · ${nameOf(p, locale)}`).join(', ')}</p>}
                                                {v.children.length > 0 && <p className="mt-1 text-xs"><span className="text-(--app-muted-foreground)">{tt('structure.children')}:</span> {v.children.length}</p>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
