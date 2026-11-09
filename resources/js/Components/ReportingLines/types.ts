/** Shapes the reporting-line pages receive (ADR 0025); mirrors ReportingLinePresenter. */

export type SupervisorResolution = {
    outcome: string;
    outcome_label: string;
    is_resolved: boolean;
    is_legitimate_end: boolean;
    is_problem: boolean;
    employee_id: string;
    as_of: string | null;
    assignment_id: string | null;
    subject_position_id: string | null;
    subject_unit_id: string | null;
    organization_id: string | null;
    basis: string | null;
    basis_label: string | null;
    supervisor_position_id: string | null;
    supervisor_position_title_en: string | null;
    supervisor_position_title_am: string | null;
    supervisor_unit_id: string | null;
    supervisor_unit_name_en: string | null;
    supervisor_unit_name_am: string | null;
    supervisor_organization_id: string | null;
    occupant_basis: string | null;
    occupant_basis_label: string | null;
    supervisor_employee_id: string | null;
    supervisor_employee_number: string | null;
    supervisor_name: string | null;
    supervisor_name_am: string | null;
    acting_appointment_id: string | null;
    explanation: string;
    explanation_text: string;
    path: string[];
};

export type EmployeeRow = {
    id: string;
    employee_number: string | null;
    full_name: string | null;
    name_am: string | null;
    status: string;
    position_title_en: string | null;
    position_title_am: string | null;
    position_id: string | null;
    is_unit_head: boolean;
    unit_id: string | null;
    unit_name_en: string | null;
    unit_name_am: string | null;
    organization_id: string | null;
    organization_name_en: string | null;
    organization_name_am: string | null;
};

export type PageMeta = { current_page: number; last_page: number; total: number; per_page: number };

export type EmployeePage = { data: EmployeeRow[]; meta: PageMeta };

export type ActingAppointmentRow = {
    id: string;
    organization_id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    position_id: string;
    position_title_en: string | null;
    position_title_am: string | null;
    unit_name_en: string | null;
    unit_name_am: string | null;
    employee_id: string;
    employee_number: string | null;
    employee_name: string | null;
    employee_name_am: string | null;
    starts_on: string | null;
    ends_on: string | null;
    reason: string | null;
    status: string;
    status_label: string;
    in_force: boolean;
    authorized_by: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    created_at: string | null;
};

export type DelegationRow = {
    id: string;
    organization_id: string;
    organization_name_en: string | null;
    organization_name_am: string | null;
    delegator_employee_id: string;
    delegator_number: string | null;
    delegator_name: string | null;
    delegator_name_am: string | null;
    delegate_employee_id: string;
    delegate_number: string | null;
    delegate_name: string | null;
    delegate_name_am: string | null;
    actions: string[];
    action_labels: string[];
    starts_on: string | null;
    ends_on: string | null;
    reason: string | null;
    status: string;
    status_label: string;
    in_force: boolean;
    authorized_by: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    created_at: string | null;
};

export type ActionOption = { value: string; label: string };

export type LookupEmployee = {
    id: string;
    employee_number: string | null;
    full_name: string | null;
    name_am: string | null;
    position_title_en: string | null;
    position_title_am: string | null;
    unit_name_en: string | null;
    unit_name_am: string | null;
};

export type LookupPosition = {
    id: string;
    title_en: string | null;
    title_am: string | null;
    code: string | null;
    is_unit_head: boolean;
    unit_name_en: string | null;
    unit_name_am: string | null;
};
