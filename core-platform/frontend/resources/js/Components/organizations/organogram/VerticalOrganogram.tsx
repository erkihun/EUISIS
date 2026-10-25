import {
    ChildRow,
    EmployeeBox,
    OrganizationBox,
    PositionBox,
    Stem,
    UnitBox,
    type OrganogramTree,
    type OrganogramUnit,
} from './shared';

/** Top-down chart: organization on top, units, then positions and employees. */

function PositionBranch({ position }: { position: OrganogramTree['direct_positions'][number] }) {
    return (
        <div className="flex flex-col items-center">
            <PositionBox position={position} />
            {position.occupancy === 'occupied' && position.assignment?.employee && (
                <>
                    <Stem />
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
        <div className="flex flex-col items-center">
            <UnitBox
                unit={unit}
                toggle={{ expanded: isExpanded, count: childCount, onToggle: () => onToggle(unit.id) }}
            />
            {children.length > 0 && (
                <>
                    <Stem />
                    <ChildRow>{children}</ChildRow>
                </>
            )}
        </div>
    );
}

export default function VerticalOrganogram({
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
        <div className="flex flex-col items-center">
            <OrganizationBox tree={tree} />
            {rootChildren.length > 0 && (
                <>
                    <Stem />
                    <ChildRow>{rootChildren}</ChildRow>
                </>
            )}
        </div>
    );
}
