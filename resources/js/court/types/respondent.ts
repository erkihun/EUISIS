import type { LaravelPageMeta } from '@/Components/ui';

// The respondent portal's pages (App\Http\Controllers\Respondent\*). Dates are ISO 8601 strings.

/** Respondent\DashboardController::index. */
export interface RespondentDashboardProps {
    stats: { cases: number; responses: number; response_replies: number; notifications: number };
    /** The five cases opened last. */
    recentCases: { case_number: string; title: string | null; status: string | null; viewed_at: string | null; show_url: string }[];
    /** The five newest approved letters of those cases; `preview_url` is the signed public preview. */
    letters: {
        id: number; subject: string | null; reference_number: string | null; created_at: string | null;
        case_number: string | null; case_title: string | null; template_title: string | null; author_name: string | null; preview_url: string;
    }[];
    /** The five newest accepted applicant replies to this respondent's responses. */
    responseReplies: { id: number; case_number: string | null; response_number: string | null; applicant_name: string; created_at: string | null }[];
    /** What the Blade header bell listed: the unseen count and the eight newest, each marked read by type + source id. */
    notifications: { count: number; items: RespondentNotification[] };
}

export interface RespondentNotification {
    /** respondent_view, hearing, status or message. */
    type: string;
    source_id: number;
    at: string | null;
    case_number: string | null;
    title: string | null;
    show_url: string | null;
}

/** Respondent\CaseSearchController::index. */
export interface RespondentCaseSearchProps {
    caseNumber: string;
    /** Set when the number and access code matched a case the person may answer. */
    case: { case_number: string; title: string | null; status: string | null; created_at: string | null; show_url: string } | null;
    /** The case exists but the access code did not match. */
    codeError: boolean;
}

/** Respondent\CaseSearchController::myCases. */
export interface RespondentMyCasesProps {
    cases: { id: number; case_number: string; status: string; case_type: string | null; viewed_at: string | null; show_url: string }[];
}

export interface RespondentCaseDocument {
    id: number;
    title: string | null;
    mime: string | null;
    /** Bytes. */
    size: number | null;
    created_at: string | null;
    description_html: string | null;
    download_url: string | null;
}

export interface RespondentCaseHearing { id: number; hearing_at: string | null; type: string | null; location: string | null; notes: string | null }

export interface RespondentCaseLetter {
    id: number; subject: string | null; reference_number: string | null; approval_status: string; created_at: string | null;
    template_title: string | null; author_name: string | null; preview_url: string;
}

export interface RespondentCaseWitness { full_name: string; phone: string | null; email: string | null; address: string | null }

export interface RespondentCaseAudit { created_at: string | null; action: string; actor_name: string | null; actor_type: string | null; actor_id: number | null; meta: Record<string, unknown> | null }

/** Respondent\CaseSearchController::show. */
export interface RespondentCaseShowProps {
    case: {
        id: number; case_number: string; status: string; review_status: string; review_note: string | null;
        case_type: string | null; filed_at: string | null; applicant_name: string | null; applicant_email: string | null;
        respondent_name: string | null; respondent_address: string | null;
        /** Cleaned on the server (Purifier `cases`), cleaned again by RichTextViewer. */
        description_html: string | null; relief_requested_html: string | null;
    };
    docs: RespondentCaseDocument[];
    hearings: RespondentCaseHearing[];
    letters: RespondentCaseLetter[];
    /** Published, approved decisions only. */
    decisions: { id: number; name: string | null; date: string | null; download_url: string }[];
    witnesses: RespondentCaseWitness[];
    audits: RespondentCaseAudit[];
}

/** One response of the respondent (Respondent\ResponseController::present). */
export interface RespondentResponseItem {
    id: number;
    title: string;
    case_number: string | null;
    response_number: string | null;
    review_status: string;
    review_note: string | null;
    /** Accepted responses can no longer be edited or deleted. */
    locked: boolean;
    has_pdf: boolean;
    created_at: string | null;
    /** Show and edit only. */
    description?: string | null;
}

export interface RespondentResponsesIndexProps { responses: RespondentResponseItem[] }

export interface RespondentResponseShowProps { response: RespondentResponseItem }

export interface RespondentResponseFormProps {
    /** Null on the create page. */
    response: RespondentResponseItem | null;
    /** From the case page's link on create (read only there), the response's own on edit. */
    caseNumber: string;
}

export interface RespondentReplyRow { id: number; case_number: string; response_number: string | null; applicant_name: string; created_at: string | null }

/** Respondent\ResponseReplyController::index. */
export interface RespondentRepliesIndexProps { replies: LaravelPageMeta & { data: RespondentReplyRow[] } }

/** Respondent\ResponseReplyController::show. */
export interface RespondentReplyShowProps {
    reply: { id: number; case_number: string; response_number: string | null; applicant_name: string; description: string | null };
}

export interface RespondentSession {
    id: string;
    ip_address: string | null;
    device_name: string;
    browser_name: string;
    is_current: boolean;
    last_active_at: string;
}

export type RespondentProfileTab = 'profile' | 'security' | 'devices';

/** Respondent\RespondentProfilePage::render (respondent.profile.edit and respondent.profile.sessions.index). */
export interface RespondentProfileProps {
    user: {
        first_name: string; middle_name: string; last_name: string; gender: string | null; phone: string;
        national_id_number: string; email: string; address: string; position: string; organization_name: string;
        is_lawyer: boolean; lawyer_document_url: string | null;
    };
    tab: RespondentProfileTab;
    /** The account's signed-in sessions; only on the sessions route. */
    sessions: RespondentSession[] | null;
    /** False unless sessions are kept in the database. */
    sessionsSupported: boolean;
    status: string | null;
    securitySuccess: string | null;
}
