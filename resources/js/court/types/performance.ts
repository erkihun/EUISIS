import type { LaravelPageMeta } from '@/Components/ui';

/** draft → submitted → reviewed (a reviewed evaluation is locked). */
export type EvaluationStatus = 'draft' | 'submitted' | 'reviewed';
export type EvaluationPeriodType = 'monthly' | 'quarterly' | 'annual';

/** A person in a picker (active users, by name). */
export interface PerformanceUserOption {
    id: number;
    name: string;
}

/** A criteria category in a picker: its slug and its name in the current language. */
export interface PerformanceCategoryOption {
    slug: string;
    name: string;
}

/** One evaluation in the register (Admin\PerformanceEvaluationController::index). */
export interface PerformanceEvaluationRow {
    id: number;
    member_name: string | null;
    member_avatar_url: string | null;
    period_type: EvaluationPeriodType;
    /** Y-m-d. */
    period_start: string | null;
    /** Y-m-d. */
    period_end: string | null;
    /** 0-100, weighted from the criterion scores. */
    overall_score: number;
    status: EvaluationStatus;
    evaluator_name: string | null;
    /** ISO 8601. */
    created_at: string | null;
}

export interface PerformanceIndexProps {
    evaluations: LaravelPageMeta & { data: PerformanceEvaluationRow[] };
    /** The member filter's options. */
    users: PerformanceUserOption[];
    /** The Add Criterion dialog's categories (shown with settings.manage). */
    categories: PerformanceCategoryOption[];
    stats: { total: number; draft: number; submitted: number; reviewed: number; avg_score: number };
    filters: { status: string; user_id: string; period_type: string };
    can: { create: boolean; update: boolean; manageSettings: boolean };
    /** This page's URL, where the Add Criterion / Add Category dialogs come back to. */
    returnUrl: string;
}

/** A criterion to score on the evaluation form (the active ones, in their order). */
export interface PerformanceCriterionOption {
    id: number;
    /** In the current language. */
    name: string;
    category: string | null;
    category_label: string | null;
    weight: number;
    description: string | null;
    /** appeal_outcomes: worked out from the appellate court's outcomes, never typed in (EUISIS ADR 0013 #14). */
    source?: string | null;
}

/** A criterion's saved score (edit form). */
export interface PerformanceScoreValue {
    criterion_id: number;
    score: number;
    comment: string | null;
}

export interface PerformanceFormProps {
    /** Null on the create page. */
    evaluation: {
        id: number;
        evaluated_user_id: number;
        member_name: string | null;
        period_type: EvaluationPeriodType;
        /** Y-m-d. */
        period_start: string | null;
        /** Y-m-d. */
        period_end: string | null;
        notes: string | null;
        status: EvaluationStatus;
    } | null;
    users: PerformanceUserOption[];
    criteria: PerformanceCriterionOption[];
    /** The saved scores (edit only; empty on create). */
    scores: PerformanceScoreValue[];
    can: { delete: boolean };
}

/** One criterion's score on the evaluation page. */
export interface PerformanceScoreRow {
    id: number;
    criterion_name: string | null;
    category_label: string | null;
    weight: number | null;
    /** 0-10. */
    score: number;
    comment: string | null;
}

export interface PerformanceShowProps {
    evaluation: {
        id: number;
        member_name: string | null;
        member_avatar_url: string | null;
        period_type: EvaluationPeriodType;
        /** Y-m-d. */
        period_start: string | null;
        /** Y-m-d. */
        period_end: string | null;
        status: EvaluationStatus;
        overall_score: number;
        evaluator_name: string | null;
        reviewer_name: string | null;
        notes: string | null;
        reviewer_notes: string | null;
        /** ISO 8601. */
        created_at: string | null;
        /** ISO 8601. */
        reviewed_at: string | null;
    };
    scores: PerformanceScoreRow[];
    can: { update: boolean; review: boolean };
}

/** One criterion on the settings page (Admin\PerformanceEvaluationSettingController::index). */
export interface PerformanceCriterionRow {
    id: number;
    name: string;
    name_am: string | null;
    category: string;
    category_label: string;
    weight: number;
    sort_order: number;
    active: boolean;
    description: string | null;
    /** Evaluation scores already given against it (a used criterion is deactivated, not deleted). */
    scores_count: number;
}

export interface PerformanceSettingsProps {
    criteria: PerformanceCriterionRow[];
    categories: PerformanceCategoryOption[];
    /** The sum of the active criteria's weights (100 is balanced). */
    totalActiveWeight: number;
    /** This page's URL, where the Add Criterion / Add Category dialogs come back to. */
    returnUrl: string;
}

export interface PerformanceCreateCriterionProps {
    categories: PerformanceCategoryOption[];
}

/** The Add Category page takes no data. */
export type PerformanceCreateCategoryProps = Record<string, never>;
