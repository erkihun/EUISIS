/*
 * Grievance Management — Inertia prop contracts. These mirror exactly what
 * the controllers in app/Http/Controllers/Grievances send (via
 * App\Services\Grievances\GrievancePresenter). Dates are ISO strings;
 * display them with <LocalizedDateDisplay> (Ethiopian in Amharic,
 * Gregorian in English). Enum values are lowercase snake_case strings;
 * label them with the `enum()` helper from Components/grievances/ui.
 */

export type Bilingual = { id?: string; name_en: string | null; name_am: string | null };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    path: string;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type EmployeeRef = { id: string; name: string | null; name_en: string | null; employee_number: string | null };

export type HandlerRef = {
    type: 'committee' | 'organization_unit' | 'external_authority' | 'organization' | '';
    id: string | null;
    name_en: string;
    name_am: string | null;
    organization_name_en: string | null;
    organization_name_am: string | null;
};

export type SlaSummary = {
    state: 'on_track' | 'due_soon' | 'due_today' | 'overdue' | 'paused' | 'stopped' | 'no_deadline';
    due_at: string | null;
    original_due_at: string | null;
    remaining_days: number | null;
    day_type: 'working_days' | 'calendar_days' | null;
    sla_days: number | null;
    paused_days: number;
    started_at: string | null;
};

/** One row in any case list. `subject`/`employee` are null when the viewer may not see details. */
export type GrievanceRow = {
    id: string;
    reference_number: string;
    subject: string | null;
    status: string;
    priority: string | null;
    confidentiality_level: string | null;
    category: Bilingual | null;
    organization: Bilingual | null;
    employee: EmployeeRef | null;
    handler: HandlerRef | null;
    stage_no: number | null;
    stage_status: string | null;
    sla: SlaSummary | null;
    submitted_at: string | null;
    created_at: string | null;
    appeal_deadline_at: string | null;
    record_state: string | null;
};

export type TimelineEvent = {
    id: string;
    event: string;
    visibility: 'complainant' | 'internal';
    data: Record<string, unknown>;
    occurred_at: string;
    actor: string | null;
    stage_id: string | null;
};

export type EvidenceItem = {
    id: string;
    evidence_type: string;
    title: string;
    description: string | null;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    classification: string;
    status: string;
    scan_status: string;
    version_no: number;
    supersedes_evidence_id: string | null;
    submitted_by_complainant: boolean;
    submitted_by: string | null;
    submitted_at: string;
    information_request_id: string | null;
    appeal_id: string | null;
};

// ── My Portal ────────────────────────────────────────────────────────────────

export type PortalIndexProps = {
    grievances: Paginated<GrievanceRow>;
    filters: { status?: string };
    statuses: string[];
    summary: { open: number; drafts: number; awaiting_response: number; decision_issued: number; next_appeal_deadline: string | null };
    can: { create: boolean };
};

export type CategoryOption = { id: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null };

export type PortalFormProps = {
    /** null when creating. */
    grievance: null | {
        id: string;
        reference_number: string;
        status: string;
        subject: string;
        description: string;
        category_id: string;
        incident_date: string | null;
        respondent_description: string | null;
        /** Set when intake returned the grievance for correction. */
        intake_reason_code: string | null;
        intake_notes: string | null;
    };
    categories: CategoryOption[];
    evidenceTypes: string[];
    allowedExtensions: string[];
    maxFileKb: number;
};

export type PortalShowProps = {
    grievance: GrievanceRow & {
        description: string;
        incident_date: string | null;
        respondent_description: string | null;
        accepted_at: string | null;
        resolved_at: string | null;
        closed_at: string | null;
        withdrawn_at: string | null;
        intake_reason_code: string | null;
        current_stage: null | { stage_no: number; handler: HandlerRef; status: string; received_at: string | null; due_at: string | null; sla_state: SlaSummary['state'] };
        timeline: TimelineEvent[];
        letters: { id: string; letter_type: string; reference_number: string | null; subject: string; letter_date: string | null; issued_at: string | null }[];
        evidence: EvidenceItem[];
        information_requests: { id: string; request_text: string; due_at: string | null; status: string; requested_at: string; responses: { response_text: string; responded_at: string }[] }[];
        appeals: { id: string; status: string; filed_at: string; reason: string }[];
    };
    reasonCodes: ReasonCode[];
    evidenceTypes: string[];
    can: { edit: boolean; delete: boolean; submit: boolean; withdraw: boolean; upload: boolean; appeal: boolean; accept_outcome: boolean };
    appeal: { allowed: boolean; reason: string | null; deadline: string | null };
};

