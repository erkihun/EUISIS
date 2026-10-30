/*
 * Service Delivery Monitoring & Support — the props the module's pages
 * receive (Modules/ServiceMonitoring/Http/Controllers and
 * Application/Services/*Presenter). Statuses are unions of the backend enums.
 */

export type ReportingLevel = 'center' | 'sub_city' | 'woreda';
export type FormType = 'A' | 'B';
export type PeriodStatus = 'scheduled' | 'open' | 'closed' | 'locked';
export type SubmissionStatus = 'draft' | 'submitted' | 'returned' | 'accepted' | 'superseded';
export type SubjectType = 'employee' | 'customer';
export type RoleCategory = 'appointee_leader' | 'merit_leader' | 'professional' | 'customer';
export type ReasonCategory = 'service_delay' | 'theft_corruption' | 'malpractice';
export type FinalAction = 'warning' | 'salary_fine' | 'demotion' | 'removal_from_responsibility' | 'dismissal' | 'legal_action' | 'other';
export type TechnologyType = 'web_system' | 'mobile_app' | 'ussd_sms' | 'kiosk' | 'api_integration' | 'other';
export type VerificationStatus = 'pending' | 'verified' | 'rejected';
export type ClarificationStatus = 'open' | 'answered' | 'closed';
export type VisitStatus = 'planned' | 'completed' | 'cancelled';
export type FindingSeverity = 'low' | 'medium' | 'high';
export type ActionItemStatus = 'open' | 'in_progress' | 'done' | 'cancelled';
export type ReportRange = 'quarter' | 'six_months' | 'nine_months' | 'annual';
export type ComplianceStatus = 'on_time' | 'late_extension' | 'in_review' | 'returned' | 'pending' | 'missing' | 'not_submitted';
export type ServiceLevel = 'main' | 'sub';
export type SectionNumber = 1 | 2 | 3 | 4;

export interface Named {
    name_en: string | null;
    name_am: string | null;
}

export interface NamedWithId extends Named {
    id: string;
}

export interface Period {
    id: string;
    fiscal_year_id: string;
    quarter: number;
    label: string;
    form_version: string;
    starts_on: string;
    ends_on: string;
    opens_on: string;
    submission_due_on: string;
    subcity_review_due_on: string;
    city_review_due_on: string;
    deadline_at: string;
    status: PeriodStatus;
    locked_at: string | null;
    reopened_reason: string | null;
}

export interface FiscalYearRow {
    id: string;
    ec_year: number;
    starts_on: string;
    ends_on: string;
    status: 'active' | 'closed';
    periods: Period[];
}

export interface Compliance {
    status: ComplianceStatus;
    late: boolean;
    /** May be recorded as not submitted (nothing submitted, or returned and past the deadline). */
    recordable: boolean;
    submission_id: string | null;
    submission_status: SubmissionStatus | null;
    revision_no: number | null;
}

export type ComplianceTally = Record<ComplianceStatus, number>;

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

/* ── Form definition (Domain/Forms/FormDefinition::describe) ── */

export interface Section2Field {
    key: string;
    label: string;
    aggregation: 'flow' | 'stock';
    group: string | null;
    group_label: string | null;
    newly_organized: boolean;
    prefill: boolean;
    reference: boolean;
}

export interface FormDescription {
    version: string;
    form_type: FormType;
    ec_year: number;
    newly_organized_rule: boolean;
    title: string;
    general_notes: string[];
    section_1: {
        title: string;
        heading: string;
        tables: { level: ReportingLevel; title: string; institution_column: string; remark_column: string }[];
        columns: { key: string; label: string }[];
        role_group: string;
        role_columns: { value: RoleCategory; label: string }[];
        summary: {
            title: string;
            action_column: string;
            rows: { value: FinalAction; label: string }[];
            total: string;
            groups: { level: ReportingLevel; label: string }[];
            role_columns: { value: RoleCategory; label: string }[];
            remark: string;
            notes: string[];
        };
    };
    section_2: { title: string; institution_column: string; fields: Section2Field[]; narrative: string; notes: string[] };
    section_3: { title: string; columns: Record<string, string>; notes: string[] };
    section_4: { title: string; columns: Record<string, string>; notes: string[] };
}

/* ── Official layout tables (Infrastructure/Exports/OfficialFormLayout) ── */

export interface LayoutCell {
    text: string;
    colspan: number;
    rowspan: number;
}

export interface LayoutTable {
    title: string;
    subtitle: string | null;
    header: LayoutCell[][];
    body: (string | number)[][];
    total_rows: number[];
    notes: string[];
}

export type LayoutSections = Record<'section_1' | 'summary' | 'section_2' | 'section_3' | 'section_4', LayoutTable[]>;

export interface OfficialForm {
    form_type: FormType;
    title?: string;
    general_notes?: string[];
    sections: LayoutSections;
}

/* ── Submission screens (Application/Services/SubmissionPresenter) ── */

export interface AccountabilityRow {
    id: string;
    sequence: number;
    subject_type: SubjectType;
    employee_id: string | null;
    full_name: string | null;
    office_name: string | null;
    role_category: RoleCategory;
    reason_category: ReasonCategory;
    reason_detail: string | null;
    final_action: FinalAction;
    action_detail: string | null;
    action_date: string;
    remark: string | null;
    source: 'manual' | 'discipline';
    repeat_confirmed: boolean;
    repeat_warning: boolean;
}

