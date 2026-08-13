import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';

type Unit = OrganizationStructureTreeData['units'][number];
type Position = OrganizationStructureTreeData['direct_positions'][number];

/**
 * Box-and-line organization chart.
 *
 * Layout is pure CSS: each subtree is a centered column, siblings sit in a row,
 * and connectors are drawn with bordered pseudo-spacers rather than SVG. That
 * keeps the whole chart printable and avoids measuring node positions in JS.
 *
 * All content comes from the scoped payload the server sends — there is no
 * placeholder or sample data anywhere in this component.
 */

/** Vertical stem dropping out of a parent box. */
function Stem() {
    return <div className="mx-auto h-6 w-px bg-gray-300 dark:bg-slate-600" />;
}

/**
 * Horizontal rail spanning a row of children, with a short drop into each.
 * The first and last half-segments are hidden so the rail stops at the outer
 * children instead of overhanging.
 */
function ChildRow({ children }: { children: React.ReactNode[] }) {
    const count = children.length;

    if (count === 0) {
        return null;
    }

    if (count === 1) {
        return (
            <div className="flex flex-col items-center">
                <Stem />
                {children[0]}
            </div>
        );
    }

    return (
        <div className="flex items-start justify-center">
            {children.map((child, index) => (
                <div key={index} className="flex flex-col items-center px-3">
                    <div className="flex h-6 w-full items-start">
                        {/* left half-rail */}
                        <div
                            className={`h-px flex-1 ${index === 0 ? '' : 'bg-gray-300 dark:bg-slate-600'}`}
                        />
                        {/* drop into this child */}
                        <div className="h-6 w-px bg-gray-300 dark:bg-slate-600" />
                        {/* right half-rail */}
                        <div
                            className={`h-px flex-1 ${index === count - 1 ? '' : 'bg-gray-300 dark:bg-slate-600'}`}
                        />
                    </div>
                    {child}
                </div>
            ))}
        </div>
    );
}

function EmployeeBox({ position }: { position: Position }) {
    const { t } = useLocale();
    const employee = position.assignment?.employee;

    if (!employee) {
        return null;
    }

    return (
        <div className="w-52 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-center dark:border-emerald-800 dark:bg-emerald-950/40">
            <p className="truncate font-mono text-[10px] text-emerald-700 dark:text-emerald-400">{employee.employee_number}</p>
            <p className="truncate text-xs font-semibold text-gray-900 dark:text-slate-100">{employee.full_name}</p>
            <p className="truncate text-[10px] text-emerald-700 dark:text-emerald-400">{t(`common.${employee.status}`)}</p>
        </div>
    );
}

function PositionBox({ position }: { position: Position }) {
    const { t, locale } = useLocale();
    const isOccupied = position.occupancy === 'occupied';

    return (
        <div className="flex flex-col items-center">
            <div className="w-52 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-center dark:border-amber-800 dark:bg-amber-950/40">
                {position.code && (
                    <p className="truncate font-mono text-[10px] text-amber-700 dark:text-amber-400">{position.code}</p>
                )}
                <p className="truncate text-xs font-semibold text-gray-900 dark:text-slate-100">
                    {localizedName(position.name_en, position.name_am, locale)}
                </p>
                {position.bpr_name && (
                    <p className="truncate text-[10px] text-gray-500 dark:text-slate-400">{position.bpr_name}</p>
                )}
                <span
                    className={`mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-medium ${
                        isOccupied
                            ? 'bg-amber-200 text-amber-900 dark:bg-amber-900/60 dark:text-amber-200'
                            : 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900/60 dark:text-emerald-200'
                    }`}
                >
                    {t(`common.${position.occupancy}`)}
                </span>
            </div>

            {isOccupied && position.assignment?.employee && (
                <>
                    <Stem />
                    <EmployeeBox position={position} />
                </>
            )}
        </div>
    );
}

