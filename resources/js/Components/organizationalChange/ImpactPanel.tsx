import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';
import type { ImpactPayload } from './types';

type Props = { impact: ImpactPayload | null; live?: boolean };

/**
 * Real impact figures. Every number comes from a live count on the server.
 *
 * A figure the data cannot support is rendered as "—" with an explanation,
 * never as 0. Approved-headcount comes from position_establishments, and an
 * organization that has not adopted establishment records has no ceiling to
 * report — showing 0 there produced a panel that contradicted itself
 * (0 approved, 3 occupied, 0 vacant).
 */
export default function ImpactPanel({ impact, live = true }: Props): JSX.Element | null {
    const { t } = useLocale();

    if (!impact || impact.kind === 'none' || impact.kind === 'narrative') {
        return (
            <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationalChangeRequests.impact.heading')}
                </h2>
                <p className="mt-2 text-sm text-gray-500 dark:text-slate-400">
                    {t('organizationalChangeRequests.impact.noImpact')}
                </p>
            </section>
        );
    }

    const isPosition = impact.kind === 'position';
    const hasEstablishment = impact.has_establishment_data !== false;

    /* Which figures matter depends on what is being changed. */
    const unitKeys = [
        'child_units_direct', 'child_units_total', 'positions_affected',
        'active_positions_affected', 'employees_affected', 'active_assignments',
        'establishments_affected', 'user_scopes_affected', 'parent_current_children',
    ];
    const positionKeys = [
        'existing_positions', 'unit_existing_positions',
        'approved_positions', 'unit_approved_positions',
        'occupied_positions', 'unit_occupied_positions',
        'vacant_positions', 'unit_vacant_positions',
        'requested_additional_positions', 'expected_total_after_approval',
        'assigned_employees', 'active_assignments', 'establishment_records',
    ];

    // A key is shown when it is a number, or when it is an explicitly null
    // establishment figure that deserves a "no data" cell rather than silence.
    const establishmentDerived = new Set([
        'approved_positions', 'unit_approved_positions',
        'vacant_positions', 'unit_vacant_positions',
    ]);

    const keys = (isPosition ? positionKeys : unitKeys).filter(key => {
        if (typeof impact[key] === 'number') {
            return true;
        }
        return impact[key] === null && establishmentDerived.has(key);
    });

    const labelFor: Record<string, string> = {
        child_units_direct: 'childUnitsDirect',
        child_units_total: 'childUnitsTotal',
        positions_affected: 'positionsAffected',
        active_positions_affected: 'activePositionsAffected',
        employees_affected: 'employeesAffected',
        active_assignments: 'activeAssignments',
        establishments_affected: 'establishmentsAffected',
        user_scopes_affected: 'userScopesAffected',
        parent_current_children: 'parentCurrentChildren',
        existing_positions: 'existingPositions',
        unit_existing_positions: 'existingPositions',
        approved_positions: 'approvedPositions',
        unit_approved_positions: 'approvedPositions',
        occupied_positions: 'occupiedPositions',
        unit_occupied_positions: 'occupiedPositions',
        vacant_positions: 'vacantPositions',
        unit_vacant_positions: 'vacantPositions',
        requested_additional_positions: 'requestedAdditional',
        expected_total_after_approval: 'expectedTotal',
        assigned_employees: 'assignedEmployees',
        establishment_records: 'establishmentRecords',
    };

    return (
        <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <header className="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationalChangeRequests.impact.heading')}
                </h2>
                <span className="text-xs text-gray-500 dark:text-slate-400">
                    {live ? t('organizationalChangeRequests.impact.live') : t('organizationalChangeRequests.impact.atApproval')}
                </span>
            </header>

            <dl className="grid grid-cols-2 gap-px bg-gray-100 sm:grid-cols-3 lg:grid-cols-4 dark:bg-slate-800">
                {keys.map(key => {
                    const value = impact[key];
                    const unavailable = value === null;

                    return (
                        <div key={key} className="bg-white p-3 dark:bg-slate-900">
                            <dt className="text-xs leading-5 text-gray-500 dark:text-slate-400">
                                {t(`organizationalChangeRequests.impact.${labelFor[key]}`)}
                            </dt>
                            <dd
                                className={`mt-1 text-lg font-semibold tabular-nums ${
                                    unavailable
                                        ? 'text-gray-400 dark:text-slate-600'
                                        : 'text-gray-900 dark:text-slate-100'
                                }`}
                            >
                                {unavailable ? '—' : String(value)}
                            </dd>
                        </div>
                    );
                })}
            </dl>

            {isPosition && !hasEstablishment && (
                <p className="border-t border-gray-100 px-4 py-2.5 text-xs leading-5 text-gray-500 dark:border-slate-800 dark:text-slate-400">
                    {t('organizationalChangeRequests.impact.noEstablishmentData')}
                </p>
            )}
        </section>
    );
}
