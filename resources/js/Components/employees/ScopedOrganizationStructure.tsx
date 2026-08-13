import { useMemo, useState } from 'react';
import { Building2, ChevronDown, ChevronRight, SearchIcon, Users } from '@/Components/Icons';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

export type ScopedPosition = {
    id: string;
    code: string | null;
    standard_name: string;
    standard_name_am: string | null;
    organization_unit_id: string;
    status: string;
    occupancy_status: 'vacant' | 'occupied';
};

export type ScopedOrganizationUnit = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    parent_unit_id: string | null;
    status: string;
    positions: ScopedPosition[];
    children: ScopedOrganizationUnit[];
};

export type ScopedOrganization = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    units: ScopedOrganizationUnit[];
};

function unitMatches(unit: ScopedOrganizationUnit, query: string): boolean {
    return [unit.code, unit.name_en, unit.name_am ?? ''].some((value) => value.toLowerCase().includes(query));
}

function positionMatches(position: ScopedPosition, query: string): boolean {
    return [position.code ?? '', position.standard_name, position.standard_name_am ?? '']
        .some((value) => value.toLowerCase().includes(query));
}

function filterUnit(unit: ScopedOrganizationUnit, query: string): ScopedOrganizationUnit | null {
    if (unitMatches(unit, query)) return unit;

    const positions = unit.positions.filter((position) => positionMatches(position, query));
    const children = unit.children
        .map((child) => filterUnit(child, query))
        .filter((child): child is ScopedOrganizationUnit => child !== null);

    return positions.length > 0 || children.length > 0 ? { ...unit, positions, children } : null;
}

function filterStructure(organizations: ScopedOrganization[], query: string): ScopedOrganization[] {
    if (!query) return organizations;

    return organizations
        .map((organization) => {
            const organizationMatches = [organization.code, organization.name_en, organization.name_am ?? '']
                .some((value) => value.toLowerCase().includes(query));
            if (organizationMatches) return organization;

            const units = organization.units
                .map((unit) => filterUnit(unit, query))
                .filter((unit): unit is ScopedOrganizationUnit => unit !== null);

            return units.length > 0 ? { ...organization, units } : null;
        })
        .filter((organization): organization is ScopedOrganization => organization !== null);
}

function collectExpandedIds(organizations: ScopedOrganization[]): Set<string> {
    const ids = new Set<string>();
    const visitUnit = (unit: ScopedOrganizationUnit) => {
        ids.add(`unit:${unit.id}`);
        unit.children.forEach(visitUnit);
    };

    organizations.forEach((organization) => {
        ids.add(`organization:${organization.id}`);
        organization.units.forEach(visitUnit);
    });

    return ids;
}

