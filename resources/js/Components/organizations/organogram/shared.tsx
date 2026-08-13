import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';

/**
 * Node primitives shared by every organogram layout.
 *
 * Node CONTENT is defined once here so the five layouts differ only in
 * arrangement — a field added to a box appears in all of them, and no layout
 * can drift into showing more employee data than the others.
 */

export type OrganogramTree = OrganizationStructureTreeData;
export type OrganogramUnit = OrganizationStructureTreeData['units'][number];
export type OrganogramPosition = OrganizationStructureTreeData['direct_positions'][number];

export type OrganogramLayout =
    | 'vertical'
    | 'horizontal'
    | 'compact'
    | 'position-focused'
    | 'unit-focused';

export const ORGANOGRAM_LAYOUTS: OrganogramLayout[] = [
    'vertical',
    'horizontal',
    'compact',
    'position-focused',
    'unit-focused',
];

/** i18n key for each layout, kept beside the union so they cannot drift. */
export const LAYOUT_LABEL_KEYS: Record<OrganogramLayout, string> = {
    vertical: 'organizations.layoutVertical',
    horizontal: 'organizations.layoutHorizontal',
    compact: 'organizations.layoutCompact',
    'position-focused': 'organizations.layoutPositionFocused',
    'unit-focused': 'organizations.layoutUnitFocused',
};

export function isOrganogramLayout(value: unknown): value is OrganogramLayout {
    return typeof value === 'string' && (ORGANOGRAM_LAYOUTS as string[]).includes(value);
}

/** Vertical stem dropping out of a parent box. */
export function Stem({ className = '' }: { className?: string }) {
    return <div className={`mx-auto h-6 w-px bg-gray-300 dark:bg-slate-600 ${className}`} />;
}

/** Horizontal connector used by the left-to-right layout. */
export function Arm() {
    return <div className="my-auto h-px w-6 flex-none bg-gray-300 dark:bg-slate-600" />;
}

/**
 * Horizontal rail spanning a row of children with a drop into each. The outer
 * half-segments are hidden so the rail stops at the first and last child.
 */
export function ChildRow({ children }: { children: React.ReactNode[] }) {
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
                        <div className={`h-px flex-1 ${index === 0 ? '' : 'bg-gray-300 dark:bg-slate-600'}`} />
                        <div className="h-6 w-px bg-gray-300 dark:bg-slate-600" />
                        <div className={`h-px flex-1 ${index === count - 1 ? '' : 'bg-gray-300 dark:bg-slate-600'}`} />
                    </div>
                    {child}
                </div>
            ))}
        </div>
    );
}

export function OrganizationBox({ tree, compact = false }: { tree: OrganogramTree; compact?: boolean }) {
    const { locale } = useLocale();
    const organization = tree.organization;

    return (
        <div
            className={`rounded-xl border-2 border-blue-500 bg-blue-50 text-center dark:border-blue-600 dark:bg-blue-950/50 ${
                compact ? 'w-48 px-3 py-2' : 'w-64 px-4 py-3'
            }`}
        >
            <p className="truncate font-mono text-[11px] text-blue-700 dark:text-blue-300">{organization.code}</p>
            <p className={`truncate font-bold text-gray-900 dark:text-slate-100 ${compact ? 'text-xs' : 'text-sm'}`}>
                {localizedName(organization.name_en, organization.name_am, locale)}
            </p>
            {organization.type && !compact && (
                <p className="truncate text-[11px] text-blue-700 dark:text-blue-300">
                    {localizedName(organization.type.name_en, organization.type.name_am, locale)}
                </p>
            )}
        </div>
    );
}

