/** The props of Court/Cases/Show (CaseController::show) and Court/Cases/Assign (CaseController::assignForm). */

export type CaseStatus = 'pending' | 'active' | 'adjourned' | 'dismissed' | 'closed' | string;
export type ReviewStatus = 'awaiting_review' | 'accepted' | 'returned' | 'rejected' | string;

export interface CaseDetailCase {
    id: number;
    case_number: string;
    title: string | null;
    status: CaseStatus;
    review_status: ReviewStatus;
    review_note: string | null;
    case_type: string | null;
    filing_date: string | null;
    created_at: string | null;
    updated_at: string | null;
    assigned_at: string | null;
    assigned_user_id: number | null;
    assignee_name: string | null;
    assignee_team_name: string | null;
    /** The case title doubles as the applicant's display name, as on the Blade page. */
    applicant_display_name: string;
    lawyer_name: string;
    submitted_by_lawyer: boolean;
    has_lawyer_document: boolean;
    applicant_address: string;
    applicant_email: string | null;
    respondent_name: string | null;
    respondent_address: string | null;
    /** Purified HTML (mews/purifier, the `cases` profile). */
    description_html: string;
    relief_html: string | null;
}

export interface CaseDetailPermissions {
    editStatus: boolean;
    assign: boolean;
    review: boolean;
    manageResponseReplies: boolean;
    writeLetter: boolean;
    viewLetters: boolean;
    manageBench: boolean;
    viewHearings: boolean;
    createHearings: boolean;
    updateHearings: boolean;
    deleteHearings: boolean;
    viewFiles: boolean;
    createFiles: boolean;
    updateFiles: boolean;
    deleteFiles: boolean;
    manageInspectionRequests: boolean;
    manageInspectionFindings: boolean;
    createMessage: boolean;
    isAdmin: boolean;
}

export interface CaseTimelineEntry {
    id: number;
    from_status: string | null;
    to_status: string | null;
    note: string | null;
    created_at: string | null;
}

export interface CaseHearing {
    id: number;
    hearing_at: string | null;
    type: string | null;
    location: string | null;
    notes: string | null;
    /** Today or past: the hearing can no longer be edited or removed. */
    date_locked: boolean;
}

export interface CaseFile {
    id: number;
    label: string | null;
    name: string;
    mime: string | null;
    size: number;
    created_at: string | null;
    uploaded_by: string | null;
}

export interface CaseMessage {
    id: number;
    body: string;
    from_admin: boolean;
    sender_name: string;
    created_at: string | null;
}

export interface CaseDocument {
    id: number;
    title: string | null;
    description_html: string | null;
    mime: string | null;
    size: number | null;
    created_at: string | null;
    has_file: boolean;
}

export interface CaseWitness {
    id: number;
    full_name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    notes: string | null;
}

export interface CaseAudit {
    id: number;
    action: string;
    actor_type: string | null;
    actor_id: number | string | null;
    actor_name: string | null;
    /** The recorded details as labelled lines in the reader's language (ids resolved to names). */
    details: Array<{ label: string; value: string; kind: 'text' | 'date' }>;
    /** Pretty-printed JSON, or null. */
    meta: string | null;
    created_at: string | null;
}

export interface CaseLetter {
    id: number;
    subject: string | null;
    reference_number: string | null;
    approval_status: string;
    template_title: string | null;
    author_name: string | null;
    created_at: string | null;
}

export interface LetterTemplateOption {
    id: number;
    title: string;
    body: string;
    category: string | null;
    placeholders: string[];
}

export interface RespondentResponseRow {
    id: number;
    title: string | null;
    case_number: string | null;
    response_number: string | null;
    description_html: string;
    review_status: ReviewStatus;
    review_note: string | null;
    pdf_url: string | null;
    created_at: string | null;
}

export interface ApplicantResponseReplyRow {
    id: number;
    respondent_response_id: number;
    response_number: string | null;
    applicant_name: string;
    description: string;
    review_status: ReviewStatus;
    review_note: string | null;
    pdf_url: string | null;
    created_at: string | null;
}

export interface InspectionRequestRow {
    id: number;
    subject: string | null;
    status: string;
    request_date: string | null;
    assigned_inspector_name: string | null;
    created_by_name: string | null;
}

export interface InspectionFindingRow {
    id: number;
    title: string | null;
    severity: string;
    finding_date: string | null;
    request_subject: string | null;
    assigned_inspector_name: string | null;
    recorded_by_name: string | null;
    accepted: boolean;
    has_attachment: boolean;
    attachment_name: string | null;
    details: string | null;
}

