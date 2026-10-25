import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { api } from '../api';
import { useAuth } from '../auth';
import FormDialog, { type FieldDef, type FieldValue } from '../components/FormDialog';
import { Building2, ChevronDown, ChevronRight, GitBranchIcon, GitForkIcon, PenIcon, TagsIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { formatDate, localized } from '../lib/format';
import { useList } from '../lib/useList';
import { nameOptions, useLookups, type TypeRef, type UnitRef } from '../lib/useLookups';
import { Link, navigate } from '../router';
import type { OrgRef, Organization, Unit } from '../types';
import { Alert, Button, Card, CardHeader, CardTitle, EmptyState, FilterField, FilterPanel, Input, Table, TBody, THead, Td, Th, Tr, actionLinkClassName } from '../ui';
import { ListFrame, useFilterForm } from './Lists';

/**
 * The organization structure screens: organizations (list, detail, create and
 * edit with the parent in the published hierarchy), units, organization and
 * unit types, and the hierarchy tree.
 */

type OrganizationDetail = Organization & {
    organization_type_id: string;
    units: number;
    positions: number;
    parent: OrgRef | null;
    children: (OrgRef & { status: string })[];
};

function useCanWrite(): boolean {
    return Boolean(useAuth().user?.can_change_master_data);
}

/** The create / edit organization dialog. */
function OrganizationDialog({ open, organization, parentId, onClose, onSaved }: {
    open: boolean;
    organization: OrganizationDetail | null;
    parentId?: string | null;
    onClose: () => void;
    onSaved: (id: string) => void;
}) {
    const { t, locale } = useLocale();
    const { lookups } = useLookups();
    const fields: FieldDef[] = [
        { name: 'organization_type_id', label: t('platform.structure.type'), type: 'select', required: true, options: nameOptions(locale, lookups.organization_types.filter((type) => type.is_active || type.id === organization?.organization_type_id)) },
        { name: 'code', label: t('platform.structure.code'), description: t('platform.structure.codeHint') },
        { name: 'name_en', label: t('platform.structure.nameEn'), required: true },
        { name: 'name_am', label: t('platform.structure.nameAm') },
        { name: 'parent_organization_id', label: t('platform.structure.parent'), type: 'select', emptyOption: t('platform.structure.noParent'), options: nameOptions(locale, lookups.organizations.filter((row) => row.id !== organization?.id)), wide: true },
        ...(organization ? [{ name: 'status', label: t('platform.structure.status'), type: 'select' as const, emptyOption: null, options: lookups.organization_statuses.map((status) => ({ value: status, label: t(`platform.status.${status}`) })) }] : []),
        { name: 'effective_from', label: t('platform.structure.effectiveFrom'), type: 'date' },
    ];
    const initial: Record<string, FieldValue> = organization
        ? { organization_type_id: organization.organization_type_id, code: organization.code, name_en: organization.name_en, name_am: organization.name_am ?? null, parent_organization_id: organization.parent?.id ?? null, status: organization.status, effective_from: organization.effective_from?.slice(0, 10) ?? null }
        : { organization_type_id: null, code: null, name_en: null, name_am: null, parent_organization_id: parentId ?? null, effective_from: null };

    return (
        <FormDialog
            open={open}
            title={organization ? t('platform.structure.editOrganization') : t('platform.structure.newOrganization')}
            fields={fields}
            initial={initial}
            submitLabel={t('platform.actions.save')}
            onClose={onClose}
            onSubmit={async (values) => {
                const response = await api<{ data: { id: string } }>(organization ? `/v1/organizations/${organization.id}` : '/v1/organizations', { method: organization ? 'PUT' : 'POST', body: values });
                toast.success(t('platform.actions.saved'));
                onSaved(response.data.id);
            }}
        />
    );
}

/** The create / edit unit dialog; the parent unit list follows the chosen organization. */
function UnitDialog({ open, unit, organizationId, onClose, onSaved }: {
    open: boolean;
    unit: Unit & { organization_unit_type_id?: string | null; parent_unit_id?: string | null } | null;
    organizationId?: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const { t, locale } = useLocale();
    const [chosen, setChosen] = useState<string | null>(unit?.organization_id ?? organizationId ?? null);
    const { lookups } = useLookups(chosen);
    const fixed = unit !== null || organizationId !== undefined;
    const fields: FieldDef[] = [
        ...(fixed ? [] : [{ name: 'organization_id', label: t('platform.structure.organization'), type: 'select' as const, required: true, options: nameOptions(locale, lookups.organizations), wide: true }]),
        { name: 'organization_unit_type_id', label: t('platform.structure.unitType'), type: 'select', required: true, options: nameOptions(locale, lookups.unit_types.filter((type: TypeRef) => type.is_active || type.id === unit?.organization_unit_type_id)) },
        { name: 'parent_unit_id', label: t('platform.structure.parentUnit'), type: 'select', options: nameOptions(locale, lookups.units.filter((row: UnitRef) => row.id !== unit?.id)) },
        { name: 'code', label: t('platform.structure.code'), description: t('platform.structure.codeHint') },
        { name: 'name_en', label: t('platform.structure.nameEn'), required: true },
        { name: 'name_am', label: t('platform.structure.nameAm') },
        ...(unit ? [{ name: 'status', label: t('platform.structure.status'), type: 'select' as const, emptyOption: null, options: lookups.unit_statuses.map((status) => ({ value: status, label: t(`platform.status.${status}`) })) }] : []),
    ];
    const initial: Record<string, FieldValue> = unit
        ? { organization_unit_type_id: unit.organization_unit_type_id ?? null, parent_unit_id: unit.parent_unit_id ?? null, code: unit.code, name_en: unit.name_en, name_am: unit.name_am ?? null, status: unit.status }
        : { organization_id: organizationId ?? null, organization_unit_type_id: null, parent_unit_id: null, code: null, name_en: null, name_am: null };

    return (
        <FormDialog
            open={open}
            title={unit ? t('platform.structure.editUnit') : t('platform.structure.newUnit')}
            fields={fields}
            initial={initial}
            submitLabel={t('platform.actions.save')}
            onClose={onClose}
            onValuesChange={(values) => { if (!fixed) setChosen((values.organization_id as string | null) ?? null); }}
            onSubmit={async (values) => {
                const body = { ...values, ...(fixed && !unit ? { organization_id: organizationId } : {}) };
                await api(unit ? `/v1/organization-units/${unit.id}` : '/v1/organization-units', { method: unit ? 'PUT' : 'POST', body });
                toast.success(t('platform.actions.saved'));
                onSaved();
            }}
        />
    );
}

export function OrganizationsPage() {
    const { t, locale } = useLocale();
    const canWrite = useCanWrite();
    const list = useList<Organization>('/v1/organizations');
    const { form, setForm, set } = useFilterForm({ search: '' });
    const [creating, setCreating] = useState(false);
    const hasFilters = Boolean(list.filters.search);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.organizations.title')} description={t('platform.organizations.description')}
            actions={canWrite ? <Button variant="primary" icon={<Building2 className="h-4 w-4" />} onClick={() => setCreating(true)}>{t('platform.structure.newOrganization')}</Button> : undefined} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.organizations.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="organization-search" wide>
                        <Input id="organization-search" type="search" placeholder={t('platform.organizations.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={Building2} empty={t('platform.organizations.empty')} noMatch={t('platform.organizations.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('common.name')}</Th>
                                <Th>{t('common.code')}</Th>
                                <Th>{t('platform.organizations.type')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td><Link href={`/organizations/${row.id}`} className="font-medium text-(--app-foreground) hover:text-primary">{localized(locale, row.name_en, row.name_am)}</Link></Td>
                                    <Td muted className="font-mono text-xs">{row.code}</Td>
                                    <Td muted>{row.type ? localized(locale, row.type.name_en, row.type.name_am) : '—'}</Td>
                                    <Td><StatusBadge status={row.status} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
            <OrganizationDialog open={creating} organization={null} onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); navigate(`/organizations/${id}`); }} />
        </AuthenticatedLayout>
    );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}