export interface Summary {
    matrix: Record<ReportingLevel, Record<FinalAction, Record<RoleCategory, number>>>;
    row_totals: Record<ReportingLevel, Record<FinalAction, number>>;
    column_totals: Record<ReportingLevel, Record<RoleCategory, number>>;
    level_totals: Record<ReportingLevel, number>;
    grand_total: number;
}

export interface PrefillRow {
    system_value: number | null;
    source: string;
    notice: string | null;
    override_reason: string | null;
    captured_at: string | null;
}

export interface DigitalServiceRow {
    id: string;
    sequence: number;
    level: ServiceLevel;
    parent_entry_id: string | null;
    service_id: string | null;
    service_name_am: string | null;
    service_name_en: string | null;
    is_catalog_gap: boolean;
    technology_type: TechnologyType;
    technology_other: string | null;
    system_name: string;
    system_count: number;
    go_live_on: string;
    remark: string | null;
    verification_status: VerificationStatus;
    rejection_reason: string | null;
}

export interface Suggestion {
    decision_id: string;
    employee_id: string;
    employee_name: string | null;
    final_action: FinalAction;
    penalty: string;
    penalty_amount: number | null;
    action_date: string;
    role_category: RoleCategory | null;
    case_number: string | null;
    used: boolean;
}

export interface ReviewCommentRow {
    id: string;
    section: SectionNumber;
    line_reference: string | null;
    body: string;
    author: string | null;
    created_at: string | null;
    resolved: boolean;
}

export interface AttachmentRow {
    id: string;
    kind: string;
    original_name: string;
    mime: string;
    size: number;
    created_at: string | null;
    download_url: string;
}

export interface SubmissionDetail {
    id: string;
    status: SubmissionStatus;
    revision_no: number;
    is_current: boolean;
    level: ReportingLevel;
    form_type: FormType;
    form_version: string;
    organization: NamedWithId;
    participant_id: string;
    period: {
        id: string;
        quarter: number;
        ec_year: number;
        label: string;
        starts_on: string;
        ends_on: string;
        submission_due_on: string;
        deadline_at: string;
        locked: boolean;
        status: PeriodStatus;
    };
    nothing: Record<SectionNumber, boolean>;
    special_support_narrative: string | null;
    submitted_at: string | null;
    submitted_late: boolean;
    accepted_at: string | null;
    extension_due_at: string | null;
    deadline_passed: boolean;
    attestation: { name: string | null; title: string | null; organization: string | null; at: string } | null;
}

export interface SubmissionProps {
    submission: SubmissionDetail;
    definition: FormDescription;
    section1: { named: boolean; entries: AccountabilityRow[]; summary: Summary };
    section2: {
        values: Record<string, number | null>;
        prefill: Record<string, PrefillRow>;
        driver_list_empty: boolean;
        notices: Record<string, string>;
    };
    section3: {
        totals: { main_total: number | null; sub_total: number | null; catalog_main_total: number };
        services: DigitalServiceRow[];
        catalog: NamedWithId[];
        fiscal_year: { from: string; to: string };
    };
    section4: { values: Record<string, number | string | null>; min_text: number };
    problems: Partial<Record<SectionNumber, string[]>>;
    completeness: Record<SectionNumber, boolean>;
    comments: ReviewCommentRow[];
    attachments: AttachmentRow[];
    history: { from: SubmissionStatus | null; to: SubmissionStatus; actor: string | null; reason: string | null; at: string | null }[];
    revisions: { id: string; revision_no: number; status: SubmissionStatus }[];
    corrections: { id: string; status: 'pending' | 'approved' | 'rejected'; reason: string; decision_note: string | null; created_at: string | null; new_submission_id: string | null }[];
    declaration: string;
    require_stamped_copy: boolean;
    can: {
        edit: boolean;
        submit: boolean;
        review: boolean;
        comment: boolean;
        view_named: boolean;
        request_correction: boolean;
        decide_correction: boolean;
        export: boolean;
        open_clarification: boolean;
    };
}

export interface SubmissionListRow {
    id: string;
    status: SubmissionStatus;
    level: ReportingLevel;
    form_type: FormType;
    revision_no: number;
    submitted_at: string | null;
    submitted_late: boolean;
    period_label: string;
    organization: Named;
    can_edit: boolean;
}

export interface ExportRow {
    id: string;
    scope_type: 'submission' | 'sub_city' | 'city';
    range: ReportRange;
    quarter: number;
    format: 'xlsx' | 'pdf';
    status: 'queued' | 'ready' | 'failed';
    filename: string | null;
    created_at: string | null;
    download_url: string | null;
}

export interface ReportTotals {
    count: number;
    section2: Record<string, number>;
    totals: { main_total: number; sub_total: number };
    digital: { main: number; sub: number; systems: number };
    section4: Record<string, number>;
}

export interface ReportSummary {
    range: ReportRange;
    quarters: number[];
    from: string | null;
    to: string | null;
    from_snapshot: boolean;
    named: boolean;
    totals: Record<'all' | ReportingLevel, ReportTotals>;
    participants: number;
    accountability: number;
}

export interface Selection {
    fiscal_year_id: string | null;
    quarter: number;
    range: ReportRange;
    participant_id?: string;
}

export interface ActionItemRow {
    id: string;
    title: string;
    due_on: string;
    status: ActionItemStatus;
    overdue: boolean;
    completion_note: string | null;
    finding_id: string | null;
    visit_id: string;
    owner_organization: Named;
    owner_user: string | null;
    evidence: { id: string; original_name: string; download_url: string }[];
    can_update: boolean;
}