export function UnitBox({
    unit,
    compact = false,
    emphasized = false,
    toggle,
}: {
    unit: OrganogramUnit;
    compact?: boolean;
    emphasized?: boolean;
    toggle?: { expanded: boolean; count: number; onToggle: () => void };
}) {
    const { t, locale } = useLocale();

    return (
        <div
            className={`rounded-lg border bg-violet-50 text-center dark:bg-violet-950/40 ${
                emphasized
                    ? 'border-2 border-violet-500 dark:border-violet-500'
                    : 'border-violet-300 dark:border-violet-800'
            } ${compact ? 'w-40 px-2 py-1' : 'w-56 px-3 py-2'}`}
        >
            {unit.code && (
                <p className="truncate font-mono text-[10px] text-violet-700 dark:text-violet-400">{unit.code}</p>
            )}
            <p className={`truncate font-semibold text-gray-900 dark:text-slate-100 ${compact ? 'text-[11px]' : 'text-sm'}`}>
                {localizedName(unit.name_en, unit.name_am, locale)}
            </p>
            {unit.unit_type?.name_en && !compact && (
                <p className="truncate text-[10px] text-gray-500 dark:text-slate-400">
                    {localizedName(unit.unit_type.name_en, unit.unit_type.name_am, locale)}
                </p>
            )}
            {toggle && toggle.count > 0 && (
                <button
                    type="button"
                    onClick={toggle.onToggle}
                    className="mt-1 text-[10px] font-medium text-violet-700 hover:underline dark:text-violet-300 print:hidden"
                >
                    {toggle.expanded ? `− ${t('organizations.collapseAll')}` : `+ ${toggle.count}`}
                </button>
            )}
        </div>
    );
}

/** First letters of the first two words — used when no photo is stored. */
function initialsOf(fullName: string): string {
    return fullName
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0).toLocaleUpperCase())
        .join('');
}

/**
 * Employee photo thumbnail, falling back to initials. The employee node only
 * renders at depth = employee, so the photo is inherently depth-gated: at
 * shallower depths `applyDepth` nulls the assignment and this never mounts.
 */
export function EmployeePhoto({ employee, size }: { employee: { full_name: string; photo_url: string | null }; size: number }) {
    const { t } = useLocale();
    const [failed, setFailed] = useState(false);
    const dimension = { width: `${size}px`, height: `${size}px` };

    if (!employee.photo_url || failed) {
        return (
            <span
                style={dimension}
                title={t('organizations.noPhoto')}
                className="flex flex-none items-center justify-center rounded-full bg-emerald-200 text-[10px] font-bold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200"
            >
                {initialsOf(employee.full_name) || '—'}
            </span>
        );
    }

    return (
        <img
            src={employee.photo_url}
            alt={t('organizations.employeePhoto')}
            style={dimension}
            onError={() => setFailed(true)}
            // crossOrigin keeps the canvas untainted so html-to-image can
            // rasterise the photo for PNG/PDF export.
            crossOrigin="anonymous"
            className="flex-none rounded-full object-cover ring-1 ring-emerald-300 dark:ring-emerald-800"
        />
    );
}

export function EmployeeBox({ position, compact = false }: { position: OrganogramPosition; compact?: boolean }) {
    const { t } = useLocale();
    const employee = position.assignment?.employee;

    if (!employee) {
        return null;
    }

    return (
        <div
            className={`flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 text-left dark:border-emerald-800 dark:bg-emerald-950/40 ${
                compact ? 'w-40 px-2 py-1' : 'w-52 px-3 py-2'
            }`}
        >
            <EmployeePhoto employee={employee} size={compact ? 22 : 30} />
            <div className="min-w-0 flex-1">
                <p className="truncate font-mono text-[10px] text-emerald-700 dark:text-emerald-400">
                    {employee.employee_number}
                </p>
                <p className={`truncate font-semibold text-gray-900 dark:text-slate-100 ${compact ? 'text-[11px]' : 'text-xs'}`}>
                    {employee.full_name}
                </p>
                {!compact && (
                    <p className="truncate text-[10px] text-emerald-700 dark:text-emerald-400">
                        {t(`common.${employee.status}`)}
                    </p>
                )}
            </div>
        </div>
    );
}

export function OccupancyBadge({ occupancy }: { occupancy: 'occupied' | 'vacant' }) {
    const { t } = useLocale();

    return (
        <span
            className={`inline-block rounded-full px-2 py-0.5 text-[10px] font-medium ${
                occupancy === 'occupied'
                    ? 'bg-amber-200 text-amber-900 dark:bg-amber-900/60 dark:text-amber-200'
                    : 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900/60 dark:text-emerald-200'
            }`}
        >
            {t(`common.${occupancy}`)}
        </span>
    );
}

