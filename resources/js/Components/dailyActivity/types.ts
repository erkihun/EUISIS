export type Named = { name_en: string; name_am: string | null } | null;

export type LogStatus = 'draft' | 'submitted' | 'under_review' | 'returned_for_correction' | 'resubmitted' | 'approved';

export type DayStatus =
    | 'required' | 'draft' | 'submitted' | 'approved' | 'returned' | 'missing'
    | 'leave' | 'public_holiday' | 'weekend' | 'not_employed' | 'not_assigned' | 'future' | 'not_tracked';

export type ActivityItem = {
    id?: string;
    activity_category: string | null;
    position_service_id: string | null;
    /** EPMS: the employee's own KPI item this activity is evidence for. */
    employee_performance_item_id?: string | null;
    position_service?: Named;
    title: string;
    description: string | null;
    output_result: string | null;
    progress_status: string;
    started_at: string | null;
    ended_at: string | null;
    duration_minutes: number | null;
    quantity: string | number | null;
    unit_of_measure: string | null;
    challenge_issue: string | null;
    next_action: string | null;
    reviewer_note?: string | null;
};

export type Attachment = {
    id: string;
    item_id: string | null;
    original_name: string;
    mime_type: string;
    file_size: number;
    uploaded_at: string | null;
    download_url: string;
};

export type HistoryEntry = {
    id: string;
    action: string;
    from_status: string | null;
    to_status: string | null;
    actor: string | null;
    comment: string | null;
    created_at: string | null;
    items: ActivityItem[] | null;
};

export type EmployeeRef = { id: string; employee_number: string; full_name: string; name_en: string | null } | null;

export type LogSummary = {
    id: string;
    activity_date: string;
    status: LogStatus;
    is_late: boolean;
    submitted_at: string | null;
    reviewed_at: string | null;
    reviewer: string | null;
    items_count: number;
    employee: EmployeeRef;
    organization: Named;
    organization_unit: Named;
    position: Named;
};

export type LogDetail = LogSummary & {
    late_reason: string | null;
    review_comment: string | null;
    first_submitted_at: string | null;
    submission_count: number;
    submitted_by: string | null;
    reopened_at: string | null;
    reopened_by: string | null;
    reopen_reason: string | null;
    items: ActivityItem[];
    attachments: Attachment[];
    history: HistoryEntry[];
};

export type PaginationMetaData = {
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    per_page?: number;
};

export type PageLink = { url: string | null; label: string; active: boolean };

export type Paginated<T> = { data: T[]; meta: PaginationMetaData; links: PageLink[] };

export type Option = { id: string; name_en: string; name_am: string | null };

export type FilterOptions = {
    organizations: Option[];
    units: Option[];
    positions: Option[];
    services: Option[];
    statuses?: string[];
};

export type ManagementAbilities = {
    viewRegister: boolean;
    review: boolean;
    viewReports: boolean;
    export: boolean;
    manageSettings: boolean;
};
