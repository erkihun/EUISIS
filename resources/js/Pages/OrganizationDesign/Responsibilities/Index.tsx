import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import CatalogEntryForm from '@/Components/organizationDesign/CatalogEntryForm';
import ResponsibilitiesForm, { ResponsibilitiesSummary, type Named, type ObjectCatalog, type ResponsibilityRow, type ResponsibilityTypeOption } from '@/Components/organizationDesign/ResponsibilitiesForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Alert, Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Select, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type TypeRow = ResponsibilityTypeOption & { description_en: string | null; description_am: string | null; is_active: boolean; sort_order: number };
type MatrixRow = { unit: Named & { parent_unit_id: string | null; unit_type: string | null }; rows: ResponsibilityRow[] };
type Props = {
    organizationId: string | null;
    organizations: Named[];
    matrix: MatrixRow[];
    gaps: { functions: Named[]; services: Named[]; processes: Named[] };
    objects: ObjectCatalog;
    types: TypeRow[];
    can: { map: boolean; manageTypes: boolean };
};

export default function ResponsibilitiesIndex({ organizationId, organizations, matrix, gaps, objects, types, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [editing, setEditing] = useState<MatrixRow | null>(null);
    const [typeEditing, setTypeEditing] = useState<TypeRow | 'new' | null>(null);
    const [draftOrganization, setDraftOrganization] = useState(organizationId ?? '');
    const activeTypes = types.filter((x) => x.is_active);
    const gapCount = gaps.functions.length + gaps.services.length + gaps.processes.length;

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('responsibilities.title')} description={tt('responsibilities.description')} />}>
            <Head title={tt('responsibilities.title')} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.responsibilities.index'), draftOrganization ? { organization_id: draftOrganization } : {}, { preserveState: false })}
                    onReset={() => router.get(route('organization-design.responsibilities.index'))}
                    hasActiveFilters={organizationId !== null && organizations.length > 1}
                >
                    <FilterField label={tt('fields.organization')}>
                        <Select value={draftOrganization} onChange={(e) => setDraftOrganization(e.target.value)}>
                            <option value="">—</option>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                {organizationId === null ? (
                    <Card><EmptyState title={tt('responsibilities.chooseOrganization')} /></Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="space-y-4 lg:col-span-2">
                            {!can.map && <Alert tone="info">{tt('responsibilities.readOnly')}</Alert>}
                            <Card>
                                <CardHeader><CardTitle>{tt('responsibilities.matrix')}</CardTitle></CardHeader>
                                {matrix.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('responsibilities.noUnits')}</p> : (
                                    <ul className="divide-y divide-(--app-border)">
                                        {matrix.map((m) => (
                                            <li key={m.unit.id} className="px-5 py-3">
                                                <div className="flex flex-wrap items-start gap-3">
                                                    <span className="font-mono text-xs text-(--app-muted-foreground)">{m.unit.code}</span>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-medium text-(--app-foreground)">{nameOf(m.unit, locale)}</p>
                                                        <div className="mt-1"><ResponsibilitiesSummary rows={m.rows} /></div>
                                                    </div>
                                                    {can.map && <button type="button" className={actionLinkClassName()} onClick={() => setEditing(m)}>{tt('responsibilities.edit')}</button>}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </div>
                        <div className="space-y-4">
                            <Card>
                                <CardHeader className="flex items-center justify-between gap-3">
                                    <div><CardTitle>{tt('responsibilities.gaps')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('responsibilities.gapsHelp')}</p></div>
                                    <span className={badgeClassName(gapCount === 0 ? 'success' : 'warning')}>{gapCount}</span>
                                </CardHeader>
                                {gapCount === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('responsibilities.noGaps')}</p> : (
                                    <div className="space-y-3 p-5 text-sm">
                                        {(['functions', 'services', 'processes'] as const).filter((g) => gaps[g].length > 0).map((g) => (
                                            <div key={g}>
                                                <p className="text-xs font-medium text-(--app-muted-foreground)">{tt(`responsibilities.groups.${g}`)}</p>
                                                <ul className="mt-1 space-y-0.5">{gaps[g].map((o) => <li key={o.id}><span className="font-mono text-xs text-(--app-muted-foreground)">{o.code}</span> {nameOf(o, locale)}</li>)}</ul>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Card>
                            <Card>
                                <CardHeader className="flex items-center justify-between gap-3">
                                    <div><CardTitle>{tt('responsibilities.types')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('responsibilities.typesHelp')}</p></div>
                                    {can.manageTypes && <Button size="sm" variant="outline" onClick={() => setTypeEditing('new')}>{tt('responsibilities.addType')}</Button>}
                                </CardHeader>
                                <ul className="divide-y divide-(--app-border)">
                                    {types.map((x) => (
                                        <li key={x.id} className="flex flex-wrap items-center gap-2 px-5 py-2 text-sm">
                                            <span className="font-mono text-xs text-(--app-muted-foreground)">{x.code}</span>
                                            <span className="min-w-0 flex-1">{nameOf(x, locale)}</span>
                                            {x.is_exclusive && <span className={badgeClassName('warning')}>{tt('responsibilities.exclusive')}</span>}
                                            {x.covers_object && <span className={badgeClassName('success')}>{tt('responsibilities.covers')}</span>}
                                            <StatusPill status={x.is_active ? 'active' : 'inactive'} />
                                            {can.manageTypes && <button type="button" className={actionLinkClassName()} onClick={() => setTypeEditing(x)}>{t('common.edit')}</button>}
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        </div>
                    </div>
                )}
            </div>

            {editing && <ResponsibilitiesForm subjectType="unit" subjectId={editing.unit.id} title={`${editing.unit.code} · ${nameOf(editing.unit, locale)}`} rows={editing.rows} objects={objects} types={activeTypes} onClose={() => setEditing(null)} />}
            {typeEditing && (
                <CatalogEntryForm
                    title={typeEditing === 'new' ? tt('responsibilities.addType') : tt('responsibilities.editType')}
                    record={typeEditing === 'new' ? null : typeEditing}
                    url={typeEditing === 'new' ? route('organization-design.responsibility-types.store') : route('organization-design.responsibility-types.update', typeEditing.id)}
                    method={typeEditing === 'new' ? 'post' : 'put'}
                    fields={{ description: true, sortOrder: true, flags: [{ key: 'is_exclusive', label: tt('responsibilities.exclusive') }, { key: 'covers_object', label: tt('responsibilities.covers') }] }}
                    onClose={() => setTypeEditing(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}