export function OrganizationDetailPage({ id }: { id: string }) {
    const { t, locale } = useLocale();
    const canWrite = useCanWrite();
    const [organization, setOrganization] = useState<OrganizationDetail | null>(null);
    const [error, setError] = useState(false);
    const [editing, setEditing] = useState(false);
    const [addingChild, setAddingChild] = useState(false);
    const [unitDialog, setUnitDialog] = useState<{ unit: (Unit & { organization_unit_type_id?: string | null; parent_unit_id?: string | null }) | null } | null>(null);
    const units = useList<Unit & { organization_unit_type_id: string | null; parent_unit_id: string | null }>('/v1/organization-units', { organization_id: id });

    const load = useCallback(() => {
        api<{ data: OrganizationDetail }>(`/v1/organizations/${id}`).then((response) => setOrganization(response.data)).catch(() => setError(true));
    }, [id]);
    useEffect(load, [load]);

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={organization ? localized(locale, organization.name_en, organization.name_am) : t('platform.organizations.title')}
                description={organization ? `${organization.code} · ${organization.type ? localized(locale, organization.type.name_en, organization.type.name_am) : ''}` : undefined}
                backHref="/organizations"
                actions={canWrite && organization ? (
                    <>
                        <Button variant="outline" icon={<Building2 className="h-4 w-4" />} onClick={() => setAddingChild(true)}>{t('platform.structure.newOrganization')}</Button>
                        <Button variant="primary" icon={<PenIcon className="h-4 w-4" />} onClick={() => setEditing(true)}>{t('platform.actions.edit')}</Button>
                    </>
                ) : undefined}
            />
        )}>
            {error && <Alert tone="danger">{t('platform.error')}</Alert>}
            {!organization && !error && <Card><EmptyState title={t('common.loading')} /></Card>}
            {organization && (
                <div className="space-y-6">
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="lg:col-span-2">
                            <CardHeader><CardTitle>{t('common.details')}</CardTitle><StatusBadge status={organization.status} /></CardHeader>
                            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <Detail label={t('platform.structure.code')}><span className="font-mono text-xs">{organization.code}</span></Detail>
                                <Detail label={t('platform.structure.type')}>{organization.type ? localized(locale, organization.type.name_en, organization.type.name_am) : '—'}</Detail>
                                <Detail label={t('platform.structure.effectiveFrom')}>{formatDate(organization.effective_from)}</Detail>
                                <Detail label={t('platform.structure.parent')}>
                                    {organization.parent ? <Link href={`/organizations/${organization.parent.id}`} className={actionLinkClassName({ size: 'sm' })}>{localized(locale, organization.parent.name_en, organization.parent.name_am)}</Link> : t('platform.structure.noParent')}
                                </Detail>
                                <Detail label={t('platform.structure.units')}>{organization.units}</Detail>
                                <Detail label={t('platform.structure.positions')}>{organization.positions}</Detail>
                            </dl>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle>{t('platform.structure.children')}</CardTitle></CardHeader>
                            {organization.children.length === 0 ? (
                                <p className="text-sm text-(--app-muted-foreground)">{t('platform.structure.noChildren')}</p>
                            ) : (
                                <ul className="divide-y divide-(--app-border) text-sm">
                                    {organization.children.map((child) => (
                                        <li key={child.id} className="flex items-center justify-between gap-2 py-2">
                                            <Link href={`/organizations/${child.id}`} className="font-medium hover:text-primary">{localized(locale, child.name_en, child.name_am)}</Link>
                                            <StatusBadge status={child.status} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <Card className="overflow-hidden p-0">
                        <div className="flex items-center justify-between gap-2 border-b border-(--app-border) px-5 py-4">
                            <CardTitle>{t('platform.structure.units')}</CardTitle>
                            {canWrite && <Button variant="outline" size="sm" icon={<GitBranchIcon className="h-4 w-4" />} onClick={() => setUnitDialog({ unit: null })}>{t('platform.structure.newUnit')}</Button>}
                        </div>
                        {units.result && units.result.data.length === 0 ? (
                            <EmptyState icon={<GitBranchIcon className="h-8 w-8" />} title={t('platform.units.empty')} />
                        ) : (
                            <Table>
                                <THead><Tr><Th>{t('common.name')}</Th><Th>{t('common.code')}</Th><Th>{t('common.status')}</Th>{canWrite && <Th align="right">{t('common.actions')}</Th>}</Tr></THead>
                                <TBody>
                                    {units.result?.data.map((row) => (
                                        <Tr key={row.id}>
                                            <Td className="font-medium">{localized(locale, row.name_en, row.name_am)}</Td>
                                            <Td muted className="font-mono text-xs">{row.code}</Td>
                                            <Td><StatusBadge status={row.status} /></Td>
                                            {canWrite && <Td align="right"><button type="button" className={actionLinkClassName()} onClick={() => setUnitDialog({ unit: row })}>{t('platform.actions.edit')}</button></Td>}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>
            )}
            <OrganizationDialog open={editing} organization={organization} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); load(); }} />
            <OrganizationDialog open={addingChild} organization={null} parentId={id} onClose={() => setAddingChild(false)} onSaved={(childId) => { setAddingChild(false); navigate(`/organizations/${childId}`); }} />
            <UnitDialog open={unitDialog !== null} unit={unitDialog?.unit ?? null} organizationId={id} onClose={() => setUnitDialog(null)} onSaved={() => { setUnitDialog(null); units.reload(); load(); }} />
        </AuthenticatedLayout>
    );
}

export function UnitsPage() {
    const { t, locale } = useLocale();
    const canWrite = useCanWrite();
    const list = useList<Unit & { organization_unit_type_id: string | null; parent_unit_id: string | null }>('/v1/organization-units');
    const { form, setForm, set } = useFilterForm({ search: '' });
    const [dialog, setDialog] = useState<{ unit: (Unit & { organization_unit_type_id: string | null; parent_unit_id: string | null }) | null } | null>(null);
    const hasFilters = Boolean(list.filters.search);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.units.title')} description={t('platform.units.description')}
            actions={canWrite ? <Button variant="primary" icon={<GitBranchIcon className="h-4 w-4" />} onClick={() => setDialog({ unit: null })}>{t('platform.structure.newUnit')}</Button> : undefined} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.units.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="unit-search" wide>
                        <Input id="unit-search" type="search" placeholder={t('platform.units.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={GitBranchIcon} empty={t('platform.units.empty')} noMatch={t('platform.units.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('common.name')}</Th>
                                <Th>{t('common.code')}</Th>
                                <Th>{t('platform.units.organization')}</Th>
                                <Th>{t('common.status')}</Th>
                                {canWrite && <Th align="right">{t('common.actions')}</Th>}
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium">{localized(locale, row.name_en, row.name_am)}</Td>
                                    <Td muted className="font-mono text-xs">{row.code}</Td>
                                    <Td muted>{row.organization ? <Link href={`/organizations/${row.organization_id}`} className="hover:text-primary">{row.organization.name_en}</Link> : '—'}</Td>
                                    <Td><StatusBadge status={row.status} /></Td>
                                    {canWrite && <Td align="right"><button type="button" className={actionLinkClassName()} onClick={() => setDialog({ unit: row })}>{t('platform.actions.edit')}</button></Td>}
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
            <UnitDialog open={dialog !== null} unit={dialog?.unit ?? null} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); list.reload(); }} />
        </AuthenticatedLayout>
    );
}

type TypeRow = TypeRef & { description_en: string | null; sort_order: number };

/** Organization types or organization unit types: a short list, edited in a dialog. */
export function TypesPage({ kind }: { kind: 'organization-types' | 'unit-types' }) {
    const { t, locale } = useLocale();
    const canWrite = useCanWrite();
    const [rows, setRows] = useState<TypeRow[] | null>(null);
    const [dialog, setDialog] = useState<{ row: TypeRow | null } | null>(null);
    const title = kind === 'organization-types' ? t('nav.organizationTypes') : t('nav.organizationUnitTypes');

    const load = useCallback(() => {
        api<{ data: TypeRow[] }>(`/v1/${kind}`).then((response) => setRows(response.data)).catch(() => setRows([]));
    }, [kind]);
    useEffect(load, [load]);

    const fields: FieldDef[] = [
        { name: 'code', label: t('platform.structure.code'), required: true },
        { name: 'sort_order', label: t('platform.structure.order'), type: 'number' },
        { name: 'name_en', label: t('platform.structure.nameEn'), required: true },
        { name: 'name_am', label: t('platform.structure.nameAm') },
        { name: 'description_en', label: t('platform.structure.description'), type: 'textarea' },
        { name: 'is_active', label: t('platform.structure.inUse'), type: 'checkbox' },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={kind === 'organization-types' ? t('platform.structure.typesDescription') : t('platform.structure.unitTypesDescription')}
            actions={canWrite ? <Button variant="primary" icon={<TagsIcon className="h-4 w-4" />} onClick={() => setDialog({ row: null })}>{t('platform.structure.newType')}</Button> : undefined} />}>
            <Card className="overflow-hidden p-0">
                {rows === null ? <EmptyState title={t('common.loading')} /> : rows.length === 0 ? <EmptyState icon={<TagsIcon className="h-8 w-8" />} title={t('common.noResults')} /> : (
                    <Table>
                        <THead><Tr><Th>{t('common.name')}</Th><Th>{t('common.code')}</Th><Th align="right">{t('platform.structure.order')}</Th><Th>{t('common.status')}</Th>{canWrite && <Th align="right">{t('common.actions')}</Th>}</Tr></THead>
                        <TBody>
                            {rows.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium">{localized(locale, row.name_en, row.name_am)}</Td>
                                    <Td muted className="font-mono text-xs">{row.code}</Td>
                                    <Td numeric>{row.sort_order}</Td>
                                    <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} /></Td>
                                    {canWrite && <Td align="right"><button type="button" className={actionLinkClassName()} onClick={() => setDialog({ row })}>{t('platform.actions.edit')}</button></Td>}
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>
            <FormDialog
                open={dialog !== null}
                title={dialog?.row ? t('platform.structure.editType') : t('platform.structure.newType')}
                fields={fields}
                initial={dialog?.row
                    ? { code: dialog.row.code, sort_order: dialog.row.sort_order, name_en: dialog.row.name_en, name_am: dialog.row.name_am, description_en: dialog.row.description_en, is_active: dialog.row.is_active }
                    : { code: null, sort_order: 0, name_en: null, name_am: null, description_en: null, is_active: true }}
                submitLabel={t('platform.actions.save')}
                onClose={() => setDialog(null)}
                onSubmit={async (values) => {
                    await api(dialog?.row ? `/v1/${kind}/${dialog.row.id}` : `/v1/${kind}`, { method: dialog?.row ? 'PUT' : 'POST', body: { ...values, sort_order: values.sort_order ?? 0 } });
                    toast.success(t('platform.actions.saved'));
                    setDialog(null);
                    load();
                }}
            />
        </AuthenticatedLayout>
    );
}

type HierarchyNode = { id: string; code: string; name_en: string; name_am: string | null; status: string; type: string | null; parent_id: string | null };

/** The published hierarchy as a tree, cut to the viewer's scope. */
export function HierarchyPage() {
    const { t, locale } = useLocale();
    const [data, setData] = useState<{ version: { version_name: string } | null; nodes: HierarchyNode[] } | null>(null);
    const [closed, setClosed] = useState<Record<string, boolean>>({});

    useEffect(() => {
        api<{ version: { version_name: string } | null; nodes: HierarchyNode[] }>('/v1/hierarchy').then(setData).catch(() => setData({ version: null, nodes: [] }));
    }, []);

    const children = useMemo(() => {
        const map = new Map<string | null, HierarchyNode[]>();
        for (const node of data?.nodes ?? []) {
            const key = node.parent_id;
            map.set(key, [...(map.get(key) ?? []), node]);
        }
        for (const list of map.values()) list.sort((a, b) => a.name_en.localeCompare(b.name_en));
        return map;
    }, [data]);

    const renderNode = (node: HierarchyNode, depth: number): React.ReactNode => {
        const kids = children.get(node.id) ?? [];
        const open = !closed[node.id];
        return (
            <li key={node.id}>
                <div className="flex items-center gap-2 rounded-md py-1.5 pe-2 hover:bg-(--app-surface-muted)" style={{ paddingInlineStart: `${depth * 20 + 4}px` }}>
                    {kids.length > 0 ? (
                        <button type="button" aria-expanded={open} aria-label={localized(locale, node.name_en, node.name_am)} onClick={() => setClosed((current) => ({ ...current, [node.id]: open }))} className="flex h-6 w-6 items-center justify-center rounded-sm text-(--app-muted-foreground) hover:text-(--app-foreground)">
                            {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                        </button>
                    ) : <span className="h-6 w-6" aria-hidden="true" />}
                    <Link href={`/organizations/${node.id}`} className="min-w-0 truncate text-sm font-medium text-(--app-foreground) hover:text-primary">{localized(locale, node.name_en, node.name_am)}</Link>
                    <span className="font-mono text-xs text-(--app-muted-foreground)">{node.code}</span>
                    {node.type && <span className="hidden text-xs text-(--app-muted-foreground) sm:inline">· {node.type}</span>}
                    <span className="ms-auto"><StatusBadge status={node.status} /></span>
                </div>
                {kids.length > 0 && open && <ul>{kids.map((kid) => renderNode(kid, depth + 1))}</ul>}
            </li>
        );
    };

    const roots = children.get(null) ?? [];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('nav.hierarchy')} description={t('platform.structure.hierarchyDescription')} />}>
            <Card>
                {data === null ? <EmptyState title={t('common.loading')} /> : roots.length === 0 ? <EmptyState icon={<GitForkIcon className="h-8 w-8" />} title={t('platform.structure.noHierarchy')} /> : (
                    <>
                        {data.version && <p className="mb-3 text-xs font-medium text-(--app-muted-foreground)">{t('platform.structure.version', { name: data.version.version_name })}</p>}
                        <ul>{roots.map((root) => renderNode(root, 0))}</ul>
                    </>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