export type ReasonCode = { code: string; name_en: string; name_am: string | null };

// ── Staff: dashboard and lists ───────────────────────────────────────────────

export type NavCan = {
    intake: boolean; authorized: boolean; approvals: boolean; reports: boolean; export: boolean;
    correspondence: boolean; committees: boolean; routes: boolean; sla: boolean; configuration: boolean;
};

export type DashboardProps = {
    assigned: {
        total: number; new: number; due_soon: number; overdue: number; awaiting_hearing: number;
        awaiting_decision: number; returned: number; escalated_in: number; pending_approval: number;
    };
    queue: GrievanceRow[];
    intakePending: number | null;
    approvalsPending: number | null;
    can: NavCan;
};

export type CasesIndexProps = {
    grievances: Paginated<GrievanceRow>;
    scope: 'assigned' | 'authorized' | 'intake';
    filters: { search?: string; status?: string; category_id?: string; organization_id?: string; handler_type?: string; sla?: string; from?: string; to?: string; confidentiality?: string; sort?: string };
    options: { statuses: string[]; categories: Bilingual[]; organizations: Bilingual[]; handlerTypes: string[]; slaStates: string[]; confidentiality: string[] };
    can: NavCan;
};

// ── Staff: case detail ───────────────────────────────────────────────────────

export type StageDetail = {
    id: string;
    stage_no: number;
    handler: HandlerRef;
    movement_type: string;
    movement_reason: string | null;
    status: string;
    is_current: boolean;
    received_at: string | null;
    review_started_at: string | null;
    completed_at: string | null;
    escalated_at: string | null;
    created_at: string;
    sla: SlaSummary;
    panel: { id: string; employee: EmployeeRef | null; role: 'chairperson' | 'writer' | 'member'; source: 'committee' | 'replacement'; is_active: boolean; recused_at: string | null; left_at: string | null }[];
    officers: { id: string; user_id: number; name: string | null; name_am: string | null; role: 'lead' | 'officer'; assigned_at: string; released_at: string | null }[];
};

export type DecisionDetail = {
    id: string; version_no: number; decision_no: string | null; decision_type: string | null; status: string;
    findings: string | null; facts_considered: string | null; legal_basis: string | null; analysis: string | null;
    decision_text: string; recommendations: string | null;
    corrective_action_required: boolean; disciplinary_referral_recommended: boolean; requires_executive_approval: boolean;
    quorum_met: boolean | null; stage_id: string; prepared_by: string | null;
    approver_position: { title_en: string; title_am: string | null } | null;
    approval_due_at: string | null; submitted_for_approval_at: string | null; approved_at: string | null;
    finalized_at: string | null; issued_at: string | null; created_at: string; supersedes_decision_id: string | null;
    /** Internal only (empty for oversight-only viewers). */
    approvals: { id: string; action: string; actor: string | null; comment: string | null; delegated: boolean; acted_at: string }[];
    votes: { employee: EmployeeRef | null; vote: string; opinion: string | null; voted_at: string }[];
};

export type LetterDetail = {
    id: string; letter_type: string; language: string; reference_number: string | null; subject: string;
    body: string | null; letter_date: string | null; status: string; signature_method: string | null;
    signatory: string | null; signatory_am: string | null; signed_at: string | null; sealed: boolean;
    issued_at: string | null; voided_at: string | null; void_reason: string | null; visible_to_complainant: boolean;
    has_pdf: boolean; pdf_sha256: string | null; organization_id: string | null; decision_id: string | null; supersedes_letter_id: string | null;
    recipients: { id: string; kind: 'to' | 'cc' | 'bcc_internal'; recipient_type: string; recipient_id: string | null; name: string; position_title: string | null; organization_name: string | null; address: string | null; email: string | null }[];
    attachments: { id: string; attachment_type: string; title: string; evidence_id: string | null; reference_id: string | null }[];
    dispatches: { id: string; channel: string; status: string; destination: string | null; sent_at: string | null; delivered_at: string | null; failed_at: string | null; failure_reason: string | null; acknowledged_at: string | null }[];
};

