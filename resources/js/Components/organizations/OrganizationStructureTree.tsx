import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { localizedName } from '@/utils/localizedName';
import { useMemo, useState } from 'react';

type Employee = { id: string; employee_number: string; full_name: string; name_en: string | null; status: string; has_active_id_card: boolean };
type Position = { id: string; code: string; old_code: string | null; name_en: string; name_am: string | null; bpr_name: string | null; grade_level: string | null; status: string; occupancy: 'occupied' | 'vacant'; assignment: { effective_from: string | null; employee: Employee } | null };
type Unit = { id: string; code: string; name_en: string; name_am: string | null; unit_type: { code: string | null; name_en: string | null; name_am: string | null }; parent_unit_id: string | null; status: string; child_units_count: number; positions_count: number; employees_count: number; positions: Position[]; children: Unit[] };
export type OrganizationStructureTreeData = { organization: { id: string; code: string; name_en: string; name_am: string | null; type: { name_en: string; name_am: string | null } | null; status: string }; units: Unit[]; direct_positions: Position[]; counters: { units: number; positions: number; occupied_positions: number; vacant_positions: number; employees: number } };

function matchesPosition(position: Position, query: string): boolean {
    const employee = position.assignment?.employee;
    return [position.code, position.old_code, position.name_en, position.name_am, position.bpr_name, employee?.employee_number, employee?.full_name, employee?.name_en]
        .some((value) => value?.toLocaleLowerCase().includes(query));
}

