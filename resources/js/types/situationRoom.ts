/**
 * The Situation Room payload contract (App\Services\SituationRoom\SituationPanel
 * and the controllers). Every label arrives either as a translation key, as a
 * bilingual pair (organization and catalogue names) or as plain text (dates,
 * grade codes); the page resolves it in the visitor's language.
 */
export type SrLabel = { key: string } | { en: string | null; am: string | null } | string;

export type SrChartType = 'bar' | 'hbar' | 'stacked' | 'line' | 'donut';

export type SrReason =
    | 'NO_DATA' | 'CONFIGURATION_REQUIRED' | 'NEEDS_DATA' | 'NO_PERMISSION' | 'ERROR' | 'SELECT_ORGANIZATION'
    | 'SERVICE_LINKAGE_REQUIRED' | 'GIS_DATA_NOT_AVAILABLE' | 'MAP_LAYER_NOT_CONFIGURED' | 'NO_CURRENT_CYCLE'
    | 'SEPARATE_DOMAINS' | 'ALL_CLEAR' | 'HIERARCHY_PUBLISHED' | 'NEEDS_POLICY_DECISION_REPORTING_HIERARCHY'
    // Addis One Intelligence (lang/*/intelligence.php `reasons`)
    | 'TELEMETRY_OFF' | 'UNKNOWN' | 'NOT_CONFIGURED' | 'SLO_CONFIGURATION_REQUIRED' | 'FORECAST_DATA_INSUFFICIENT' | 'AI_UNAVAILABLE' | 'NEEDS_DEPLOYMENT_CONFIGURATION';

export interface SrKpiItem {
    key: string;
    label: string;
    definition: string | null;
    value: number | null;
    unit?: string;
    previous?: number;
    href?: string;
    state?: 'not_configured' | 'attention' | 'clear';
    threshold?: number | null;
}

export interface SrSeries {
    key: string;
    label: SrLabel;
    values: Array<number | null>;
}

export type SrColumnType = 'text' | 'label' | 'number' | 'percent' | 'date';

export interface SrColumn {
    key: string;
    label: SrLabel;
    type: SrColumnType;
}

export interface SrPagination {
    page: number;
    per_page: number;
    total: number;
}

interface SrPanelBase {
    key: string;
    title: string;
    description: string | null;
    source: string;
    href: string | null;
    span: 1 | 2 | 3;
}

export type SrPanel =
    | (SrPanelBase & { type: 'kpis'; reason: null; data: { items: SrKpiItem[] } })
    | (SrPanelBase & { type: SrChartType; reason: null; data: { categories: SrLabel[]; series: SrSeries[]; unit: string | null; palette?: 'diverging' } })
    | (SrPanelBase & { type: 'table'; reason: null; data: { columns: SrColumn[]; rows: Array<Record<string, unknown>>; pagination: SrPagination | null } })
    | (SrPanelBase & { type: 'notice'; reason: SrReason; data: Record<string, never> });

export interface SrResult<P = { panels: SrPanel[]; has_errors: boolean }> {
    generated_at: string;
    cached_for: number;
    payload: P;
}

export interface SrCategory {
    key: string;
    section: 'command' | 'people' | 'public_voice' | 'operations' | 'governance';
    icon: string;
    wall: boolean;
    href: string;
}

export interface SrNamedOption {
    id: string;
    label: SrLabel;
}

export interface SrScope {
    focus: string | null;
    breadcrumb: SrNamedOption[];
    children: SrNamedOption[];
    hierarchy: boolean;
    unrestricted: boolean;
    empty: boolean;
}

export interface SrPeriod {
    preset: 'today' | 'week' | 'month' | 'quarter' | 'year' | 'custom';
    from: string;
    to: string;
    granularity: 'day' | 'week' | 'month';
    timezone: string;
}

export type SrFilters = { period: SrPeriod } & Partial<Record<'organization_id' | 'organization_unit_id' | 'category_id' | 'position_service_id' | 'target_type' | 'status' | 'tip_type' | 'page', string>>;

export type SrQuery = Record<string, string>;

export interface SrLabels {
    en: Record<string, string>;
    am: Record<string, string>;
}

export interface SrCan {
    wall: boolean;
    reports: boolean;
    actions_manage: boolean;
    community_voice: boolean;
    intelligence: boolean;
}

export interface SrFrameProps {
    labels: SrLabels;
    categories: SrCategory[];
    filters: SrFilters;
    query: SrQuery;
    scope: SrScope;
    can: SrCan;
}
