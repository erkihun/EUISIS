/** The Core workspace (docs/architecture/core-workspace.md). */

export type CoreNavItem = { route: string; label_key: string; icon: string };

export type CoreNavGroup = { key: string; label_key: string; icon: string; items: CoreNavItem[] };

export type CoreNavSection = { label_key: string; groups: CoreNavGroup[] };

/**
 * Shared as `workspace` while the person works in Core, or in the court module
 * (ADR 0020: its staff area or a party portal, with its own home, way out and
 * notification feed); null everywhere else.
 */
export type CoreWorkspaceShared = {
    name: 'core' | 'court';
    nav: CoreNavSection[];
    home?: string;
    exit?: string;
    bell?: { feed: string; read: string; readAll: string; viewAll: string };
} | null;

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
