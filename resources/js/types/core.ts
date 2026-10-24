/** The Core workspace (docs/architecture/core-workspace.md). */

export type CoreNavItem = { route: string; label_key: string; icon: string };

export type CoreNavGroup = { key: string; label_key: string; icon: string; items: CoreNavItem[] };

export type CoreNavSection = { label_key: string; groups: CoreNavGroup[] };

/** Shared as `workspace` while the person works in Core; null everywhere else. */
export type CoreWorkspaceShared = { name: 'core'; nav: CoreNavSection[] } | null;

export type CoreMetric = { key: string; value: number | string | null };

/** One recent master-data change from the audit log: what and who, never values. */
export type CoreActivity = { event_type: string; actor_name: string | null; subject: string | null; created_at: string | null };

export type CoreDomain = { key: string; href: string | null; metrics: CoreMetric[] };

export type CoreMetrics = {
    citywide: boolean;
    /** How many organizations a scoped viewer covers; null for a citywide viewer. */
    organization_count: number | null;
    domains: CoreDomain[];
};
