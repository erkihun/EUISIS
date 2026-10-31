import type { LaravelPageMeta } from '@/Components/ui';

/** One row of the case records register (Admin\RecordController::index). */
export interface RecordListRow {
    id: number;
    case_number: string;
    title: string | null;
    applicant_name: string | null;
    case_type: string | null;
    team_name: string | null;
    assignee_name: string | null;
    status: string;
    /** ISO date. */
    filing_date: string | null;
    /** What this viewer may see: a non-reviewer always sees `accepted`. */
    review_status: string;
    reviewer_name: string | null;
    decisions_count: number;
    /** The appeal record exists once the case has a decision or is closed/dismissed. */
    has_appeal_record: boolean;
    /** ISO 8601: at the appellate court since (EUISIS ADR 0013 #14). */
    appealed_at: string | null;
    appeal_outcome: AppealOutcome | null;
    /** Requests to appeal waiting for the registry. */
    appeal_requests: number;
}

export type AppealOutcome = 'confirmed' | 'modified' | 'reversed';

export interface RecordListFilters {
    q: string;
    status: string;
    case_type_id: string;
    assignee_id: string;
    /** ISO dates. */
    from: string;
    to: string;
    /** appealed, requested or none. */
    appeal: string;
}

export interface RecordsIndexProps {
    cases: LaravelPageMeta & { data: RecordListRow[] };
    filters: RecordListFilters;
    statuses: string[];
    types: { id: number; name: string }[];
    users: { id: number; name: string }[];
    isReviewer: boolean;
}

export interface RecordDocument {
    id: number | null;
    label: string | null;
    path: string | null;
    /** ISO 8601. */
    created_at: string | null;
}

/** The case record page (Admin\RecordController::show): the case and every section of its file. */
export interface RecordShowProps {
    case: {
        id: number;
        case_number: string | null;
        title: string | null;
        status: string | null;
        case_type: string | null;
        /** ISO dates. */
        filing_date: string | null;
        first_hearing_date: string | null;
        applicant_name: string | null;
        applicant_email: string | null;
        applicant_phone: string | null;
        respondent_name: string | null;
        respondent_address: string | null;
        /** Sanitized HTML. */
        description: string | null;
        relief_requested: string | null;
    };
    /** ISO 8601, when the page was generated. */
    generatedAt: string;
    benchNotes: { id: number; title: string | null; note: string | null; author: string | null; created_at: string | null }[];
    submittedDocuments: RecordDocument[];
    otherDocuments: RecordDocument[];
    witnesses: { id: number; full_name: string | null; phone: string | null; email: string | null; address: string | null }[];
    respondentResponses: {
        id: number;
        title: string | null;
        case_number: string | null;
        /** Plain text, shortened. */
        description: string | null;
        pdf_path: string | null;
        download_url: string | null;
        created_at: string | null;
    }[];
    letters: {
        id: number;
        subject: string | null;
        reference_number: string | null;
        approval_status: string | null;
        author_name: string | null;
        /** Sanitized HTML. */
        body: string | null;
        created_at: string | null;
    }[];
    hearings: { id: number; hearing_at: string | null; type: string | null; location: string | null; judge_notes: string | null; notes: string | null }[];
    decision: { id: number; title: string | null; content: string | null; created_at: string | null } | null;
    evidences: { id: number; title: string | null; description: string | null; created_at: string | null }[];
    /** Case files plus evidence items, the count the "Other documents" header shows. */
    filesCount: number;
    hasAppealRecord: boolean;
    /** The EUISIS proceeding the case started from: a grievance or a discipline case. */
    origins: RecordOrigin[];
    /** The parties on the applicant's side, with their lawyers (EUISIS ADR 0013 #13). */
    parties: { id: number; name: string; role: 'lead' | 'co_applicant' | 'intervener'; status: string; lawyers: { name: string; license_number: string | null }[] }[];
    /** The applicant's replies the court accepted; sanitized HTML. */
    replies: { id: number; response_id: number; description: string | null; created_at: string | null }[];
    appeal: RecordAppealPanel;
}

export interface RecordOrigin {
    id: number;
    kind: 'grievance' | 'discipline';
    reference: string | null;
    subject: string;
    category: string | null;
    status: string | null;
    /** Plain text. */
    description: string | null;
    submitted_at: string | null;
    closed_at: string | null;
    responses: { body?: string; at?: string }[];
    decisions: { number?: string; type?: string; text?: string; at?: string }[];
}

export interface RecordAppeal {
    id: number;
    appeal_number: string;
    status: 'draft' | 'submitted' | 'under_review' | 'approved' | 'rejected' | 'closed';
    side: 'applicant' | 'respondent' | null;
    appellant: string | null;
    via: string | null;
    grounds: string | null;
    note: string | null;
    requested_at: string | null;
    released_at: string | null;
    released_by: string | null;
    decided_at: string | null;
    decided_by: string | null;
    outcome: AppealOutcome | null;
    /** Y-m-d. */
    outcome_date: string | null;
    appellate_court: string | null;
    appellate_case_number: string | null;
    outcome_notes: string | null;
    outcome_document_url: string | null;
}

/** The appeal to the appellate court (EUISIS ADR 0013 #14). */
export interface RecordAppealPanel {
    /** Closed with a published decision: a party may appeal. */
    appealable: boolean;
    appealedAt: string | null;
    outcome: AppealOutcome | null;
    rows: RecordAppeal[];
    can: { request: boolean; release: boolean; outcome: boolean };
}

/** The record's sections, in the order of the proceedings (as the appeal record PDF). */
export type RecordSection = 'origin' | 'case' | 'pleadings' | 'hearings' | 'witnesses-letters' | 'decision' | 'appeal' | 'documents';