export default function OrganizationStructureTree({ tree, t, locale }: { tree: OrganizationStructureTreeData; t: (key: string) => string; locale: string }) {
    const allIds = useMemo(() => {
        const ids: string[] = ['organization'];
        const visit = (units: Unit[]) => units.forEach((unit) => { ids.push(`unit-${unit.id}`); unit.positions.forEach((position) => ids.push(`position-${position.id}`)); visit(unit.children); });
        visit(tree.units);
        tree.direct_positions.forEach((position) => ids.push(`position-${position.id}`));
        return ids;
    }, [tree]);
    const [expanded, setExpanded] = useState<Set<string>>(new Set(['organization']));
    const [search, setSearch] = useState('');
    const query = search.trim().toLocaleLowerCase();
    const toggle = (id: string) => setExpanded((current) => { const next = new Set(current); next.has(id) ? next.delete(id) : next.add(id); return next; });
    const isOpen = (id: string) => query !== '' || expanded.has(id);

    const PositionNode = ({ position }: { position: Position }) => {
        const id = `position-${position.id}`;
        if (query && !matchesPosition(position, query)) return null;
        return <div className="ml-6 border-l border-gray-200 pl-4 dark:border-slate-700">
            <button type="button" onClick={() => toggle(id)} className="flex w-full items-center gap-2 py-2 text-left">
                <span>{isOpen(id) ? '▾' : '▸'}</span><span className="font-mono text-xs">{position.code}</span>
                <span className="font-medium">{localizedName(position.name_en, position.name_am, locale)}</span>
                <StatusBadge status={position.occupancy} label={t(`organizations.${position.occupancy}`)} />
            </button>
            {isOpen(id) && <div className="mb-2 ml-6 rounded-lg bg-gray-50 p-3 text-xs dark:bg-slate-950">
                <div className="flex flex-wrap gap-x-5 gap-y-1 text-gray-600 dark:text-slate-300">
                    {position.old_code && <span>{t('positions.oldCode')}: {position.old_code}</span>}
                    {position.bpr_name && <span>{t('positions.bprName')}: {position.bpr_name}</span>}
                    <span>{t('positions.gradeLevel')}: {position.grade_level ?? '—'}</span>
                </div>
                {position.assignment ? <div className="mt-3 border-t border-gray-200 pt-3 dark:border-slate-700">
                    <div className="flex items-center gap-2 font-semibold">{position.assignment.employee.employee_number} · {localizedName(position.assignment.employee.full_name, position.assignment.employee.name_en, locale)} <StatusBadge status={position.assignment.employee.status} /></div>
                    <div className="mt-1 flex flex-wrap gap-4"><span>{t('organizations.activeAssignment')}</span><span>{t('organizations.assignmentStartDate')}: <LocalizedDateDisplay value={position.assignment.effective_from} /></span><span>{t('organizations.activeIdCard')}: {position.assignment.employee.has_active_id_card ? t('common.yes') : t('common.no')}</span></div>
                </div> : <div className="mt-3 text-gray-500">{t('organizations.noAssignedEmployees')}</div>}
            </div>}
        </div>;
    };

    const UnitNode = ({ unit, parentName }: { unit: Unit; parentName: string }) => {
        const id = `unit-${unit.id}`;
        const childMatch = !query || [unit.code, unit.name_en, unit.name_am].some((value) => value?.toLocaleLowerCase().includes(query)) || unit.positions.some((position) => matchesPosition(position, query)) || unit.children.some((child) => JSON.stringify(child).toLocaleLowerCase().includes(query));
        if (!childMatch) return null;
        return <div className="ml-5 border-l border-gray-200 pl-4 dark:border-slate-700">
            <button type="button" onClick={() => toggle(id)} className="flex w-full flex-wrap items-center gap-2 py-2 text-left">
                <span>{isOpen(id) ? '▾' : '▸'}</span><span className="font-mono text-xs">{unit.code}</span><span className="font-semibold">{localizedName(unit.name_en, unit.name_am, locale)}</span><StatusBadge status={unit.status} />
                <span className="text-xs text-gray-500">{unit.positions_count} {t('organizations.positions')}</span><span className="text-xs text-gray-500">{unit.employees_count} {t('organizations.assignedEmployees')}</span>
            </button>
            {isOpen(id) && <div><div className="ml-6 pb-2 text-xs text-gray-500">{localizedName(unit.unit_type.name_en, unit.unit_type.name_am, locale)} · {t('organizations.parentUnit')}: {parentName}</div>{unit.positions.length === 0 && <p className="ml-6 py-2 text-xs text-gray-500">{t('organizations.noPositionsFound')}</p>}{unit.positions.map((position) => <PositionNode key={position.id} position={position} />)}{unit.children.map((child) => <UnitNode key={child.id} unit={child} parentName={localizedName(unit.name_en, unit.name_am, locale)} />)}</div>}
        </div>;
    };

    const organizationName = localizedName(tree.organization.name_en, tree.organization.name_am, locale);
    return <section className="mt-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-semibold">{t('organizations.fullOrganizationStructure')}</h2><p className="text-sm text-gray-500">{t('organizations.organizationTree')}</p></div><div className="flex gap-2"><button type="button" className="rounded-lg border px-3 py-1.5 text-sm" onClick={() => setExpanded(new Set(allIds))}>{t('organizations.expandAll')}</button><button type="button" className="rounded-lg border px-3 py-1.5 text-sm" onClick={() => setExpanded(new Set())}>{t('organizations.collapseAll')}</button></div></div>
        <input className="mt-4 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-slate-700" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('organizations.searchStructure')} />
        <div className="mt-4 rounded-xl border border-gray-100 p-3 dark:border-slate-800"><button type="button" onClick={() => toggle('organization')} className="flex w-full flex-wrap items-center gap-2 text-left"><span>{isOpen('organization') ? '▾' : '▸'}</span><span className="font-mono text-xs">{tree.organization.code}</span><span className="font-bold">{organizationName}</span><StatusBadge status={tree.organization.status} /><span className="text-xs text-gray-500">{tree.counters.units} {t('organizations.organizationUnits')}</span><span className="text-xs text-gray-500">{tree.counters.positions} {t('organizations.positions')}</span><span className="text-xs text-gray-500">{tree.counters.occupied_positions} {t('organizations.occupied')}</span><span className="text-xs text-gray-500">{tree.counters.vacant_positions} {t('organizations.vacant')}</span><span className="text-xs text-gray-500">{tree.counters.employees} {t('organizations.assignedEmployees')}</span></button>
            {isOpen('organization') && <div className="mt-3">{tree.units.length === 0 && <p className="ml-6 py-2 text-sm text-gray-500">{t('organizations.noOrganizationUnitsFound')}</p>}{tree.direct_positions.length > 0 && <div className="ml-5"><p className="py-2 text-sm font-semibold">{t('organizations.directOrganizationPositions')}</p>{tree.direct_positions.map((position) => <PositionNode key={position.id} position={position} />)}</div>}{tree.units.map((unit) => <UnitNode key={unit.id} unit={unit} parentName={organizationName} />)}</div>}
        </div>
    </section>;
}
