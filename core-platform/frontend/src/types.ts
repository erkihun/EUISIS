// Shapes the Core API returns. Only the fields the screens use.

export type OrgRef = { id: string; code: string; name_en: string; name_am?: string | null };
export type PositionRef = { id: string; job_position_code: string; title_en: string };

export type Organization = OrgRef & {
    status: string;
    effective_from: string | null;
    type: { id: string; code: string; name_en: string; name_am: string | null } | null;
};

export type Unit = OrgRef & { organization_id: string; status: string; organization: OrgRef | null };

export type Position = PositionRef & {
    title_am: string | null;
    is_active: boolean;
    holders_count: number;
    organization: OrgRef | null;
    unit: { id: string; name_en: string } | null;
};

export type Assignment = {
    id: string;
    employee_id: string;
    organization_id: string;
    position_id: string | null;
    assignment_status: string;
    is_current: boolean;
    effective_from: string | null;
    effective_to: string | null;
    reason?: string | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
    organization?: OrgRef | null;
    position?: PositionRef | null;
};

export type Employee = {
    id: string;
    employee_number: string;
    full_name: string;
    gender: string | null;
    status: string;
    has_active_card?: boolean;
    current_assignment?: Assignment | null;
};

export type IdCard = {
    id: string;
    employee_id: string;
    card_number: string;
    status: string;
    is_current: boolean;
    issued_at: string | null;
    expires_at: string | null;
    revoked_at?: string | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
};

export type AuditEntry = {
    id: string;
    actor_user_id: number | null;
    action: string;
    subject_type: string;
    subject_id: string;
    organization_id: string | null;
    reason: string | null;
    request_id: string | null;
    created_at: string;
};
