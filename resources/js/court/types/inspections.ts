import type { LaravelPageMeta } from '@/Components/ui';

/** A case to choose from (id, number, title). */
export interface InspectionCaseOption {
    id: number;
    case_number: string;
    title: string | null;
}

/** One inspection request as the list and the show page read it (Admin\CaseInspectionRequestController). */
export interface InspectionRequestRow {
    id: number;
    case_id: number | null;
    case_number: string | null;
    case_title: string | null;
    subject: string | null;
    /** ISO date. */
    request_date: string | null;
    status: string;
    assigned_inspector_user_id: number | null;
    assigned_inspector_name: string | null;
    created_by_name: string | null;
}

/** Which of the two inspection lists the viewer may open. */
export interface InspectionNav {
    requests: boolean;
    findings: boolean;
}

export interface InspectionRequestsIndexProps {
    requests: LaravelPageMeta & { data: InspectionRequestRow[] };
    cases: InspectionCaseOption[];
    filters: { case_id: string; status: string };
    statuses: string[];
    nav: InspectionNav;
}

export interface InspectionRequestFormProps {
    /** Null on the create page. */
    request: (InspectionRequestRow & { request_note: string | null }) | null;
    cases: InspectionCaseOption[];
    /** Only when the viewer may assign inspectors. */
    inspectors: { id: number; name: string }[];
    canAssign: boolean;
    prefill: { case_id: string; assigned_inspector_user_id: string };
    statuses: string[];
    /** ISO date, the default request date. */
    today: string;
}

export interface InspectionRequestFindingSummary {
    id: number;
    title: string | null;
    /** ISO date. */
    finding_date: string | null;
    severity: string;
    recorded_by_name: string | null;
}

export interface InspectionRequestShowProps {
    request: InspectionRequestRow & {
        request_note: string | null;
        requested_by_name: string | null;
        findings: InspectionRequestFindingSummary[];
    };
    /** A completed request is locked. */
    canEdit: boolean;
}

/** An inspection request to record a finding against. */
export interface InspectionRequestOption {
    id: number;
    case_number: string | null;
    subject: string | null;
    /** ISO date. */
    request_date: string | null;
}

/** One finding as the list, the show page and the form read it (Admin\CaseInspectionFindingController). */
export interface InspectionFindingRow {
    id: number;
    request_id: number;
    request_subject: string | null;
    case_id: number | null;
    case_number: string | null;
    title: string | null;
    /** ISO date. */
    finding_date: string | null;
    severity: string;
    accepted: boolean;
    has_attachment: boolean;
    attachment_name: string | null;
}

export interface InspectionFindingsIndexProps {
    findings: LaravelPageMeta & { data: InspectionFindingRow[] };
    requests: InspectionRequestOption[];
    filters: { request_id: string; case_id: string; severity: string };
    severities: string[];
    nav: InspectionNav;
}

export interface InspectionFindingFormProps {
    /** Null on the create page. */
    finding: (InspectionFindingRow & { details: string | null }) | null;
    requests: InspectionRequestOption[];
    prefill: { request_id: string };
    severities: string[];
    /** ISO date, the default finding date. */
    today: string;
}

export interface InspectionFindingShowProps {
    finding: InspectionFindingRow & {
        details: string | null;
        recorded_by_name: string | null;
        /** ISO 8601. */
        accepted_at: string | null;
        accepted_by_name: string | null;
    };
    /** An accepted finding is locked. */
    canEdit: boolean;
    /** An admin may accept a finding that is not yet accepted. */
    canAccept: boolean;
}