export function PositionBox({
    position,
    compact = false,
    emphasized = false,
}: {
    position: OrganogramPosition;
    compact?: boolean;
    emphasized?: boolean;
}) {
    const { locale } = useLocale();

    return (
        <div
            className={`rounded-lg border bg-amber-50 text-center dark:bg-amber-950/40 ${
                emphasized ? 'border-2 border-amber-500 dark:border-amber-500' : 'border-amber-300 dark:border-amber-800'
            } ${compact ? 'w-40 px-2 py-1' : 'w-52 px-3 py-2'}`}
        >
            {position.code && (
                <p className="truncate font-mono text-[10px] text-amber-700 dark:text-amber-400">{position.code}</p>
            )}
            <p className={`truncate font-semibold text-gray-900 dark:text-slate-100 ${compact ? 'text-[11px]' : 'text-xs'}`}>
                {localizedName(position.name_en, position.name_am, locale)}
            </p>
            {position.bpr_name && !compact && (
                <p className="truncate text-[10px] text-gray-500 dark:text-slate-400">{position.bpr_name}</p>
            )}
            <span className="mt-1 inline-block">
                <OccupancyBadge occupancy={position.occupancy} />
            </span>
        </div>
    );
}

export type OrganogramDepth = 'organization_unit' | 'position' | 'employee';

export const ORGANOGRAM_DEPTHS: OrganogramDepth[] = ['organization_unit', 'position', 'employee'];

/** i18n key per depth, kept beside the union so they cannot drift apart. */
export const DEPTH_LABEL_KEYS: Record<OrganogramDepth, string> = {
    organization_unit: 'organizations.depthUntilUnit',
    position: 'organizations.depthUntilPosition',
    employee: 'organizations.depthUntilEmployee',
};

export function isOrganogramDepth(value: unknown): value is OrganogramDepth {
    return typeof value === 'string' && (ORGANOGRAM_DEPTHS as string[]).includes(value);
}

/**
 * Apply the selected depth by pruning the tree ONCE, before any layout sees it.
 *
 * Doing it here rather than inside each layout means depth cannot be honoured
 * inconsistently — and because the layouts render whatever tree they are given,
 * PNG/PDF/print automatically capture the pruned structure too.
 *
 * - organization_unit → positions removed entirely
 * - position          → positions kept, employee assignment stripped
 * - employee          → full tree, unchanged
 */
export function applyDepth(tree: OrganogramTree, depth: OrganogramDepth): OrganogramTree {
    if (depth === 'employee') {
        return tree;
    }

    const prunePositions = (positions: OrganogramPosition[]): OrganogramPosition[] =>
        depth === 'organization_unit'
            ? []
            // Drop the assignment so no employee data reaches the DOM at all;
            // occupancy is kept so the vacant/occupied badge stays truthful.
            : positions.map((position) => ({ ...position, assignment: null }));

    const pruneUnits = (units: OrganogramUnit[]): OrganogramUnit[] =>
        units.map((unit) => ({
            ...unit,
            positions: prunePositions(unit.positions),
            children: pruneUnits(unit.children),
        }));

    return {
        ...tree,
        units: pruneUnits(tree.units),
        direct_positions: prunePositions(tree.direct_positions),
    };
}

/** Every unit id in the tree — seeds expand-all and collapse-all. */
export function collectUnitIds(units: OrganogramUnit[]): string[] {
    return units.flatMap((unit) => [unit.id, ...collectUnitIds(unit.children)]);
}

/** Depth-first flatten, keeping each unit's depth for indented layouts. */
export function flattenUnits(units: OrganogramUnit[], depth = 0): Array<{ unit: OrganogramUnit; depth: number }> {
    return units.flatMap((unit) => [{ unit, depth }, ...flattenUnits(unit.children, depth + 1)]);
}
