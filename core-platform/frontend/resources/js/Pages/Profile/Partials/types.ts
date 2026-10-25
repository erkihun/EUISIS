// Props ProfileController@edit hands the profile page and its sections.

export type Profile = {
    name: string;
    email: string;
    phone_number: string | null;
    gender: string | null;
    national_id: string | null;
    profile_photo_url: string | null;
    initials: string;
    roles: string[];
    status: string;
    last_login_at: string | null;
    created_at: string | null;
};

export type SecurityInfo = {
    two_factor_enabled: boolean;
    two_factor_required: boolean;
    two_factor_confirmed_at: string | null;
    two_factor_last_used_at: string | null;
};

export type DeviceKind = 'desktop' | 'phone' | 'tablet' | 'unknown';

export type DeviceSession = {
    id: string;
    ip_address: string | null;
    browser: string | null;
    os: string | null;
    device: DeviceKind;
    last_active_at: string;
    current: boolean;
};

export type SignIn = {
    at: string;
    ip_address: string | null;
    browser: string | null;
    os: string | null;
    device: DeviceKind;
};

export type DevicesInfo = {
    /** False when the session driver keeps no per-user index (anything but `database`). */
    supported: boolean;
    sessions: DeviceSession[];
    recent_sign_ins: SignIn[];
};
