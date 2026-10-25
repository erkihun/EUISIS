import {
    Arm,
    EmployeeBox,
    OrganizationBox,
    PositionBox,
    UnitBox,
    type OrganogramTree,
    type OrganogramUnit,
} from './shared';

/**
 * Left-to-right chart. Each level is a column; children stack vertically to the
 * right of their parent, joined by a vertical spine and a short arm per child.
 */

function Branch({ children }: { children: React.ReactNode[] }) {
    if (children.length === 0) {
        return null;
    }

    return (
        <div className="flex items-stretch">
            {/* Vertical spine spanning the children, trimmed at top and bottom. */}
            <div className="relative w-px flex-none bg-gray-300 dark:bg-slate-600" />
            <div className="flex flex-col justify-center gap-3">
                {children.map((child, index) => (
                    <div key={index} className="flex items-center">
                        <Arm />
                        {child}
                    </div>
                ))}
            </div>
        </div>
    );
}

function PositionBranch({ position }: { position: OrganogramTree['direct_positions'][number] }) {
    const hasEmployee = position.occupancy === 'occupied' && position.assignment?.employee;

    return (
        <div className="flex items-center">
            <PositionBox position={position} />
            {hasEmployee && (
                <>
                    <Arm />
                    <EmployeeBox position={position} />
                </>
            )}
        </div>
    );
}

function UnitBranch({
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

    const children: React.ReactNode[] = isExpanded
        ? [
            ...unit.positions.map((position) => <PositionBranch key={position.id} position={position} />),
            ...unit.children.map((child) => (
                <UnitBranch key={child.id} unit={child} expandedIds={expandedIds} onToggle={onToggle} />
            )),
        ]
        : [];

    return (
        <div className="flex items-center">
            <UnitBox
                unit={unit}
                toggle={{ expanded: isExpanded, count: childCount, onToggle: () => onToggle(unit.id) }}
            />
            {children.length > 0 && (
                <>
                    <Arm />
                    <Branch>{children}</Branch>
                </>
            )}
        </div>
    );
}

export default function HorizontalOrganogram({
    tree,
    expandedIds,
    onToggle,
}: {
    tree: OrganogramTree;
    expandedIds: Set<string>;
    onToggle: (id: string) => void;
}) {
    const rootChildren: React.ReactNode[] = [
        ...tree.direct_positions.map((position) => <PositionBranch key={position.id} position={position} />),
        ...tree.units.map((unit) => (
            <UnitBranch key={unit.id} unit={unit} expandedIds={expandedIds} onToggle={onToggle} />
        )),
    ];

    return (
        <div className="flex items-center">
            <OrganizationBox tree={tree} />
            {rootChildren.length > 0 && (
                <>
                    <Arm />
                    <Branch>{rootChildren}</Branch>
                </>
            )}
        </div>
    );
}
