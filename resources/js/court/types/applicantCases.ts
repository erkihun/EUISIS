import type { LaravelPageMeta } from '@/Components/ui';

/*
 * The props of the applicant's case pages (Applicant\ApplicantCaseController)
 * and of the replies to respondent responses (Applicant\ApplicantResponseReplyController).
 * Dates are ISO 8601: a calendar date (YYYY-MM-DD) or a timestamp.
 */

export type ApplicantCaseStatus = 'pending' | 'active' | 'adjourned' | 'dismissed' | 'closed' | string;
export type ApplicantReviewStatus = 'awaiting_review' | 'accepted' | 'returned' | 'rejected' | string;

export interface CaseTypeOption {
    id: number;
    name: string;
    /** The case number prefix source (falls back to the name). */
    prefix: string | null;
}

/* ---------- Court/Applicant/Cases/Index ---------- */

export interface ApplicantCaseRow {
    id: number;
    case_number: string;
    title: string | null;
    case_type: string | null;
    status: ApplicantCaseStatus;
    review_status: ApplicantReviewStatus;
    review_note: string | null;
    /** YYYY-MM-DD. */
    filing_date: string | null;
    /** Pending and not yet approved by the reviewer. */
    can_delete: boolean;
}

export interface ApplicantCaseIndexProps {
    cases: LaravelPageMeta & { data: ApplicantCaseRow[] };
}

/* ---------- Court/Applicant/Cases/Create ---------- */

export interface ApplicantCaseCreateProps {
    types: CaseTypeOption[];
    /** The published terms the applicant must accept; null when none are published. */
    terms: { id: number; body_html: string } | null;
    /** A lawyer types the applicant's name and address; anyone else files in their own name. */
    applicant: { is_lawyer: boolean; name: string; address: string };
    /** The EUISIS city directory (optional respondent organization). */
    organizations: { uuid: string; name: string }[];
    /** YYYY-MM-DD. */
    today: string;
}

/* ---------- Court/Applicant/Cases/Edit ---------- */

export interface ApplicantCaseWitness {
    id: number;
    full_name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
}

export interface ApplicantCaseEditProps {
    case: {
        id: number;
        case_number: string;
        status: ApplicantCaseStatus;
        review_status: ApplicantReviewStatus;
        title: string;
        /** YYYY-MM-DD. */
        filing_date: string | null;
        case_type_id: number | null;
        respondent_name: string;
        respondent_address: string;
        /** Purified HTML. */
        description: string;
        relief_requested: string;
    };
    /** Pending and not yet approved: the controller redirects otherwise. */
    editable: boolean;
    types: CaseTypeOption[];
    docs: { id: number; title: string | null; has_file: boolean }[];
    witnesses: ApplicantCaseWitness[];
    /** The last two digits of the year, for the case number format hint. */
    yearSuffix: string;
}

/* ---------- Court/Applicant/Cases/Show ---------- */

export interface ApplicantCaseDetail {
    id: number;
    case_number: string;
    /** The access code a respondent uses to find the case. */
    code: string | null;
    title: string | null;
    status: ApplicantCaseStatus;
    review_status: ApplicantReviewStatus;
    review_note: string | null;
    case_type: string | null;
    /** Filing date, or the creation time when the filing date is missing. */
    filed_at: string | null;
    respondent_name: string | null;
    respondent_address: string | null;
    /** Purified HTML (mews/purifier, the `cases` profile). */
    description_html: string;
    relief_html: string | null;
}

export interface ApplicantCaseDocument {
    id: number;
    title: string | null;
    mime: string | null;
    size: number | null;
    created_at: string | null;
    description_html: string | null;
    has_file: boolean;
}

export interface ApplicantCaseHearing {
    id: number;
    hearing_at: string | null;
    type: string | null;
    location: string | null;
    notes: string | null;
}

export interface ApplicantCaseLetter {
    id: number;
    subject: string | null;
    reference_number: string | null;
    approval_status: string;
    template_title: string | null;
    author_name: string | null;
    created_at: string | null;
    /** A signed preview URL. */
    preview_url: string;
}

export interface ApplicantCaseRespondentResponse {
    id: number;
    title: string | null;
    case_number: string;
    response_number: string | null;
    created_at: string | null;
    /** Plain text, at most 180 characters. */
    excerpt: string | null;
    has_pdf: boolean;
}

