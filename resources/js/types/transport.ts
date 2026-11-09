/**
 * Transport page contracts, mirroring app/Http/Resources/Transport*Resource
 * and the payloads of App\Services\Transport\TransportReportService and the
 * settlement services.
 */

export type ScopeType = 'self' | 'subtree' | 'citywide';
export type VehicleType = 'bus' | 'minibus' | 'van' | 'car' | 'shuttle' | 'other';
export type VehicleStatus = 'active' | 'maintenance' | 'inactive' | 'suspended';
export type DriverStatus = 'active' | 'inactive' | 'suspended';
export type TripStatus = 'planned' | 'assigned' | 'boarding' | 'in_progress' | 'completed' | 'cancelled' | 'failed';
export type Direction = 'inbound' | 'outbound';
export type PassStatus = 'active' | 'suspended' | 'expired' | 'cancelled';
export type ProviderStatus = 'active' | 'inactive' | 'suspended';
export type TransactionStatus = 'accepted' | 'rejected';
export type VerificationMethod = 'qr' | 'nfc' | 'manual_exception';
export type SyncState = 'online' | 'offline_pending' | 'offline_reconciled' | 'offline_rejected';
export type ContractStatus = 'draft' | 'active' | 'superseded' | 'ended' | 'cancelled';
export type SettlementModel = 'per_verified_boarding' | 'per_employee_per_day' | 'per_completed_trip' | 'fixed_periodic' | 'fixed_plus_usage' | 'minimum_guarantee_plus_usage' | 'capacity_based' | 'other_approved_model';
export type SettlementPeriod = 'daily' | 'weekly' | 'monthly';
export type InvoiceStatus = 'draft' | 'submitted' | 'under_review' | 'returned' | 'disputed' | 'approved' | 'withdrawn';
export type SettlementStatus = 'draft' | 'under_review' | 'approved' | 'paid' | 'rejected' | 'cancelled';
export type DeviceStatus = 'active' | 'suspended' | 'revoked';
export type EligibilityStatus = 'active' | 'suspended' | 'ended';
export type ExceptionStatus = 'open' | 'reviewed' | 'dismissed';

export const SCOPE_TYPES: ScopeType[] = ['self', 'subtree', 'citywide'];
export const VEHICLE_TYPES: VehicleType[] = ['bus', 'minibus', 'van', 'car', 'shuttle', 'other'];
export const VEHICLE_STATUSES: VehicleStatus[] = ['active', 'maintenance', 'inactive', 'suspended'];
export const DRIVER_STATUSES: DriverStatus[] = ['active', 'inactive', 'suspended'];
export const TRIP_STATUSES: TripStatus[] = ['planned', 'assigned', 'boarding', 'in_progress', 'completed', 'cancelled', 'failed'];
export const DIRECTIONS: Direction[] = ['inbound', 'outbound'];
export const PASS_STATUSES: PassStatus[] = ['active', 'suspended', 'expired', 'cancelled'];
export const PROVIDER_STATUSES: ProviderStatus[] = ['active', 'inactive', 'suspended'];
export const SETTLEMENT_MODELS: SettlementModel[] = ['per_verified_boarding', 'per_employee_per_day', 'per_completed_trip', 'fixed_periodic', 'fixed_plus_usage', 'minimum_guarantee_plus_usage', 'capacity_based', 'other_approved_model'];
export const SETTLEMENT_PERIODS: SettlementPeriod[] = ['daily', 'weekly', 'monthly'];
export const ELIGIBILITY_STATUSES: EligibilityStatus[] = ['active', 'suspended', 'ended'];
export const DISPUTE_TYPES = ['missing_transaction', 'trip_cancellation', 'device_outage', 'vehicle_substitution', 'other'] as const;

export type Named = { name_en: string | null; name_am: string | null };

export type ProviderOption = { id: string; provider_code: string } & Named;

export type OrganizationOption = { id: string; code?: string | null } & Named;

export type RouteRef = { id: string; route_code: string } & Named;

export type TransportRoute = {
    id: string;
    provider_id: string;
    route_code: string;
    origin_en: string | null;
    origin_am: string | null;
    destination_en: string | null;
    destination_am: string | null;
    distance_km: string | number | null;
    estimated_duration_minutes: number | null;
    assigned_organization_id: string | null;
    assigned_scope_type: ScopeType;
    is_active: boolean;
    notes: string | null;
    assigned_organization?: OrganizationOption | null;
    provider?: ProviderOption | null;
    stops?: TransportRouteStop[];
} & Named;

export type TransportRouteStop = {
    id: string;
    transport_route_id: string;
    stop_code: string;
    latitude: string | number | null;
    longitude: string | number | null;
    sequence: number;
    pickup_allowed: boolean;
    dropoff_allowed: boolean;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
} & Named;

