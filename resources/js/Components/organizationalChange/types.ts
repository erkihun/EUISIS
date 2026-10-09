/** Shared shapes for the organizational change request screens. */

export type NamedRef = { id: number; name: string };

export type OrganizationRef = {
    id: string;
    name_en: string;
    name_am: string | null;
    code?: string | null;
};

export type UnitRef = {
    id: string;
    name_en: string;
    name_am: string | null;
};

export type ChangeRequestListMeta = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};

export type ChangeRequestItem = {
    id: string;
    entity_type: string;
    entity_id: string | null;
    action: string;
    before_data: Record<string, unknown> | null;
    proposed_data: Record<string, unknown>;
    validation_snapshot: Record<string, unknown> | null;
    resulting_entity_id: string | null;
};

export type ChangeRequestReview = {
    id: string;
    action: string;
    stage: string;
    comment: string | null;
    revision: number;
    reviewed_at: string | null;
    reviewer: NamedRef | null;
};

export type ChangeRequestAttachment = {
    id: string;
    document_type: string;
    reference_no: string | null;
    document_date: string | null;
    original_name: string;
    file_size: number | null;
    uploaded_at: string | null;
    uploader: NamedRef | null;
};

export type ChangeRequestHistoryEntry = {
    id: string;
    action: string;
    from_status: string | null;
    to_status: string | null;
    comment: string | null;
    revision: number;
    created_at: string | null;
    actor: NamedRef | null;
};

export type ChangeRequestSummary = {
    id: string;
    request_no: string;
    status: string;
    request_type: string;
    category: string;
    priority: string;
    reason: string;
    requested_effective_date: string | null;
    revision: number;
    organization?: OrganizationRef | null;
    requester?: NamedRef | null;
    implementation_assignee?: NamedRef | null;
};

export type ChangeRequestDetail = ChangeRequestSummary & {
    reviewer?: NamedRef | null;
    approver?: NamedRef | null;
    implementer?: NamedRef | null;
    implementing_unit?: UnitRef | null;
    implementing_unit_key: string | null;
    submitted_at: string | null;
    review_started_at: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    implementation_assigned_at: string | null;
    implementation_started_at: string | null;
    implemented_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    blocked_at: string | null;
    decision_comment: string | null;
    implementation_note: string | null;
    blocked_reasons: Conflict[] | null;
    implementation_result: Record<string, unknown> | null;
    items: ChangeRequestItem[];
    reviews: ChangeRequestReview[];
    attachments: ChangeRequestAttachment[];
    history: ChangeRequestHistoryEntry[];
};

export type Conflict = {
    code: string;
    context: Record<string, unknown>;
};

export type ImpactPayload = Record<string, unknown> & {
    kind?: string;
    generated_at?: string;
};

export type ChangeRequestAbilities = {
    update?: boolean;
    submit?: boolean;
    resubmit?: boolean;
    cancel?: boolean;
    review?: boolean;
    requestCorrection?: boolean;
    approve?: boolean;
    reject?: boolean;
    assignImplementation?: boolean;
    implement?: boolean;
    complete?: boolean;
    returnForAmendment?: boolean;
    uploadAttachment?: boolean;
};

export type QueueAbilities = {
    create: boolean;
    review: boolean;
    approve: boolean;
    viewApproved: boolean;
    implement: boolean;
    assignImplementation: boolean;
};

export type FilterOptions = {
    statuses: string[];
    requestTypes: string[];
    organizations: OrganizationRef[];
};

export type FormOptions = {
    organizations: OrganizationRef[];
    unitTypes: { id: string; name_en: string; name_am: string | null; code: string | null }[];
    occupations: { id: string; name_en: string; name_am: string | null }[];
    units: {
        id: string;
        organization_id: string;
        parent_unit_id: string | null;
        name_en: string;
        name_am: string | null;
        code: string | null;
        status: string;
    }[];
    positions: {
        id: string;
        organization_id: string;
        organization_unit_id: string | null;
        title_en: string;
        title_am: string | null;
        job_position_code: string | null;
        grade_level: string | null;
        is_active: boolean;
    }[];
    attachmentTypes: string[];
};
