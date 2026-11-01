import type { LaravelPageMeta } from '@/Components/ui';

/** One hearing in the hearings list (Admin\HearingController::index). */
export interface HearingRow {
    id: number;
    case_id: number | null;
    case_number: string | null;
    case_title: string | null;
    type: string | null;
    location: string | null;
    notes: string | null;
    /** ISO 8601. */
    hearing_at: string | null;
    creator_name: string | null;
}

export interface HearingsIndexProps {
    hearings: LaravelPageMeta & { data: HearingRow[] };
    filters: { case_number: string; hearing_date: string };
}