export type TransportVehicle = {
    id: string;
    provider_id: string;
    vehicle_code: string;
    plate_number: string;
    vehicle_type: VehicleType | null;
    capacity: number | null;
    standing_capacity?: number | null;
    status: VehicleStatus;
    assigned_route_id: string | null;
    model: string | null;
    year: number | null;
    color: string | null;
    notes: string | null;
    inspection_expires_at?: string | null;
    insurance_expires_at?: string | null;
    permit_expires_at?: string | null;
    maintenance_until?: string | null;
    route?: RouteRef | null;
    provider?: ProviderOption | null;
};

export type TransportDriver = {
    id: string;
    provider_id: string;
    full_name: string;
    phone_number: string | null;
    license_number: string | null;
    license_expiry_date: string | null;
    status: DriverStatus;
    assigned_vehicle_id: string | null;
    notes: string | null;
    vehicle?: { id: string; vehicle_code: string; plate_number: string } | null;
    provider?: ProviderOption | null;
};

export type TransportSchedule = {
    id: string;
    provider_id: string;
    transport_route_id: string;
    direction: Direction;
    service_days: number[];
    planned_departure: string | null;
    planned_arrival: string | null;
    skips_public_holidays: boolean;
    default_vehicle_id: string | null;
    default_driver_id: string | null;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
    notes: string | null;
    route?: RouteRef | null;
    provider?: ProviderOption | null;
    default_vehicle?: { id: string; plate_number: string } | null;
};

export type ServiceCalendarDay = {
    id: string;
    calendar_date: string;
    kind: 'operating' | 'suspended';
    name_en: string | null;
    name_am: string | null;
    provider?: ProviderOption | null;
    route?: RouteRef | null;
};

export type VehicleChange = { id: string; from_vehicle_id: string | null; to_vehicle_id: string | null; from_plate: string | null; to_plate: string | null; reason: string; changed_at: string | null };

export type TransportTrip = {
    id: string;
    provider_id: string;
    transport_route_id: string;
    transport_vehicle_id: string | null;
    transport_driver_id: string | null;
    transport_schedule_id?: string | null;
    trip_number: string;
    direction: Direction | null;
    trip_date: string | null;
    departure_time: string | null;
    arrival_time: string | null;
    planned_departure_at?: string | null;
    planned_arrival_at?: string | null;
    actual_started_at?: string | null;
    actual_ended_at?: string | null;
    cancelled_at?: string | null;
    cancellation_reason?: string | null;
    status: TripStatus;
    capacity: number | null;
    effective_capacity?: number | null;
    boarded_count: number;
    expected_riders?: number | null;
    utilization_percent?: number | null;
    occupancy_status?: string;
    is_extra?: boolean;
    distance_km?: string | number | null;
    notes?: string | null;
    completion_notes?: string | null;
    route?: RouteRef | null;
    vehicle?: { id: string; vehicle_code?: string; plate_number: string; capacity?: number | null } | null;
    driver?: { id: string; full_name: string } | null;
    provider?: ProviderOption | null;
    vehicle_changes?: VehicleChange[];
};

export type TransportPass = {
    id: string;
    employee_id: string;
    provider_id: string;
    transport_route_id: string | null;
    boarding_stop_id?: string | null;
    evening_stop_id?: string | null;
    suspended_reason?: string | null;
    valid_from: string | null;
    valid_until: string | null;
    status: PassStatus;
    notes: string | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
    provider?: ProviderOption | null;
    route?: RouteRef | null;
    boarding_stop?: ({ id: string; stop_code: string } & Named) | null;
    evening_stop?: ({ id: string; stop_code: string } & Named) | null;
};

export type TransportTransaction = {
    id: string;
    provider_id: string;
    employee_id: string;
    transport_trip_id?: string | null;
    scanned_at: string | null;
    transaction_date: string | null;
    status: TransactionStatus;
    result_code: string | null;
    rejection_reason: string | null;
    direction?: Direction | null;
    verification_method?: VerificationMethod | null;
    sync_state?: SyncState | null;
    voided_at?: string | null;
    void_reason?: string | null;
    manual_reason?: string | null;
    eligibility_snapshot?: Record<string, unknown> | null;
    transport_settlement_id?: string | null;
    employee?: { id: string; employee_number: string; full_name: string; organization: string | null; organization_am?: string | null } | null;
    provider?: ProviderOption | null;
    route?: RouteRef | null;
    trip?: { id: string; trip_number: string; status: TripStatus } | null;
    vehicle?: { id: string; plate_number: string } | null;
    device?: { id: string; device_code: string } | null;
    stop?: ({ id: string; stop_code: string } & Named) | null;
};

