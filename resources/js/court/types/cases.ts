import type { LaravelPageMeta } from '@/Components/ui';

/** One row of the cases list (Admin\CaseController::index). */
export interface CaseListRow {
    id: number;
    case_number: string;
    title: string | null;
    applicant_name: string | null;
    applicant_is_lawyer: boolean;
    case_type: string | null;
    team_name: string | null;
    assignee_name: string | null;
    status: string;
    /** ISO date. */
    filing_date: string | null;
    /** What this viewer may see: a non-reviewer always sees `accepted`. */
    review_status: string;
    reviewer_name: string | null;
}

export interface CaseListFilters {
    q: string;
    status: string;
    case_type_id: string;
    assignee_id: string;
    /** ISO dates. */
    from: string;
    to: string;
}

export interface CaseListProps {
    cases: LaravelPageMeta & { data: CaseListRow[] };
    filters: CaseListFilters;
    statuses: string[];
    types: { id: number; name: string }[];
    users: { id: number; name: string }[];
    isReviewer: boolean;
    canExport: boolean;
}
