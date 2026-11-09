// Props the applicant portal's account pages get (Applicant\Applicant*Controller). Dates are ISO 8601.
import type { LaravelPageMeta } from '@/Components/ui';

export type LoginAs = 'applicant' | 'respondent';

/** Court/Applicant/Login (ApplicantAuthController@showLogin). */
export interface ApplicantLoginProps {
    loginAs: LoginAs;
    /** The email a failed sign-in was tried with. */
    email: string;
    /** System Settings' login banner, when it is switched on for the sign-in page. */
    bannerUrl: string | null;
    /** The latest published About page; `body` is HTML already sanitised on the server. */
    about: { title: string; body: string } | null;
}

/** Court/Applicant/ForgotPassword (ApplicantPasswordController@showLinkRequestForm). */
export interface ApplicantForgotPasswordProps {
    email: string;
    status: string | null;
}

/** Court/Applicant/Dashboard (ApplicantDashboardController@index). */
export interface ApplicantDashboardProps {
    applicant: { name: string | null; is_lawyer: boolean };
    today: string;
    counts: {
        total: number;
        pending: number;
        active: number;
        closed: number;
        letters: number;
        responses: number;
        decisions: number;
        responseReplies: number;
    };
    recent: {
        id: number;
        case_number: string;
        title: string | null;
        status: string;
        case_type: string | null;
        created_at: string | null;
        created_relative: string;
    }[];
    letters: { id: number; subject: string | null; reference_number: string | null; case_number: string | null; created_at: string | null }[];
    responses: { id: number; title: string | null; case_number: string | null; case_id: number | null; created_relative: string }[];
    responseReplies: {
        id: number;
        case_id: number;
        respondent_response_id: number;
        case_number: string;
        response_number: string | null;
        review_status: string;
        review_note: string | null;
        created_at: string | null;
    }[];
    decisions: { id: number; name: string | null; case_number: string | null; status: string | null; date: string | null; downloadable: boolean }[];
}

/** Court/Applicant/Profile (ApplicantProfileController@edit). */
export interface ApplicantProfileProps {
    profile: {
        first_name: string;
        middle_name: string;
        last_name: string;
        gender: string | null;
        phone: string;
        /** As the model presents it: "0000 0000 0000 0000". */
        national_id_number: string;
        email: string;
        address: string;
        position: string;
        organization_name: string;
        is_lawyer: boolean;
        has_lawyer_document: boolean;
    };
    securitySuccess: string | null;
}

/** Court/Applicant/Sessions (ApplicantSessionController@index). */
export interface ApplicantSessionsProps {
    sessions: {
        id: string;
        ip_address: string | null;
        device_name: string;
        browser_name: string;
        is_current: boolean;
        last_active_at: string;
        last_active_relative: string;
    }[];
    /** False when the session driver keeps no per-user rows (anything but `database`). */
    supported: boolean;
    status: string | null;
}

type Page<T> = LaravelPageMeta & { data: T[] };

/** Court/Applicant/Notifications (ApplicantNotificationController@index). */
export interface ApplicantNotificationsProps {
    hearings: Page<{ id: number; case_id: number; case_number: string; type: string | null; location: string | null; at: string | null }>;
    messages: Page<{ id: number; case_id: number; case_number: string; url: string | null; excerpt: string | null; at: string | null; relative: string }>;
    respondentViews: Page<{ id: number; case_id: number; case_number: string; respondent_name: string | null; at: string | null; relative: string }>;
    /** `from` and `to` are already labelled (app.status.*, or the status in words). */
    statusChanges: Page<{ id: number; case_id: number; case_number: string; from: string; to: string; at: string | null; relative: string }>;
}

/** Court/Applicant/NotificationSettings (ApplicantNotificationController@settingsEdit). */
export interface ApplicantNotificationSettingsProps {
    prefs: { email_status: boolean; email_hearing: boolean; email_message: boolean; email_weekly_digest: boolean };
}