export type CaseShowProps = {
    grievance: GrievanceRow & {
        description: string | null;
        incident_date: string | null;
        respondent: null | { type: string | null; employee: EmployeeRef | null; unit: Bilingual | null; description: string | null };
        organization_unit: Bilingual | null;
        accepted_at: string | null; resolved_at: string | null; closed_at: string | null;
        closure_reason_code: string | null; withdrawal_reason_code: string | null; withdrawal_reason: string | null;
        intake_reason_code: string | null; intake_notes: string | null;
        legal_hold: boolean; legal_hold_reason: string | null; retention_until: string | null; archived_at: string | null;
        root_cause_category: string | null; systemic_issue_flag: boolean; corrective_action_required: boolean; reopened_count: number;
        current_stage: StageDetail | null;
        stages: StageDetail[];
        pauses: { id: string; reason: string; status: string; notes: string | null; requested_by: string | null; approved_by: string | null; requested_at: string; started_at: string | null; ended_at: string | null; due_at_before: string | null; due_at_after: string | null; paused_days: number | null }[];
    };
    /** Each key is present only when the viewer may see that section. */
    tabs: Partial<{
        timeline: TimelineEvent[];
        evidence: EvidenceItem[];
        information_requests: { id: string; requested_from_type: string; requested_from_id: string | null; requested_from_name: string | null; request_text: string; due_at: string | null; status: string; pauses_sla: boolean; requested_by: string | null; requested_at: string; responded_at: string | null; responses: { id: string; response_text: string; responded_by: string | null; responded_at: string }[] }[];
        hearings: { id: string; stage_id: string; scheduled_at: string; duration_minutes: number | null; location: string | null; mode: string; meeting_link: string | null; status: string; agenda: string | null; notes: string | null; cancellation_reason: string | null; held_at: string | null; chairperson: EmployeeRef | null; participants: { id: string; role: string; employee: EmployeeRef | null; name: string | null; affiliation: string | null; contact: string | null; attendance: string; notice_sent_at: string | null; acknowledged_at: string | null }[] }[];
        minutes: { id: string; hearing_id: string | null; stage_id: string; meeting_date: string; summary: string; discussion: string | null; resolutions: string | null; attendees: string[] | null; status: string; version_no: number; supersedes_minutes_id: string | null; amendment_reason: string | null; prepared_by: string | null; confirmed_by: string | null; confirmed_at: string | null }[];
        decisions: DecisionDetail[];
        letters: LetterDetail[];
        appeals: { id: string; status: string; reason: string; filed_at: string; deadline_at: string | null; from_stage_no: number | null; to_stage_no: number | null }[];
        notes: { id: string; body: string; author: string | null; created_at: string }[];
        tasks: { id: string; task_type: string; title: string; assignee: string | null; assigned_to_user_id: number | null; due_at: string | null; status: string; completed_at: string | null }[];
        recusals: { id: string; stage_id: string; employee: EmployeeRef | null; reason: string; status: string; declared_at: string; decided_by: string | null; decided_at: string | null; decision_notes: string | null; replacement: EmployeeRef | null }[];
        corrective_actions: { id: string; description: string; decision_id: string | null; due_date: string | null; status: string; responsible_organization: Bilingual | null; responsible_unit: Bilingual | null; completion_notes: string | null; completed_at: string | null }[];
        referrals: { id: string; reason: string; status: string; referred_at: string; organization: Bilingual | null; unit: Bilingual | null; disciplinary_case_reference: string | null }[];
        amendments: { id: string; changes: Record<string, { from: unknown; to: unknown }>; reason: string | null; by: string | null; at: string }[];
        audit: { id: string; event_type: string; actor: string | null; new_values: Record<string, unknown> | null; old_values: Record<string, unknown> | null; reason: string | null; created_at: string; request_ip: string | null }[];
    }>;
    /** Present (non-empty) only for current handlers and intake officers. */
    options: Partial<{
        routes: Record<'manual_escalation' | 'reassigned' | 'referred' | 'returned', { route_id: string; handler: HandlerRef }[]>;
        unit_officers: { id: number; name: string }[];
        reason_codes: { intake_return: ReasonCode[]; intake_rejection: ReasonCode[]; closure: ReasonCode[]; reopen: ReasonCode[]; reassignment: ReasonCode[] };
        decision_types: string[]; vote_types: string[]; voting_enabled: boolean; dissent_enabled: boolean;
        evidence_types: string[]; evidence_extensions: string[]; max_file_kb: number;
        information_targets: string[]; hearing_modes: string[]; participant_roles: string[]; pause_reasons: string[];
        task_types: string[]; letter_types: string[]; letter_languages: string[]; dispatch_channels: string[];
        seals: { id: string; name: string; organization_id: string }[];
        allow_intake_rejection: boolean; decision_statuses: string[];
    }>;
    viewer: { details: boolean; internal: boolean; handles: boolean; lead: boolean; panel_role: 'chairperson' | 'writer' | 'member' | null; pending_recusal: boolean; oversight_only: boolean };
    appeal: { open: boolean; deadline: string | null };
    can: {
        intake: boolean; receive: boolean; start_review: boolean; review: boolean; classify: boolean; escalate: boolean; reassign: boolean; refer: boolean;
        assign_officers: boolean; request_information: boolean; pause: boolean; decide_pause: boolean; declare_recusal: boolean; decide_recusal: boolean;
        manage_hearings: boolean; confirm_minutes: boolean; draft_decision: boolean; review_decision: boolean; submit_decision: boolean; finalize_decision: boolean;
        vote: boolean; prepare_letters: boolean; sign_letters: boolean; seal_letters: boolean; issue_letters: boolean; upload_evidence: boolean; notes: boolean;
        decide_withdrawal: boolean; close: boolean; reopen: boolean; archive: boolean; outcomes: boolean;
    };
};

