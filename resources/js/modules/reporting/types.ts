/* Shapes the Reporting pages receive from the server (Modules/Reporting/Http/Support/Present.php). */

export interface Bilingual {
    name_en: string | null;
    name_am: string | null;
}

export interface OrganizationRef extends Bilingual {
    id: string;
    code: string | null;
}

/** A work unit inside an institution. */
export interface UnitRef extends Bilingual {
    id: string;
    code: string | null;
    organization_id?: string;
}

export interface Labelled {
    label_en: string | null;
    label_am: string | null;
}

export type Workflow = 'not_started' | 'draft' | 'submitted' | 'under_review' | 'returned' | 'approved' | 'rejected';
export type Timeliness = 'not_due' | 'early' | 'on_time' | 'late' | 'overdue';
export type Applicability = 'required' | 'exempted' | 'cancelled';

export interface ObligationRow {
    id: string;
    organization: OrganizationRef;
    /** Set when the report is a work unit's own, inside that institution. */
    unit?: UnitRef | null;
    is_personal: boolean;
    report_type: Bilingual & { id?: string; confidentiality?: string | null };
    period: Labelled & { start?: string | null; end?: string | null };
    receiving?: { id: string | null } & Bilingual;
    opens_at?: string;
    original_due_at: string;
    effective_due_at: string;
    deadline_extended: boolean;
    revision_due_at?: string | null;
    review_due_at: string | null;
    first_submitted_at: string | null;
    latest_submitted_at?: string | null;
    workflow: Workflow;
    applicability: Applicability;
    timeliness: Timeliness | null;
    timeliness_original: Timeliness | null;
    days_overdue: number;
    review_overdue: boolean;
    version: number;
    preparer_name: string | null;
    accountable_name: string | null;
    reviewer_name?: string | null;
    latest_submission_id?: string | null;
    hierarchy?: OrganizationRef[];
}

export interface ComplianceSummary {
    as_of: string;
    counts: Record<string, number>;
    rates: { submission_rate: number | null; on_time_rate: number | null; approval_rate: number | null };
    follow_ups: { open: number; overdue: number };
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

export interface TemplateSection {
    key: string;
    title_en: string;
    title_am: string;
    guidance_en?: string | null;
    guidance_am?: string | null;
    is_required: boolean;
}

export interface ReviewStepRow {
    id: string;
    step_no: number;
    name_en: string;
    name_am: string;
    required_permission: string;
    status: 'waiting' | 'pending' | 'decided' | 'skipped';
    decision: 'approve' | 'return' | 'reject' | null;
    reason: string | null;
    reviewer_user_id: number | null;
    reviewer_name?: string | null;
    due_at: string | null;
    decided_by_name: string | null;
    decided_at: string | null;
}

export interface AttachmentRow {
    id: string;
    kind: 'report_document' | 'annex' | 'evidence';
    requirement_key: string | null;
    name: string;
    size: number;
    version_no: number;
    scan_status: string;
    submitted: boolean;
    url: string;
}

export interface ArtifactRow {
    id: string;
    format: 'docx' | 'pdf';
    label: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    size: number | null;
    attempts: number;
    completed_at: string | null;
    url: string | null;
}

export interface SubmissionRow {
    id: string;
    version_no: number;
    reference_no: string;
    mode: 'structured' | 'upload';
    status: string;
    is_nil: boolean;
    is_late: boolean;
    late_reason: string | null;
    submitted_by_name: string;
    submitted_at: string;
    decided_at: string | null;
    content_hash: string;
    sections?: (TemplateSection & { html: string })[];
    summary?: string | null;
    nil_reason?: string | null;
    completeness_declared?: boolean;
    steps?: ReviewStepRow[];
    attachments?: AttachmentRow[];
    artifacts?: ArtifactRow[];
}

export interface CommentRow {
    id: string;
    section_key: string | null;
    visibility: 'submitter' | 'internal';
    body: string;
    author_name: string;
    created_at: string;
    submission_id: string | null;
}

export interface ExtensionRow {
    id: string;
    obligation_id: string;
    status: 'pending' | 'approved' | 'rejected';
    reason: string;
    requested_due_at: string;
    requested_by_name: string;
    requested_at: string;
    decided_by_name: string | null;
    decided_at: string | null;
    decision_reason: string | null;
    previous_due_at: string | null;
    approved_due_at: string | null;
    is_retrospective: boolean;
    obligation?: ObligationRow;
}

export interface FollowUpRow {
    id: string;
    obligation_id: string;
    title: string;
    description: string;
    owner_name: string | null;
    owner_user_id: number | null;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: 'open' | 'in_progress' | 'done' | 'closed' | 'cancelled';
    progress: number;
    due_at: string;
    overdue: boolean;
    created_by_name: string;
    created_at: string | null;
    closed_at: string | null;
    closure_note: string | null;
    updates?: { id: string; progress: number; status: string; note: string; author_name: string; created_at: string; evidence_name: string | null; evidence_url: string | null }[];
    obligation?: ObligationRow | null;
}

export interface FilterOptions {
    report_types: ({ id: string; is_active: boolean } & Bilingual)[];
    cycles: ({ id: string; report_type_id: string; due_at: string } & Labelled)[];
}

export interface PersonRef {
    id: number;
    name: string;
    email: string | null;
}
