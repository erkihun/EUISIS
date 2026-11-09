import type { LaravelPageMeta } from '@/Components/ui';

/** One row of the decisions list (Admin\DecisionController::index). */
export interface DecisionRow {
    id: number;
    case_id: number | null;
    case_number: string | null;
    /** ISO date. */
    case_filed_date: string | null;
    applicant: string | null;
    respondent: string | null;
    judge: string | null;
    /** ISO date. */
    decision_date: string | null;
    status: string | null;
    approved: boolean;
    /** The middle judge may edit and delete a decision that is not published. */
    can_edit: boolean;
    can_delete: boolean;
}

export interface DecisionsIndexProps {
    decisions: LaravelPageMeta & { data: DecisionRow[] };
    filters: { q: string };
    canCreate: boolean;
}

/** A case the decision form offers (closed cases on create; the decision's own case is always included on edit). */
export interface DecisionCaseOption {
    id: number;
    case_number: string;
    title: string | null;
    /** The applicant's name: the case title, else the applicant record's full name. */
    applicant: string;
    respondent: string;
}

export interface DecisionJudgeOption {
    id: number;
    name: string;
}

/** The values of an existing decision on the edit form (Admin\DecisionController::edit). */
export interface DecisionFormValues {
    id: number;
    name: string | null;
    case_id: number | null;
    case_file_number: string | null;
    applicant_full_name: string | null;
    respondent_full_name: string | null;
    /** ISO date. */
    decision_date: string | null;
    decision_content: string | null;
    /** The three panel seats in storage order (0 first, 1 middle = the author, 2 third); null for an empty seat. */
    judges: Array<number | null>;
}

/** Props of Court/Decisions/Create and Court/Decisions/Edit (Admin\DecisionController::create / edit). */
export interface DecisionFormProps {
    /** Null on the create page. */
    decision: DecisionFormValues | null;
    cases: DecisionCaseOption[];
    judgeUsers: DecisionJudgeOption[];
    /** The signed-in judge: always the middle seat. */
    currentUser: { id: number | null; name: string | null };
    defaults: {
        /** Today, ISO date. */
        decision_date: string;
        /** `?case_id=` on the create page. */
        case_id: number | null;
        judges: Array<number | null>;
    };
}

/** One seat of the judicial panel as the show page draws it (author first). */
export interface DecisionPanelSeat {
    number: number;
    name: string | null;
    /** approve | reject | improve, or null while pending. */
    vote: string | null;
}

export interface DecisionDetail {
    id: number;
    name: string | null;
    case_id: number | null;
    case_number: string | null;
    case_file_number: string | null;
    /** ISO date. */
    decision_date: string | null;
    applicant_full_name: string | null;
    respondent_full_name: string | null;
    status: string;
    approved: boolean;
    /** ISO date-time. */
    approved_at: string | null;
    approver_name: string | null;
    /** Sanitized HTML (or legacy plain text). */
    decision_content: string | null;
    panel: DecisionPanelSeat[];
    /** Three distinct judges hold the seats; publishing and approving need it. */
    panel_complete: boolean;
}

export interface DecisionReviewRow {
    id: number;
    reviewer_name: string | null;
    /** approve | reject | improve | pending. */
    outcome: string;
    review_note: string | null;
    /** ISO date-time. */
    updated_at: string | null;
    /** Whether the signed-in user wrote it (and so may update or delete it). */
    is_owner: boolean;
}

export interface DecisionTemplateOption {
    id: number;
    title: string;
}

/** Props of Court/Decisions/Show (Admin\DecisionController::show). */
export interface DecisionShowProps {
    decision: DecisionDetail;
    reviews: DecisionReviewRow[];
    templates: DecisionTemplateOption[];
    statuses: string[];
    can: {
        /** The middle judge, while the decision is not published. */
        edit: boolean;
        /** decision.update: change the status (until published) and review. */
        changeStatus: boolean;
        /** decision.approve: approve once published. */
        approve: boolean;
        review: boolean;
    };
    errors?: Record<string, string>;
}

/** Props of Court/Decisions/EditReview (Admin\DecisionController::editReview). */
export interface DecisionEditReviewProps {
    decision: { id: number; case_number: string | null };
    review: {
        id: number;
        outcome: string;
        review_note: string;
        reviewer_name: string | null;
    };
}

/** One row of the decision templates register (Admin\DecisionTemplateController::index). */
export interface DecisionTemplateRow {
    id: number;
    title: string;
    category: string | null;
    is_default: boolean;
    /** The placeholder tokens, comma-joined; null when none. */
    placeholders: string | null;
    /** ISO date-time. */
    updated_at: string | null;
}

export interface DecisionTemplatesIndexProps {
    templates: LaravelPageMeta & { data: DecisionTemplateRow[] };
    can: { create: boolean; update: boolean; delete: boolean };
}

/** The values of a template on its form (Admin\DecisionTemplateController::edit). */
export interface DecisionTemplateFormValues {
    id: number;
    title: string;
    category: string;
    is_default: boolean;
    /** Comma-joined tokens. */
    placeholders: string;
    body: string;
    header_image_name: string | null;
    header_image_url: string | null;
    footer_image_name: string | null;
    footer_image_url: string | null;
}

/** Props of Court/DecisionTemplates/Create and Edit. */
export interface DecisionTemplateFormProps {
    /** Null on the create page. */
    template: DecisionTemplateFormValues | null;
}
