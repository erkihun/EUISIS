/** The `dashboard` prop of Court/Dashboard (Admin\DashboardController::fromAllTime / fromRange). */
export interface CourtDashboard {
    range: string;
    mode: 'daily' | 'monthly';
    kpis: { total: number; pending: number; active: number; resolved: number; activeUsers: number };
    recent: { id: number; case_number: string; title: string | null; status: string; created_at: string | null }[];
    /** One point per day or month of the period; `key` is the ISO date the bucket starts. */
    trend: { key: string; value: number }[];
    teams: { id: number; name: string; cases: number }[];
    members: CourtDashboardMember[];
    gender: { key: string; value: number }[];
    caseTypes: { key: string; value: number }[];
}

export interface CourtDashboardMember {
    id: number;
    name: string;
    team: string | null;
    status: string;
    avatar_url: string | null;
    cases: number;
    pending: number;
    active: number;
    resolved: number;
    /** A null label is a case without a type. */
    types: { label: string | null; count: number }[];
}

export interface ServiceHealth {
    database: boolean;
    queue_waiting: number | null;
    queue_ready: boolean;
    mail: boolean;
    sms: boolean;
    sms_ready: boolean;
    telegram: boolean;
    in_app: boolean;
}

export interface DataStorage {
    tables: number;
    database_size: string | null;
    storage_free: number | null;
    audit_events: number;
    last_backup: string | null;
}