export interface EuisisPanelData {
    referral: {
        grievance_reference: string | null;
        tribunal_case_number: string | null;
        complainant_name: string | null;
        complainant_contact: string | null;
        referring_officer: string | null;
        received_at: string | null;
    } | null;
    organization: { name: string; code: string | null; active: boolean; status: string | null } | null;
    employee: { name: string; number: string | null; status: string | null; position: string | null; organization: string | null } | null;
    /** Set when the person is linked to an EUISIS employee, even before the directory cache has the record. */
    employee_uuid: string | null;
    has_applicant: boolean;
    can_link: boolean;
    can_link_employee: boolean;
    enabled: boolean;
    has_organizations: boolean;
    organizations: Array<{ uuid: string; name: string; active: boolean; status: string | null }>;
    selected_organization_uuid: string | null;
    last_sync: string | null;
    last_error: string | null;
    notice: string | null;
}

/** Flow 5: the grievances an employee attached when filing from the EUISIS employee portal. */
export interface EuisisGrievanceRecord {
    id: number;
    reference: string | null;
    subject: string;
    category: string | null;
    status: string | null;
    description: string | null;
    submitted_at: string | null;
    closed_at: string | null;
    responses: Array<{ body: string; at: string | null }>;
    decisions: Array<{ number: string | null; type: string | null; text: string; at: string | null }>;
}

export interface EuisisRecordData {
    filed_from_euisis: boolean;
    grievances: EuisisGrievanceRecord[];
}

/** A lawyer on a party's side (case_representatives). */
export interface CasePartyRepresentative {
    id: number;
    name: string;
    license_number: string | null;
    phone: string | null;
    email: string | null;
    status: 'pending' | 'accepted' | 'refused' | 'revoked';
    confirmed_by_party: boolean;
    power_of_attorney_url: string | null;
    note: string | null;
    verified_at: string | null;
}

/** A party on the applicant's side (case_applicants): the applicant, a co-applicant or an intervener. */
export interface CaseParty {
    id: number;
    name: string;
    role: 'lead' | 'co_applicant' | 'intervener';
    status: 'active' | 'invited' | 'declined' | 'requested' | 'admitted' | 'refused' | 'removed';
    source: string;
    linked: boolean;
    statement: string | null;
    attachment_url: string | null;
    decision_note: string | null;
    decided_at: string | null;
    decided_by: string | null;
    merged_from: string | null;
    created_at: string | null;
    representatives: CasePartyRepresentative[];
}

/** The "Parties and representation" section (Admin\CasePartiesController::props). */
export interface CaseParties {
    rows: CaseParty[];
    can: { decide: boolean; verify: boolean; merge: boolean };
    mergedInto: { id: number; case_number: string; note: string | null; at: string | null } | null;
    mergedFrom: { id: number; case_number: string; at: string | null }[];
    candidates: { id: number; case_number: string; title: string; filed_at: string | null; status: string }[];
    /** The court's register of verified lawyers, for "Record a lawyer" (registry staff only; empty otherwise). */
    lawyers: { id: number; name: string; license_number: string }[];
}

export interface CaseDetailProps {
    case: CaseDetailCase;
    /** The applicant's side: parties, lawyers and merges (EUISIS ADR 0013 #13). */
    parties: CaseParties;
    can: CaseDetailPermissions;
    /** Closed with a published decision: every action is locked. */
    caseLocked: boolean;
    statuses: string[];
    currentUserInitial: string;
    termsItems: string[];
    nextLetterReference: string | null;
    timeline: CaseTimelineEntry[];
    hearings: CaseHearing[];
    files: CaseFile[];
    messages: CaseMessage[];
    docs: CaseDocument[];
    witnesses: CaseWitness[];
    audits: CaseAudit[];
    letters: CaseLetter[];
    letterTemplates: LetterTemplateOption[];
    respondentResponses: RespondentResponseRow[];
    applicantResponseReplies: ApplicantResponseReplyRow[];
    inspectionAssignees: Array<{ id: number; name: string }>;
    inspectionRequests: InspectionRequestRow[];
    inspectionFindings: InspectionFindingRow[];
    euisis: EuisisPanelData | null;
    euisisRecord: EuisisRecordData | null;
    errors?: Record<string, string> & { euisis?: Record<string, string> };
    [key: string]: unknown;
}

/** The sections of the case page; one is open at a time and the URL hash names it. */
export type CaseSection =
    | 'case-summary'
    | 'case-details'
    | 'timeline'
    | 'letters'
    | 'letters-compose'
    | 'audits'
    | 'inspection-requests'
    | 'inspection-findings'
    | 'uploaded-files'
    | 'hearings'
    | 'messages'
    | 'respondent-responses'
    | 'response-of-response'
    | 'parties';

export interface AssignPageCase {
    id: number;
    case_number: string;
    title: string | null;
    status: string;
    assigned_user_id: number | null;
    assignee_name: string | null;
    assignee_email: string | null;
    assigned_at: string | null;
}

export interface AssignPageProps {
    case: AssignPageCase;
    assignmentMode: 'leader' | 'admin';
    canAssignLeaders: boolean;
    leaderTeam: { id: number; name: string; members: Array<{ id: number; name: string; email: string }> } | null;
    teams: Array<{ id: number; name: string; leader: { id: number; name: string; email: string } }>;
    [key: string]: unknown;
}