// ── Staff: approvals, appeals, correspondence, reports ───────────────────────

export type ApprovalRow = {
    id: string; grievance_id: string; reference_number: string; category: Bilingual | null; organization: Bilingual | null;
    confidentiality_level: string | null; handler: HandlerRef | null; version_no: number; decision_type: string | null; status: string;
    submitted_for_approval_at: string | null; approval_due_at: string | null; overdue: boolean; can_act: boolean;
};
export type ApprovalsIndexProps = { decisions: Paginated<ApprovalRow>; tab: 'pending' | 'history'; counts: { pending: number } };

export type AppealRow = {
    id: string; grievance_id: string; reference_number: string | null; case_status: string | null; category: Bilingual | null;
    status: string; filed_at: string; deadline_at: string | null; from: HandlerRef | null; to: HandlerRef | null;
};
export type AppealsIndexProps = { appeals: Paginated<AppealRow>; filters: { status?: string } };

export type CorrespondenceRow = LetterDetail & { grievance_id: string; case_number: string | null };
export type CorrespondenceIndexProps = {
    letters: Paginated<CorrespondenceRow>;
    filters: { status?: string; letter_type?: string; search?: string };
    options: { statuses: string[]; types: string[] };
    seals: { id: string; name: string; organization_id: string }[];
    can: { seal: boolean; issue: boolean };
};

export type ReportResult = { columns: string[]; rows: Record<string, unknown>[]; suppressed: number; totals?: Record<string, number> };
export type ReportsIndexProps = {
    report: string;
    reports: string[];
    result: ReportResult;
    filters: { from: string | null; to: string | null; organization_id: string | null; category_id: string | null };
    minGroupSize: number;
    options: { organizations: Bilingual[]; categories: Bilingual[] };
    can: { export: boolean };
};

// ── Staff: configuration ─────────────────────────────────────────────────────

export type CommitteeRow = {
    id: string; name_en: string; name_am: string | null; committee_type: string; status: 'pending_approval' | 'active' | 'inactive';
    organization: Bilingual | null; organization_unit: Bilingual | null; effective_from: string | null; effective_to: string | null;
};
export type CommitteesIndexProps = {
    committees: Paginated<CommitteeRow & { active_members_count: number; open_cases_count: number; problems: string[] }>;
    filters: { organization_id?: string; committee_type?: string; status?: string; search?: string };
    options: { organizations: Bilingual[]; types: string[]; statuses: string[] };
    can: { create: boolean };
};
export type CommitteeShowProps = {
    committee: CommitteeRow & { description_en: string | null; description_am: string | null; approved_at: string | null; created_by: number | null };
    members: { id: string; employee: EmployeeRef | null; role: 'chairperson' | 'writer' | 'member'; effective_from: string | null; effective_to: string | null; status: string; is_active: boolean; appointment_reference: string | null; appointed_by: string | null; end_reason: string | null }[];
    problems: string[];
    openCases: number;
    policy: { min: number; max: number; require_writer: boolean };
    roles: string[];
    units: Bilingual[];
    can: { update: boolean; approve: boolean; manage_members: boolean };
};

