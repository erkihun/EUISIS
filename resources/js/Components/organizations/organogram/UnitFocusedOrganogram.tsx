import {
    OccupancyBadge,
    OrganizationBox,
    UnitBox,
    flattenUnits,
    type OrganogramTree,
} from './shared';
import { useLocale } from '@/hooks/useLocale';

/**
 * Units are the subject here: each unit is an emphasized card carrying a
 * summary of its positions (total / occupied / vacant) rather than a full
 * position tree, so the shape of the organization reads at a glance.
 */
export default function UnitFocusedOrganogram({ tree }: { tree: OrganogramTree }) {
    const { t } = useLocale();
    const units = flattenUnits(tree.units);

    return (
        <div className="flex flex-col items-center gap-5">
            <OrganizationBox tree={tree} />

            {units.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-slate-400">
                    {t('organizationUnits.noOrganizationUnitsFound')}
                </p>
            ) : (
                <div className="w-full max-w-4xl space-y-3">
                    {units.map(({ unit, depth }) => {
                        const occupied = unit.positions.filter((position) => position.occupancy === 'occupied').length;
                        const vacant = unit.positions.length - occupied;

                        return (
                            <div
                                key={unit.id}
                                className="flex flex-wrap items-center gap-3"
                                style={{ paddingLeft: `${depth * 24}px` }}
                            >
                                <UnitBox unit={unit} emphasized />

                                <dl className="flex flex-wrap items-center gap-2 text-[11px]">
                                    <div className="rounded-lg border border-gray-200 px-2 py-1 text-center dark:border-slate-700">
                                        <dd className="font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                                            {unit.positions.length}
                                        </dd>
                                        <dt className="text-gray-500 dark:text-slate-400">{t('organizations.positions')}</dt>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <OccupancyBadge occupancy="occupied" />
                                        <span className="font-semibold tabular-nums text-gray-700 dark:text-slate-300">
                                            {occupied}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <OccupancyBadge occupancy="vacant" />
                                        <span className="font-semibold tabular-nums text-gray-700 dark:text-slate-300">
                                            {vacant}
                                        </span>
                                    </div>
                                    {unit.children.length > 0 && (
                                        <span className="text-gray-500 dark:text-slate-400">
                                            {t('organizations.organizationUnits')}: {unit.children.length}
                                        </span>
                                    )}
                                </dl>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