export type TransportProvider = {
    id: string;
    provider_code: string;
    assigned_scope_type: ScopeType;
    assigned_organization_id: string | null;
    status: ProviderStatus;
    contact_person: string | null;
    email: string | null;
    phone_number: string | null;
    address: string | null;
    assigned_organization?: OrganizationOption | null;
    profile?: {
        license_number: string | null;
        registration_number: string | null;
        service_area_description_en: string | null;
        service_area_description_am: string | null;
        status: string | null;
    } | null;
} & Named;

export type TransportEligibility = {
    id: string;
    subject_type: 'employee' | 'organization';
    employee_id: string | null;
    organization_id: string | null;
    scope_type: 'self' | 'subtree';
    provider_id: string | null;
    transport_contract_id: string | null;
    status: EligibilityStatus;
    effective_from: string | null;
    effective_to: string | null;
    authorization_reference: string | null;
    notes: string | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
    organization?: OrganizationOption | null;
    provider?: ProviderOption | null;
    authorized_by?: string | null;
};

export type TransportDevice = {
    id: string;
    provider_id: string;
    transport_vehicle_id: string | null;
    device_code: string;
    name: string;
    status: DeviceStatus;
    credential_prefix: string;
    platform: string | null;
    software_version: string | null;
    last_seen_at: string | null;
    last_sync_at: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
    vehicle?: { id: string; vehicle_code: string; plate_number: string } | null;
    provider?: ProviderOption | null;
};

export type TransportContract = {
    id: string;
    contract_reference: string;
    organization_id: string | null;
    provider_id: string;
    version_no: number;
    supersedes_contract_id: string | null;
    status: ContractStatus;
    currency_code: string;
    settlement_model: SettlementModel;
    settlement_period: SettlementPeriod;
    terms: Record<string, string>;
    missing_terms: string[];
    service_coverage: string | null;
    terms_reference: string | null;
    has_agreement: boolean;
    effective_from: string | null;
    effective_to: string | null;
    approved_at: string | null;
    notes: string | null;
    created_by: number | null;
    provider?: ProviderOption | null;
    organization?: OrganizationOption | null;
};

export type TransportInvoice = {
    id: string;
    invoice_number: string;
    provider_id: string;
    transport_contract_id: string | null;
    period_start: string | null;
    period_end: string | null;
    claimed_boardings: number | null;
    claimed_trips: number | null;
    claimed_employee_days: number | null;
    claimed_amount: string | null;
    currency_code: string;
    status: InvoiceStatus;
    has_attachment: boolean;
    notes: string | null;
    submitted_at: string | null;
    returned_reason: string | null;
    transport_settlement_id: string | null;
    submitted_by?: string | null;
    provider?: ProviderOption | null;
    contract?: { id: string; contract_reference: string; version_no: number; settlement_model: SettlementModel | null } | null;
    settlement?: { id: string; settlement_number: string | null; status: SettlementStatus } | null;
};

export type SettlementAdjustment = {
    id: string;
    origin: 'provider_dispute' | 'organization_adjustment';
    kind: string;
    dispute_type: string | null;
    amount: string;
    quantity: number | null;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    requested_by_user_id: number | null;
    decided_at: string | null;
    decision_notes: string | null;
    created_at: string | null;
};

export type SettlementLine = { label: string; quantity: number | null; rate: string | null; amount: string };

export type TransportSettlement = {
    id: string;
    settlement_number: string | null;
    provider_id: string | null;
    transport_contract_id: string | null;
    transport_invoice_id: string | null;
    organization_id: string | null;
    period_start: string | null;
    period_end: string | null;
    status: SettlementStatus;
    contract_snapshot: { contract_reference?: string; version_no?: number; settlement_model?: SettlementModel; terms?: Record<string, string> } | null;
    basis: {
        inbound_boardings?: number; outbound_boardings?: number; manual_boardings?: number; seat_trips?: number; vehicles_used?: number; scheduled_trips?: number;
        by_route?: { route_id: string; boardings: number; riders: number }[]; by_day?: { date: string; boardings: number; riders: number }[];
        lines?: SettlementLine[]; data_quality?: { boardings_on_uncompleted_trips?: number; offline_pending_boardings?: number };
    } | null;
    verified_boardings: number;
    unique_riders: number;
    employee_days: number;
    completed_trips: number;
    cancelled_trips: number;
    currency_code: string;
    calculated_amount: string | null;
    claimed_amount: string | null;
    adjustment_total: string;
    approved_amount: string | null;
    computed_by: number | null;
    computed_at: string | null;
    reviewed_at: string | null;
    approved_at: string | null;
    rejected_reason: string | null;
    payment_reference: string | null;
    payment_marked_at: string | null;
    notes: string | null;
    policy_gaps: string[];
    provider?: ProviderOption | null;
    invoice?: TransportInvoice | null;
    computed_by_name?: string | null;
    reviewed_by_name?: string | null;
    approved_by_name?: string | null;
    adjustments?: SettlementAdjustment[];
};

