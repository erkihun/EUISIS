/** EmployeeSelfServiceResource: whitelisted, masked, no ids or hashes. */
export type SelfServiceProfile = {
    full_name: string | null;
    name_en: string | null;
    employee_number: string | null;
    status: string | null;
    photo_url: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    preferred_language: string | null;
    notification_preferences: { email: boolean } | null;
    date_of_birth: string | null;
    gender: string | null;
    nationality: string | null;
    national_id: string | null;
    employment_type: string | null;
    employment_type_am: string | null;
    organization: string | null;
    organization_am: string | null;
    organization_unit: string | null;
    organization_unit_am: string | null;
    position: string | null;
    position_am: string | null;
    job_grade: string | null;
    effective_from: string | null;
};

/** Per field: self-service access class and whether the current card prints it. */
export type FieldPolicy = Record<string, { access: string; card_visible: boolean; editable?: boolean; verification_required?: boolean }>;

export type SelfServiceCard = {
    card_number: string;
    status: string;
    issued_at: string | null;
    expires_at: string | null;
    reprint_required: boolean;
    changed_fields: string[];
    changed_field_keys?: string[];
    snapshot_available: boolean;
};
