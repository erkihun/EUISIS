import { usePage } from '@inertiajs/react';

/**
 * The committee board is served under Grievance Management and under
 * Discipline Management (ADR 0031). The server says which one and the
 * route-name prefix to use, so every link stays inside its own module.
 */
export function useCommitteeRoutes() {
    const props = usePage().props as { route_prefix?: string; domain?: string };
    const prefix = props.route_prefix ?? 'grievances.committees';
    return {
        cr: (name: string, params?: string | (string | number)[]): string => String(route(`${prefix}.${name}`, params)),
        discipline: props.domain === 'discipline',
    };
}