export type Reconciliation = {
    claimed_amount: string | null;
    calculated_amount: string | null;
    difference: string | null;
    adjustment_total: string;
    adjusted_amount: string | null;
    approved_amount: string | null;
    claimed_boardings: number | null;
    verified_boardings: number;
    boardings_difference: number | null;
    claimed_trips: number | null;
    completed_trips: number;
    claimed_employee_days: number | null;
    employee_days: number;
    disputed: number;
    policy_gaps: string[];
};

export type TransportException = {
    id: string;
    provider_id: string;
    transport_trip_id: string | null;
    transport_transaction_id: string | null;
    employee_id: string | null;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    status: ExceptionStatus;
    detail: Record<string, unknown> | null;
    occurred_at: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    provider?: ProviderOption | null;
    trip?: { id: string; trip_number: string; trip_date: string | null } | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
    vehicle?: { id: string; plate_number: string } | null;
    device?: { id: string; device_code: string } | null;
    reviewed_by?: string | null;
};

export type ManifestRow = {
    id: string;
    employee_number: string | null;
    full_name: string | null;
    organization: string | null;
    organization_am?: string | null;
    boarded_at: string | null;
    stop: ({ stop_code: string } & Named) | null;
    verification_method: VerificationMethod | null;
    status: 'accepted' | 'rejected' | 'voided';
    result_code: string | null;
    sync_state: SyncState | null;
    vehicle_plate: string | null;
    device_code: string | null;
    manual_reason?: string | null;
};

export type Manifest = { rows: ManifestRow[]; totals: { accepted: number; rejected: number; voided: number; manual: number; expected_riders: number } };

export type DataQualityCheck = { code: string; count: number; severity: string };

export type PaginationMeta = { current_page: number; last_page: number; per_page: number; total: number; from?: number | null; to?: number | null };
export type Paginated<T> = { data: T[]; meta: PaginationMeta };

export type ReportFilters = {
    date_from: string;
    date_to: string;
    provider_id?: string | null;
    status?: TransactionStatus | null;
    route_id?: string | null;
    vehicle_id?: string | null;
    direction?: Direction | null;
    verification_method?: VerificationMethod | null;
};

export type ReportBucket = { id: string; accepted: number; rejected: number; riders: number; inbound: number; outbound: number; route_code?: string; provider_code?: string; vehicle_code?: string; plate_number?: string } & Partial<Named>;

export type TransportReport = {
    summary: { total: number; accepted: number; rejected: number; verified: number; voided: number; riders: number; employee_days: number; inbound: number; outbound: number; manual: number; nfc: number; pay_as_you_go: number; trips: number };
    byRoute: ReportBucket[];
    byProvider: ReportBucket[];
    byVehicle: ReportBucket[];
    rejections: { code: string; count: number }[];
    daily: { date: string; accepted: number; rejected: number; inbound: number; outbound: number; riders: number }[];
};

export type DailyUsageRow = { date: string; assigned_employees: number; unique_riders: number; morning_riders: number; evening_riders: number; boardings: number; completed_trips: number; vehicles_used: number };
export type RouteUtilizationRow = { route_id: string; route_code: string; assigned_employees: number; unique_riders: number; boardings: number; inbound: number; outbound: number; scheduled_trips: number; completed_trips: number; cancelled_trips: number; seats_offered: number; utilization_percent: number | null; no_show_rate_percent: number | null } & Named;
export type VehicleUtilizationRow = { vehicle_id: string; vehicle_code: string; plate_number: string; capacity: number | null; trips: number; completed_trips: number; passengers: number; seats_offered: number; utilization_percent: number | null; days_operated: number; distance_km: string };
export type ProviderPerformanceRow = { provider_id: string; provider_code: string; scheduled_trips: number; completed_trips: number; cancelled_trips: number; completion_rate_percent: number | null; verified_boardings: number; unique_riders: number; utilization_percent: number | null; exceptions: number } & Named;

export type ScanResult = {
    accepted: boolean;
    result_code: string;
    message?: string;
    warnings?: string[];
    transaction?: TransportTransaction | null;
    /** The trip's occupancy after this decision, for the terminal's counter. */
    trip?: { id: string; boarded_count: number; effective_capacity: number | null; status: TripStatus } | null;
};

/** The provider-portal capabilities shared by SetProviderPortalContext. */
export type TransportPortalCan = {
    transportDashboardView: boolean;
    transportScan: boolean;
    transportTransactionsView: boolean;
    transportRoutesManage: boolean;
    transportVehiclesManage: boolean;
    transportDriversManage: boolean;
    transportTripsManage: boolean;
    transportReportsView: boolean;
    transportDispatch: boolean;
    transportManualException: boolean;
    transportManifestsView: boolean;
    transportExceptionsView: boolean;
    transportDevicesView: boolean;
    transportInvoicesManage: boolean;
};
