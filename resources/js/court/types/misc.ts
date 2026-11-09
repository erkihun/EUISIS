import type { LaravelPageMeta } from '@/Components/ui';

/** One appeal (Admin\AppealController::index). */
export interface AppealRow {
    id: number;
    appeal_number: string;
    case_number: string;
    title: string | null;
    status: string;
    decided_by: string | null;
    created_at: string | null;
}

export interface AppealsIndexProps {
    appeals: LaravelPageMeta & { data: AppealRow[] };
    filters: { status: string };
    canCreate: boolean;
}

/** One case type (Admin\CaseTypeController::index). */
export interface CaseTypeRow {
    id: number;
    name: string;
    prefix: string | null;
    cases_count: number;
}

export interface CaseTypesIndexProps {
    types: LaravelPageMeta & { data: CaseTypeRow[] };
    filters: { q: string };
}

/** The notifications page (Admin\AdminNotificationController::index): four lists, each paged on its own. */
export interface NotificationsIndexProps {
    messages: LaravelPageMeta & { data: { id: number; case_id: number; case_number: string; body: string; at: string | null }[] };
    cases: LaravelPageMeta & { data: { id: number; case_number: string; title: string | null; at: string | null }[] };
    hearings: LaravelPageMeta & { data: { id: number; case_id: number; case_number: string; type: string | null; location: string | null; at: string | null }[] };
    respondentViews: LaravelPageMeta & { data: { id: number; case_id: number; case_number: string; respondent_name: string | null; at: string | null }[] };
}
