export interface Bilingual {
    name_en?: string | null;
    name_am?: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export type LeaveStatus = 'pending_supervisor' | 'pending_hr' | 'approved' | 'rejected' | 'cancelled';

export type LeaveCategory = 'annual' | 'personal' | 'sick' | 'maternity' | 'paternity' | 'unpaid' | 'special';

export interface EmployeeRef extends Bilingual {
    id: string;
    employee_number: string | null;
    service_start_date?: string | null;
}

export interface LeaveTypeRef extends Bilingual {
    id: string;
    code: string;
    category: LeaveCategory;
    day_unit: 'working' | 'calendar';
}

export interface LeaveRow {
    id: string;
    reference_number: string;
    employee: EmployeeRef | null;
    leave_type: LeaveTypeRef | null;
    organization: (Bilingual & { id: string }) | null;
    unit: (Bilingual & { id: string }) | null;
    budget_year: number;
    starts_on: string;
    ends_on: string;
    is_half_day: boolean;
    half_day_part: 'morning' | 'afternoon' | null;
    days_counted: number;
    status: LeaveStatus;
    registered_by_hr: boolean;
    submitted_at: string | null;
}

export interface LeaveEvent {
    id: string;
    action: string;
    actor: string | null;
    comment: string | null;
    data: Record<string, string | number | boolean> | null;
    at: string | null;
}

export interface LeaveDetail extends LeaveRow {
    reason: string | null;
    reason_hidden: boolean;
    contact_during_leave: string | null;
    pay_breakdown: { full: number; half: number } | null;
    expected_delivery_on: string | null;
    actual_delivery_on: string | null;
    ended_early_on: string | null;
    supervisor: { name: string } | null;
    supervisor_comment: string | null;
    supervisor_decided_at: string | null;
    hr_comment: string | null;
    hr_decided_at: string | null;
    cancel_reason: string | null;
    needs_document: boolean;
    documents: { id: string; name: string; size_bytes: number; uploaded_at: string | null }[];
    documents_hidden: boolean;
    events: LeaveEvent[];
    can: {
        decide_as_supervisor: boolean;
        decide_as_hr: boolean;
        cancel: boolean;
        end_early: boolean;
        record_delivery: boolean;
        add_document: boolean;
    };
    is_owner: boolean;
}

export interface TypeSummary extends Bilingual {
    id: string;
    code: string;
    category: LeaveCategory;
    day_unit: 'working' | 'calendar';
    pay_class: 'full' | 'none' | 'by_period';
    uses_balance: boolean;
    allows_half_day: boolean;
    requires_document: boolean;
    employee_can_request: boolean;
    has_rule: boolean;
    taken: number;
    pending: number;
    max_days_per_request: number | null;
    max_days_per_year: number | null;
    settings: Record<string, number> | null;
    // Types with a balance only.
    granted?: number;
    carried?: number;
    available?: number;
    free?: number;
    buckets?: { year: number; balance: number; usable_until: number }[];
    waiting?: { year: number; days: number; last_year: number }[];
    carry_over_mode?: 'none' | 'automatic' | 'authorized' | null;
    carry_over_years?: number | null;
    no_grant_reason?: string | null;
}

export interface LeaveSummary {
    budget_year: number;
    year_starts_on: string;
    year_ends_on: string;
    has_rules: boolean;
    rules_confirmed: boolean;
    types: TypeSummary[];
}

export interface LedgerLine {
    id: string;
    leave_type: Bilingual;
    entitlement_year: number;
    entry_type: string;
    days: number;
    usable_until_year: number | null;
    note: string | null;
    reference_number: string | null;
    leave_request_id: string | null;
    author: string | null;
    created_at: string | null;
}