function UnitNode({
    unit,
    expandedIds,
    onToggle,
}: {
    unit: Unit;
    expandedIds: Set<string>;
    onToggle: (id: string) => void;
}) {
    const { t, locale } = useLocale();
    const isExpanded = expandedIds.has(unit.id);
    const childCount = unit.children.length + unit.positions.length;

    const children: React.ReactNode[] = isExpanded
        ? [
            ...unit.positions.map((position) => <PositionBox key={position.id} position={position} />),
            ...unit.children.map((child) => (
                <UnitNode key={child.id} unit={child} expandedIds={expandedIds} onToggle={onToggle} />
            )),
        ]
        : [];

    return (
        <div className="flex flex-col items-center">
            <div className="w-56 rounded-lg border border-violet-300 bg-violet-50 px-3 py-2 text-center dark:border-violet-800 dark:bg-violet-950/40">
                {unit.code && (
                    <p className="truncate font-mono text-[10px] text-violet-700 dark:text-violet-400">{unit.code}</p>
                )}
                <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {localizedName(unit.name_en, unit.name_am, locale)}
                </p>
                {unit.unit_type?.name_en && (
                    <p className="truncate text-[10px] text-gray-500 dark:text-slate-400">
                        {localizedName(unit.unit_type.name_en, unit.unit_type.name_am, locale)}
                    </p>
                )}
                {childCount > 0 && (
                    <button
                        type="button"
                        onClick={() => onToggle(unit.id)}
                        className="mt-1 text-[10px] font-medium text-violet-700 hover:underline dark:text-violet-300 print:hidden"
                    >
                        {isExpanded ? `− ${t('organizations.collapseAll')}` : `+ ${childCount}`}
                    </button>
                )}
            </div>

            {isExpanded && children.length > 0 && (
                <>
                    <Stem />
                    <ChildRow>{children}</ChildRow>
                </>
            )}
        </div>
    );
}

/** Every unit id in the tree, so expand-all can seed the expanded set. */
function collectUnitIds(units: Unit[]): string[] {
    return units.flatMap((unit) => [unit.id, ...collectUnitIds(unit.children)]);
}

export default function OrganogramChart({
    tree,
    captureRef,
    toolbarExtra,
}: {
    tree: OrganizationStructureTreeData;
    /** Wraps the chart content only, so exports exclude the toolbar. */
    captureRef?: React.Ref<HTMLDivElement>;
    toolbarExtra?: React.ReactNode;
}) {
    const { t, locale } = useLocale();
    const allUnitIds = useMemo(() => collectUnitIds(tree.units), [tree.units]);

    // Collapsed beyond the first level by default so large structures stay
    // readable; expand-all is one click away.
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => new Set(tree.units.map((unit) => unit.id)));
    const [zoom, setZoom] = useState(1);

    function toggle(id: string) {
        setExpandedIds((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    const hasStructure = tree.units.length > 0 || tree.direct_positions.length > 0;

    const rootChildren: React.ReactNode[] = [
        ...tree.direct_positions.map((position) => <PositionBox key={position.id} position={position} />),
        ...tree.units.map((unit) => (
            <UnitNode key={unit.id} unit={unit} expandedIds={expandedIds} onToggle={toggle} />
        )),
    ];

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <button
                    type="button"
                    onClick={() => setExpandedIds(new Set(allUnitIds))}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('organizations.expandAll')}
                </button>
                <button
                    type="button"
                    onClick={() => setExpandedIds(new Set())}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('organizations.collapseAll')}
                </button>
                <span className="mx-1 h-4 w-px bg-gray-200 dark:bg-slate-700" />
                <button
                    type="button"
                    onClick={() => setZoom((value) => Math.max(0.5, Number((value - 0.1).toFixed(2))))}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('organizations.zoomOut')}
                </button>
                <span className="text-xs tabular-nums text-gray-500 dark:text-slate-400">{Math.round(zoom * 100)}%</span>
                <button
                    type="button"
                    onClick={() => setZoom((value) => Math.min(1.5, Number((value + 0.1).toFixed(2))))}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('organizations.zoomIn')}
                </button>
                {toolbarExtra}
            </div>

            {!hasStructure ? (
                <p className="py-16 text-center text-sm text-gray-500 dark:text-slate-400">
                    {t('organizations.noStructureFound')}
                </p>
            ) : (
                <div className="overflow-x-auto pb-4">
                    <div
                        ref={captureRef}
                        className="inline-block min-w-full origin-top-left bg-white p-4 dark:bg-slate-900"
                        style={{ transform: `scale(${zoom})`, transformOrigin: 'top left' }}
                    >
                        <div className="flex flex-col items-center">
                            {/* Root: the selected organization */}
                            <div className="w-64 rounded-xl border-2 border-blue-500 bg-blue-50 px-4 py-3 text-center dark:border-blue-600 dark:bg-blue-950/50">
                                <p className="truncate font-mono text-[11px] text-blue-700 dark:text-blue-300">{tree.organization.code}</p>
                                <p className="truncate text-sm font-bold text-gray-900 dark:text-slate-100">
                                    {localizedName(tree.organization.name_en, tree.organization.name_am, locale)}
                                </p>
                                {tree.organization.type && (
                                    <p className="truncate text-[11px] text-blue-700 dark:text-blue-300">
                                        {localizedName(tree.organization.type.name_en, tree.organization.type.name_am, locale)}
                                    </p>
                                )}
                            </div>

                            {rootChildren.length > 0 && (
                                <>
                                    <Stem />
                                    <ChildRow>{rootChildren}</ChildRow>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