export type RouteRow = {
    id: string; source: HandlerRef; include_descendants: boolean; target: HandlerRef; movement_type: string;
    category: Bilingual | null; sla_profile: Bilingual | null; priority: number; effective_from: string | null; effective_to: string | null;
    is_active: boolean; approved_at: string | null; approved_by: string | null; created_by: string | null; created_by_id: number | null;
    notes: string | null; cross_organization: boolean;
};
export type HandlerOptions = {
    movement_types: string[]; handler_types: string[]; organizations: Bilingual[];
    committees: (Bilingual & { organization_id: string; status: string; organization: Bilingual | null })[];
    external_authorities: Bilingual[]; categories: Bilingual[]; sla_profiles: Bilingual[];
};
export type RoutingIndexProps = {
    routes: Paginated<RouteRow>;
    filters: { movement_type?: string; source_handler_type?: string; target_handler_type?: string; state?: string };
    options: HandlerOptions;
    can: { manage: boolean; approve: boolean };
};

export type SlaProfileRow = {
    id: string; name_en: string; name_am: string | null; purpose: string; handler_type: string | null; handler_id: string | null; handler: HandlerRef | null;
    resolution_days: number; day_type: string; start_point: string; warning_thresholds: { percent?: number[]; days_remaining?: number[]; due_today?: boolean } | null;
    auto_escalate: boolean; priority: number; is_active: boolean; organization: Bilingual | null; category: Bilingual | null;
    effective_from: string | null; effective_to: string | null;
};
export type SlaIndexProps = {
    profiles: SlaProfileRow[];
    options: HandlerOptions & { purposes: string[]; day_types: string[]; start_points: string[] };
    can: { manage: boolean };
};

export type SettingField = {
    key: string; type: 'boolean' | 'integer' | 'string' | 'text' | 'select' | 'multiselect' | string; value: unknown;
    label_en: string; label_am: string; description_en: string | null; description_am: string | null; options: string[] | null;
};
export type SettingsIndexProps = {
    tab: string;
    /** From SystemSettingsService::getGroupForAdmin — inspect the shape at runtime; see Performance/Settings.tsx for rendering. */
    fields: unknown;
    categories: { id: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null; is_active: boolean; default_confidentiality: string | null; default_priority: string | null; requires_executive_approval: boolean; sort_order: number; grievances_count: number }[];
    reasonCodes: { id: string; type: string; code: string; name_en: string; name_am: string | null; is_active: boolean; sort_order: number }[];
    approvalRules: { id: string; name_en: string; name_am: string | null; handler_type: string | null; handler_id: string | null; handler: HandlerRef | null; decision_type: string | null; requires_approval: boolean; priority: number; is_active: boolean; organization_id: string | null; category_id: string | null; approver_position_id: string | null; approval_sla_profile_id: string | null; organization: Bilingual | null; category: Bilingual | null; approver_position: { id: string; title_en: string; title_am: string | null } | null; approval_sla_profile: Bilingual | null; effective_from: string | null; effective_to: string | null }[];
    externalAuthorities: { id: string; code: string; name_en: string; name_am: string | null; organization_id: string | null; is_administrative_tribunal: boolean; address: string | null; is_active: boolean; organization: Bilingual | null }[];
    templates: { id: string; name: string; subject_template: string; body_template: string; is_active: boolean; organization_id: string | null; template_type: string; language: string; organization: Bilingual | null; effective_from: string | null; effective_to: string | null }[];
    letterheads: { id: string; organization_id: string; header_line_en: string | null; header_line_am: string | null; address_en: string | null; address_am: string | null; po_box: string | null; phone: string | null; fax: string | null; email: string | null; website: string | null; footer_en: string | null; footer_am: string | null; organization: Bilingual | null }[];
    seals: { id: string; name: string; status: 'pending' | 'active' | 'retired'; mime_type: string; sha256: string; organization_id: string; uploaded_by: number | null; approved_by: number | null; organization: Bilingual | null; approved_at: string | null; effective_from: string | null; effective_to: string | null }[];
    delegations: { id: string; authority: string; status: string; reason: string | null; delegator: { id: number; name: string } | null; delegate: { id: number; name: string } | null; position: { id: string; title_en: string; title_am: string | null } | null; organization: Bilingual | null; starts_at: string; ends_at: string; revoked_at: string | null }[];
    options: {
        organizations: Bilingual[]; reason_code_types: string[]; confidentiality: string[]; priorities: string[]; decision_types: string[];
        handler_types: string[]; letter_types: string[]; letter_languages: string[]; tokens: string[]; approval_sla_profiles: Bilingual[];
    };
    can: { update: boolean; delegations: boolean; seals: boolean };
};
