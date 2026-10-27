/**
 * Addis One Help — browser-side mirror of the JSON contract in
 * docs/public-assistant/public-capabilities.md ("Browser → server"). The
 * panel renders only what the server returns; unknown block types are ignored
 * and every `href` is validated before it becomes a link.
 */

export type PublicAssistantStatus = 'ready' | 'limited';

export type PublicAssistantPageKind =
    | 'home'
    | 'services'
    | 'service'
    | 'announcements'
    | 'support'
    | 'verify'
    | 'id_checker'
    | 'employee_qr'
    | 'organization_qr'
    | 'feedback_form'
    | 'unknown';

export type PublicAssistantQuickActionKey =
    | 'how_to_use'
    | 'submit_feedback'
    | 'submit_suggestion'
    | 'submit_complaint'
    | 'track_submission'
    | 'verify_employee'
    | 'organization_services'
    | 'contact'
    | 'public_help';

export interface PublicAssistantQuickAction {
    key: PublicAssistantQuickActionKey | string;
    label: string;
}

export interface PublicAssistantCapabilities {
    knowledge: boolean;
    services: boolean;
    support: boolean;
    status: boolean;
}

export type PublicAssistantFormType = 'feedback' | 'suggestion' | 'complaint';

export interface PublicAssistantPageTarget {
    type: 'organization' | 'employee' | string;
    name: string;
}

export interface PublicAssistantPageInfo {
    kind: PublicAssistantPageKind | string;
    label: string | null;
    target: PublicAssistantPageTarget | null;
    form_type: PublicAssistantFormType | null;
}

export interface PublicAssistantBootstrap {
    enabled: boolean;
    status: PublicAssistantStatus;
    capabilities: PublicAssistantCapabilities;
    page: PublicAssistantPageInfo;
    quick_actions: PublicAssistantQuickAction[];
    welcome: string;
    disclaimer: string | null;
    limits: { max_message_chars: number };
    messages: PublicAssistantMessage[];
}

export interface PublicAssistantMessageMeta {
    mode?: string | null;
    ai?: boolean | null;
}

export interface PublicAssistantMessage {
    id: string;
    role: 'user' | 'assistant';
    text: string;
    blocks: PublicAssistantBlock[];
    created_at: string;
    meta?: PublicAssistantMessageMeta;
}

// ---------------------------------------------------------------- blocks

export type PublicServiceState = 'available' | 'degraded' | 'unavailable';
export type PublicContactKind = 'phone' | 'email' | 'link';

export interface PublicTextBlock { type: 'text'; text: string }
export interface PublicBulletsBlock { type: 'bullets'; items: string[] }
export interface PublicStepsBlock { type: 'steps'; items: string[] }
export interface PublicSourcesBlock { type: 'sources'; items: Array<{ title: string; href?: string | null }> }
export interface PublicActionsBlock { type: 'actions'; items: Array<{ key: string; label: string; href: string }> }
export interface PublicContactBlock { type: 'contact'; items: Array<{ kind: PublicContactKind | string; label: string; href: string }> }
export interface PublicStatusBlock { type: 'status'; state: PublicServiceState | string; text: string }
export interface PublicNoticeBlock { type: 'notice'; tone: 'info' | 'warning' | string; text: string }
export interface PublicDisclaimerBlock { type: 'disclaimer'; text: string }

export type PublicAssistantBlock =
    | PublicTextBlock
    | PublicBulletsBlock
    | PublicStepsBlock
    | PublicSourcesBlock
    | PublicActionsBlock
    | PublicContactBlock
    | PublicStatusBlock
    | PublicNoticeBlock
    | PublicDisclaimerBlock;
// A block type the server adds later arrives with a `type` outside this union; the
// renderer's `default` branch ignores it (the contract says unknown types are skipped).

// ---------------------------------------------------------------- requests

/** What the browser tells the server about the page: route name, path and the QR token from the URL. */
export interface PublicAssistantPagePayload {
    route: string | null;
    path: string;
    token?: string | null;
}

export interface SendPublicMessagePayload {
    message: string;
    action?: string | null;
    page: PublicAssistantPagePayload;
}

/** POST /public-assistant/messages → the stored user message and the reply. */
export interface SendPublicMessageResponse {
    message: PublicAssistantMessage;
    reply: PublicAssistantMessage;
    status?: PublicAssistantStatus;
}

// ---------------------------------------------------------------- errors

/** Error codes from the contract plus the browser-side ones the panel distinguishes. */
export type PublicAssistantErrorCode =
    | 'assistant_disabled'
    | 'rate_limited'
    | 'validation'
    | 'unavailable'
    | 'session_expired'
    | 'network'
    | 'unknown';

export interface PublicAssistantError {
    code: PublicAssistantErrorCode;
    /** Server-provided sentence when there is one (validation text, for example). */
    message?: string | null;
}
