/**
 * Owner rule 2026-11-08: Tip-offs and public complaints are separate modules,
 * each with its own pages under its own prefix — /tip-off-admin (tip-offs.*)
 * and /complaint-admin (public-complaints.*). A page that serves either type
 * builds its links through this, never through a shared route.
 */
export type CaseType = 'suggestion' | 'complaint';

export const CASE_MODULE: Record<CaseType, string> = { suggestion: 'tip-offs', complaint: 'public-complaints' };

/** A route of the case's own module, e.g. caseRoute('complaint', 'cases.show', { submission: id }). */
export function caseRoute(type: CaseType, action: string, params: Record<string, string | number> = {}): string {
    return route(`${CASE_MODULE[type]}.${action}`, params as never) as string;
}