export interface ApplicantCaseMessage {
    id: number;
    body: string;
    /** Sent by the applicant. */
    mine: boolean;
    /** The court sender's name; null for the applicant or an unnamed court sender. */
    sender_name: string | null;
    created_at: string | null;
}

export interface ApplicantCaseFile {
    id: number;
    name: string;
    mime: string | null;
    size: number;
    created_at: string | null;
    /** Uploaded by this applicant. */
    can_delete: boolean;
}

export interface ApplicantCaseAudit {
    id: number;
    action: string;
    actor_type: string | null;
    actor_id: number | null;
    actor_name: string | null;
    /** Pretty-printed JSON. */
    meta: string | null;
    created_at: string | null;
}

export interface ApplicantCaseShowProps {
    case: ApplicantCaseDetail;
    applicant: { name: string; address: string };
    docs: ApplicantCaseDocument[];
    witnesses: ApplicantCaseWitness[];
    hearings: ApplicantCaseHearing[];
    letters: ApplicantCaseLetter[];
    respondentResponses: ApplicantCaseRespondentResponse[];
    messages: ApplicantCaseMessage[];
    files: ApplicantCaseFile[];
    audits: ApplicantCaseAudit[];
}

/* ---------- Court/Applicant/Cases/RespondentResponse ---------- */

export interface RespondentResponseSummary {
    id: number;
    response_number: string | null;
    respondent_name: string | null;
    has_pdf: boolean;
}

export interface RespondentResponseDetail extends RespondentResponseSummary {
    title: string | null;
    case_number: string;
    respondent_email: string | null;
    respondent_phone: string | null;
    respondent_org: string | null;
    created_at: string | null;
    summary: string | null;
    /** Purified HTML. */
    description_html: string;
    pdf_name: string | null;
}

export interface ResponseReplyListItem {
    id: number;
    review_status: ApplicantReviewStatus;
    review_note: string | null;
    excerpt: string;
    created_at: string | null;
    /** Not yet accepted: may still be changed or removed. */
    editable: boolean;
}

export interface CaseRef {
    id: number;
    case_number: string;
}

export interface ApplicantRespondentResponseProps {
    case: CaseRef;
    response: RespondentResponseDetail;
    replies: ResponseReplyListItem[];
}

/* ---------- Court/Applicant/Replies/* ---------- */

export interface ApplicantReplyRow {
    id: number;
    case_id: number;
    respondent_response_id: number;
    case_number: string;
    response_number: string | null;
    review_status: ApplicantReviewStatus;
    created_at: string | null;
    editable: boolean;
}

export interface ApplicantReplyIndexProps {
    replies: LaravelPageMeta & { data: ApplicantReplyRow[] };
}

export interface ApplicantReplyCreateProps {
    case: CaseRef;
    response: RespondentResponseSummary;
    applicantName: string;
}

export interface ApplicantReply {
    id: number;
    /** Plain text. */
    description: string;
    review_status: ApplicantReviewStatus;
    review_note: string | null;
    created_at: string | null;
    applicant_name: string;
    editable: boolean;
    has_pdf: boolean;
}

export interface ApplicantReplyShowProps {
    case: CaseRef;
    response: RespondentResponseSummary;
    reply: ApplicantReply;
}

export type ApplicantReplyEditProps = ApplicantReplyShowProps;

/** The printable filing receipt (ApplicantCaseController::receipt). */
export interface ApplicantCaseReceiptProps {
    receipt: {
        id: number;
        case_number: string;
        code: string | null;
        status: string;
        title: string;
        filed_at: string | null;
        case_type: string | null;
        court_name: string | null;
        applicant: { name: string; email: string | null; phone: string | null };
        respondent: { name: string | null; address: string | null } | null;
        /** Cleaned on the server. */
        description_html: string;
        relief_html: string | null;
    };
    witnesses: { id: number; name: string; phone: string | null; email: string | null }[];
    documents: { id: number; title: string; url: string | null }[];
    files: { id: number; label: string; extension: string; uploaded_at: string | null }[];
    hearings: { id: number; at: string | null; type: string | null; location: string | null; ics_url: string }[];
    generatedAt: string;
}
