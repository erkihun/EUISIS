import {
    ChildRow,
    EmployeePhoto,
    OccupancyBadge,
    OrganizationBox,
    Stem,
    UnitBox,
    type OrganogramTree,
    type OrganogramUnit,
} from './shared';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

/**
 * Small-node variant for large structures. Positions are listed inside their
 * unit card rather than drawn as separate boxes, which keeps the chart width
 * manageable when an organization has many positions.
 */

function CompactPositionRow({ position }: { position: OrganogramTree['direct_positions'][number] }) {
    const { locale } = useLocale();
    const employee = position.assignment?.employee;

    return (
        <li className="flex items-center gap-1.5 border-t border-gray-100 px-2 py-1 text-left dark:border-slate-800">
            <span className="min-w-0 flex-1 truncate text-[10px] text-gray-700 dark:text-slate-300">
                {position.code && <span className="font-mono text-gray-400">{position.code} </span>}
                {localizedName(position.name_en, position.name_am, locale)}
            </span>
            {employee ? (
                <span className="flex min-w-0 items-center gap-1">
                    <EmployeePhoto employee={employee} size={16} />
                    <span className="truncate text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
                        {employee.full_name}
                    </span>
                </span>
            ) : (
                <OccupancyBadge occupancy="vacant" />
            )}
        </li>
    );
}

function CompactUnitBranch({
    unit,
    expandedIds,
    onToggle,
}: {
    unit: OrganogramUnit;
    expandedIds: Set<string>;
    onToggle: (id: string) => void;
}) {
    const isExpanded = expandedIds.has(unit.id);
    const childCount = unit.children.length + unit.positions.length;

    return (
        <div className="flex flex-col items-center">
            <div className="w-44 overflow-hidden rounded-lg border border-violet-300 bg-white dark:border-violet-800 dark:bg-slate-900">
                <UnitBox
                    unit={unit}
                    compact
                    toggle={{ expanded: isExpanded, count: childCount, onToggle: () => onToggle(unit.id) }}
                />
                {isExpanded && unit.positions.length > 0 && (
                    <ul>
                        {unit.positions.map((position) => (
                            <CompactPositionRow key={position.id} position={position} />
                        ))}
                    </ul>
                )}
            </div>

            {isExpanded && unit.children.length > 0 && (
                <>
                    <Stem />
                    <ChildRow>
                        {unit.children.map((child) => (
                            <CompactUnitBranch
                                key={child.id}
                                unit={child}
                                expandedIds={expandedIds}
                                onToggle={onToggle}
                            />
                        ))}
                    </ChildRow>
                </>
            )}
        </div>
    );
}

export default function CompactOrganogram({
    tree,
    expandedIds,
    onToggle,
}: {
    tree: OrganogramTree;
    expandedIds: Set<string>;
    onToggle: (id: string) => void;
}) {
    return (
        <div className="flex flex-col items-center">
            <OrganizationBox tree={tree} compact />
            {tree.units.length > 0 && (
                <>
                    <Stem />
                    <ChildRow>
                        {tree.units.map((unit) => (
                            <CompactUnitBranch
                                key={unit.id}
                                unit={unit}
                                expandedIds={expandedIds}
                                onToggle={onToggle}
                            />
                        ))}
                    </ChildRow>
                </>
            )}
        </div>
    );
}