function UnitNode({
    unit,
    organizationId,
    depth,
    expandedIds,
    selectedPositionId,
    onToggle,
    onSelectPosition,
}: {
    unit: ScopedOrganizationUnit;
    organizationId: string;
    depth: number;
    expandedIds: Set<string>;
    selectedPositionId: string | null;
    onToggle: (id: string) => void;
    onSelectPosition: (organizationId: string, positionId: string) => void;
}) {
    const { t, locale } = useLocale();
    const expansionId = `unit:${unit.id}`;
    const isExpanded = expandedIds.has(expansionId);

    return (
        <div role="treeitem" aria-expanded={isExpanded}>
            <div
                className="flex items-center gap-2 rounded-lg py-1.5 pr-2 text-sm text-gray-800 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/50"
                style={{ paddingLeft: `${12 + depth * 16}px` }}
            >
                <button
                    type="button"
                    onClick={() => onToggle(expansionId)}
                    className="flex h-5 w-5 items-center justify-center text-gray-400"
                    aria-label={isExpanded ? t('organizationUnits.collapseOrganization') : t('organizationUnits.expandOrganization')}
                >
                    {isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                </button>
                <span className="min-w-0 flex-1 truncate font-medium">{localizedName(unit.name_en, unit.name_am, locale)}</span>
                <span className="font-mono text-[10px] text-gray-400">{unit.code}</span>
                <StatusBadge status={unit.status} label={t(`common.${unit.status}`)} className="px-1.5 py-0 text-[10px]" />
            </div>

            {isExpanded && (
                <div role="group">
                    <p
                        className="py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400"
                        style={{ paddingLeft: `${48 + depth * 16}px` }}
                    >
                        {t('organizationUnits.positions')}
                    </p>
                    {unit.positions.length === 0 ? (
                        <p className="py-1 text-xs text-gray-400" style={{ paddingLeft: `${48 + depth * 16}px` }}>
                            {t('organizations.noPositionsFound')}
                        </p>
                    ) : unit.positions.map((position) => (
                        <button
                            key={position.id}
                            type="button"
                            onClick={() => onSelectPosition(organizationId, position.id)}
                            className={`flex w-full items-center gap-2 rounded-lg py-1.5 pr-2 text-left text-xs ${selectedPositionId === position.id ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200' : 'text-gray-600 hover:bg-gray-50 dark:text-slate-400 dark:hover:bg-slate-800'}`}
                            style={{ paddingLeft: `${48 + depth * 16}px` }}
                        >
                            <Users className="h-3.5 w-3.5 flex-none" />
                            <span className="min-w-0 flex-1 truncate">{localizedName(position.standard_name, position.standard_name_am, locale)}</span>
                            {position.code && <span className="font-mono text-[10px] text-gray-400">{position.code}</span>}
                            <StatusBadge status={position.status} label={t(`common.${position.status}`)} className="px-1.5 py-0 text-[10px]" />
                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-medium ${position.occupancy_status === 'occupied' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'}`}>
                                {t(`organizations.${position.occupancy_status}`)}
                            </span>
                        </button>
                    ))}
                    {unit.children.map((child) => (
                        <UnitNode
                            key={child.id}
                            unit={child}
                            organizationId={organizationId}
                            depth={depth + 1}
                            expandedIds={expandedIds}
                            selectedPositionId={selectedPositionId}
                            onToggle={onToggle}
                            onSelectPosition={onSelectPosition}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function ScopedOrganizationStructure({
    organizations,
    isScoped,
    selectedOrganizationId,
    selectedPositionId,
    onSelectOrganization,
    onSelectPosition,
    onClearPosition,
}: {
    organizations: ScopedOrganization[];
    isScoped: boolean;
    selectedOrganizationId: string | null;
    selectedPositionId: string | null;
    onSelectOrganization: (organizationId: string) => void;
    onSelectPosition: (organizationId: string, positionId: string) => void;
    onClearPosition: () => void;
}) {
    const { t, locale } = useLocale();
    const [search, setSearch] = useState('');
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => collectExpandedIds(organizations));
    const filteredOrganizations = useMemo(
        () => filterStructure(organizations, search.trim().toLowerCase()),
        [organizations, search],
    );
    const effectiveExpandedIds = search.trim() ? collectExpandedIds(filteredOrganizations) : expandedIds;

    function toggle(id: string) {
        setExpandedIds((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    return (
        <div className="flex h-full flex-col rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {isScoped ? t('organizationUnits.yourOrganizationStructure') : t('organizations.organizationStructure')}
                </h2>
                {selectedPositionId && (
                    <button type="button" onClick={onClearPosition} className="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400">
                        {t('common.clear')}
                    </button>
                )}
            </div>
            <div className="px-3 py-2">
                <div className="relative">
                    <SearchIcon className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400" />
                    <input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('organizations.searchStructure')}
                        className="w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-8 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                </div>
            </div>
            <div className="flex-1 space-y-3 overflow-y-auto p-2" role="tree">
                {filteredOrganizations.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-10 text-center">
                        <Building2 className="h-8 w-8 text-gray-300 dark:text-slate-600" />
                        <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{t('organizationUnits.noOrganizationStructureFound')}</p>
                    </div>
                ) : filteredOrganizations.map((organization) => {
                    const expansionId = `organization:${organization.id}`;
                    const isExpanded = effectiveExpandedIds.has(expansionId);

                    return (
                        <section key={organization.id} role="treeitem" aria-expanded={isExpanded}>
                            <div className={`flex items-center gap-2 rounded-lg px-2 py-2 ${selectedOrganizationId === organization.id ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200' : 'hover:bg-gray-50 dark:hover:bg-slate-800'}`}>
                                <button type="button" onClick={() => toggle(expansionId)} className="text-gray-400">
                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </button>
                                <button type="button" onClick={() => onSelectOrganization(organization.id)} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                                    <Building2 className="h-4 w-4 flex-none" />
                                    <span className="truncate text-sm font-semibold">{localizedName(organization.name_en, organization.name_am, locale)}</span>
                                </button>
                                <span className="font-mono text-[10px] text-gray-400">{organization.code}</span>
                                <StatusBadge status={organization.status} label={t(`common.${organization.status}`)} className="px-1.5 py-0 text-[10px]" />
                            </div>
                            {isExpanded && (
                                <div role="group">
                                    <p className="px-10 pt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">{t('organizationUnits.organizationUnits')}</p>
                                    {organization.units.length === 0 ? (
                                        <p className="px-10 py-3 text-xs text-gray-400">{t('organizationUnits.noOrganizationUnitsFound')}</p>
                                    ) : organization.units.map((unit) => (
                                        <UnitNode
                                            key={unit.id}
                                            unit={unit}
                                            organizationId={organization.id}
                                            depth={0}
                                            expandedIds={effectiveExpandedIds}
                                            selectedPositionId={selectedPositionId}
                                            onToggle={toggle}
                                            onSelectPosition={onSelectPosition}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
