import type { Named, Paginated } from '@/Components/dailyActivity/types';

export type { Named, Paginated };

export type FieldWorkStatus =
    | 'draft'
    | 'pending_supervisor_approval'
    | 'returned_for_correction'
    | 'approved'
    | 'rejected'
    | 'in_field'
    | 'completed'
    | 'cancelled';

export type MonitoringFlag = 'overdue' | 'check_in_missing';

export type DestinationType = 'registered_organization' | 'external_organization' | 'field_site' | 'other_location';

export type LocationValidation = 'within_expected_area' | 'outside_expected_area' | 'low_accuracy' | 'cannot_validate';

export type EmployeeRef = { id: string; employee_number: string | null; full_name: string | null; name_en: string | null };

export type FieldWorkSummary = {
    id: string;
    reference_number: string;
    status: FieldWorkStatus;
    monitoring_flag: MonitoringFlag | null;
    supervisor_resolution: 'resolved' | 'supervisor_not_resolved' | null;
    type: Named;
    requester: EmployeeRef | null;
    organization: Named;
    organization_unit: Named;
    position: Named;
    destination_type: DestinationType;
    destination: { name_en: string | null; name_am: string | null };
    starts_at: string;
    expected_return_at: string;
    actual_return_at: string | null;
    schedule_type: 'partial_day' | 'full_day' | 'multi_day';
    is_team: boolean;
    participants_count: number | null;
    submitted_at: string | null;
};

/** Coordinates are present only for viewers with the precise-location permission. */
export type LocationEvent = {
    id: string;
    event_type: 'field_check_in' | 'field_check_out';
    validation_status: LocationValidation;
    captured_at: string;
    received_at: string;
    latitude?: number;
    longitude?: number;
    accuracy_m?: number | null;
    distance_m?: number | null;
};

export type Participant = {
    id: string;
    role: 'lead' | 'member';
    employee: EmployeeRef | null;
    checked_in_at: string | null;
    checked_out_at: string | null;
    events: LocationEvent[];
};

export type FieldWorkDetail = FieldWorkSummary & {
    purpose: string;
    activity_description: string | null;
    destination_details: {
        organization: (Named & { id: string }) | null;
        organization_unit: (Named & { id: string }) | null;
        external_organization_name: string | null;
        site_name: string | null;
        address: string | null;
        contact_person: string | null;
        contact_phone: string | null;
        has_geofence: boolean;
        expected_point: { latitude: number; longitude: number; radius_m: number } | null;
    };
    supervisor: { name: string } | null;
    decision: { by: string | null; at: string | null; reason: string | null } | null;
    completion: {
        at: string | null;
        actual_start_at: string | null;
        actual_return_at: string | null;
        note: string | null;
        outcome: string | null;
        follow_up_required: boolean;
        follow_up_note: string | null;
    } | null;
    cancel_reason: string | null;
    participants: Participant[];
    history: { action: string; from_status: string | null; to_status: string | null; actor: string | null; comment: string | null; at: string | null }[];
    location_visibility: 'precise' | 'status' | 'none';
};

export type Option = { id: string; name_en: string; name_am: string | null };

export type FilterOptions = {
    types: Option[];
    statuses: FieldWorkStatus[];
    destination_types: DestinationType[];
    organizations: Option[];
    units: Option[];
};

export type ManagementAbilities = {
    dashboard: boolean;
    requests: boolean;
    approvals: boolean;
    team: boolean;
    overdue: boolean;
    types: boolean;
    oversight: boolean;
};
