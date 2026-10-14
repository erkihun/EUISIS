/**
 * Transport page contracts, mirroring app/Http/Resources/Transport*Resource
 * and the report payload of App\Services\Transport\TransportReportService.
 */

export type ScopeType = 'self' | 'subtree' | 'citywide';
export type VehicleType = 'bus' | 'minibus' | 'van' | 'car' | 'shuttle' | 'other';
export type VehicleStatus = 'active' | 'maintenance' | 'inactive' | 'suspended';
export type DriverStatus = 'active' | 'inactive' | 'suspended';
export type TripStatus = 'scheduled' | 'boarding' | 'departed' | 'completed' | 'cancelled';
export type PassStatus = 'active' | 'suspended' | 'expired' | 'cancelled';
export type ProviderStatus = 'active' | 'inactive' | 'suspended';
export type TransactionStatus = 'accepted' | 'rejected';

export const SCOPE_TYPES: ScopeType[] = ['self', 'subtree', 'citywide'];
export const VEHICLE_TYPES: VehicleType[] = ['bus', 'minibus', 'van', 'car', 'shuttle', 'other'];
export const VEHICLE_STATUSES: VehicleStatus[] = ['active', 'maintenance', 'inactive', 'suspended'];
export const DRIVER_STATUSES: DriverStatus[] = ['active', 'inactive', 'suspended'];
export const TRIP_STATUSES: TripStatus[] = ['scheduled', 'boarding', 'departed', 'completed', 'cancelled'];
export const PASS_STATUSES: PassStatus[] = ['active', 'suspended', 'expired', 'cancelled'];
export const PROVIDER_STATUSES: ProviderStatus[] = ['active', 'inactive', 'suspended'];

export type Named = { name_en: string | null; name_am: string | null };

export type ProviderOption = { id: string; provider_code: string } & Named;

export type OrganizationOption = { id: string; code?: string | null } & Named;

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
} & Named;

export type TransportVehicle = {
    id: string;
    provider_id: string;
    vehicle_code: string;
    plate_number: string;
    vehicle_type: VehicleType | null;
    capacity: number | null;
    status: VehicleStatus;
    assigned_route_id: string | null;
    model: string | null;
    year: number | null;
    color: string | null;
    notes: string | null;
    route?: ({ id: string; route_code: string } & Named) | null;
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

export type TransportTrip = {
    id: string;
    provider_id: string;
    transport_route_id: string;
    transport_vehicle_id: string | null;
    transport_driver_id: string | null;
    trip_number: string;
    trip_date: string | null;
    departure_time: string | null;
    arrival_time: string | null;
    status: TripStatus;
    capacity: number | null;
    boarded_count: number;
    notes?: string | null;
    route?: ({ id: string; route_code: string } & Named) | null;
    vehicle?: { id: string; plate_number: string } | null;
    driver?: { id: string; full_name: string } | null;
};

export type TransportPass = {
    id: string;
    employee_id: string;
    provider_id: string;
    transport_route_id: string | null;
    valid_from: string | null;
    valid_until: string | null;
    status: PassStatus;
    notes: string | null;
    employee?: { id: string; employee_number: string; full_name: string } | null;
    provider?: ProviderOption | null;
    route?: ({ id: string; route_code: string } & Named) | null;
};

export type TransportTransaction = {
    id: string;
    provider_id: string;
    employee_id: string;
    scanned_at: string | null;
    transaction_date: string | null;
    status: TransactionStatus;
    result_code: string | null;
    rejection_reason: string | null;
    employee?: { id: string; employee_number: string; full_name: string; organization: string | null; organization_am?: string | null } | null;
    provider?: ProviderOption | null;
    route?: ({ id: string; route_code: string } & Named) | null;
    trip?: { id: string; trip_number: string; status: TripStatus } | null;
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

export type PaginationMeta = { current_page: number; last_page: number; per_page: number; total: number; from?: number | null; to?: number | null };
export type Paginated<T> = { data: T[]; meta: PaginationMeta };

export type ReportFilters = { date_from: string; date_to: string; provider_id?: string | null; status?: TransactionStatus | null };

export type ReportBucket = { id: string; accepted: number; rejected: number; route_code?: string; provider_code?: string } & Partial<Named>;

export type TransportReport = {
    summary: { total: number; accepted: number; rejected: number; riders: number; pay_as_you_go: number };
    byRoute: ReportBucket[];
    byProvider: ReportBucket[];
    rejections: { code: string; count: number }[];
    daily: { date: string; accepted: number; rejected: number }[];
};

export type ScanResult = {
    accepted: boolean;
    result_code: string;
    message?: string;
    transaction?: TransportTransaction | null;
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
};
