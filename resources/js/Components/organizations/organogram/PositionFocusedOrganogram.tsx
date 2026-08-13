import {
    EmployeeBox,
    OrganizationBox,
    PositionBox,
    flattenUnits,
    type OrganogramTree,
} from './shared';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

/**
 * Positions are the subject here: units collapse to plain grouping headers and
 * every position is rendered as an emphasized card, with the employee card
 * attached only where the position is occupied.
 */
export default function PositionFocusedOrganogram({ tree }: { tree: OrganogramTree }) {
    const { t, locale } = useLocale();
    const groups = flattenUnits(tree.units).filter(({ unit }) => unit.positions.length > 0);
    const hasDirect = tree.direct_positions.length > 0;

    return (
        <div className="flex flex-col items-center gap-5">
            <OrganizationBox tree={tree} />

            {!hasDirect && groups.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-slate-400">{t('organizations.noPositionsFound')}</p>
            ) : (
                <div className="w-full max-w-5xl space-y-5">
                    {hasDirect && (
                        <section>
                            <h3 className="mb-2 border-b border-gray-200 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-slate-700 dark:text-slate-400">
                                {t('organizations.positions')}
                            </h3>
                            <div className="flex flex-wrap gap-4">
                                {tree.direct_positions.map((position) => (
                                    <div key={position.id} className="flex flex-col items-center gap-1">
                                        <PositionBox position={position} emphasized />
                                        {position.assignment?.employee && <EmployeeBox position={position} compact />}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    {groups.map(({ unit, depth }) => (
                        <section key={unit.id}>
                            {/* Unit is a heading, not a box — positions carry the emphasis. */}
                            <h3
                                className="mb-2 border-b border-gray-200 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-slate-700 dark:text-slate-400"
                                style={{ paddingLeft: `${depth * 12}px` }}
                            >
                                {unit.code && <span className="font-mono normal-case">{unit.code} · </span>}
                                {localizedName(unit.name_en, unit.name_am, locale)}
                            </h3>
                            <div className="flex flex-wrap gap-4" style={{ paddingLeft: `${depth * 12}px` }}>
                                {unit.positions.map((position) => (
                                    <div key={position.id} className="flex flex-col items-center gap-1">
                                        <PositionBox position={position} emphasized />
                                        {position.assignment?.employee && <EmployeeBox position={position} compact />}
                                    </div>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </div>
    );
}
