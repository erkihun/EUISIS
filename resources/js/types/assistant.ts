/**
 * Addis One Assistant — browser-side mirror of the JSON contract in
 * docs/assistant/context-and-tools.md. The panel renders only what the
 * server returns; unknown block types are ignored.
 */

export type AssistantStatus = 'ready' | 'limited' | 'unavailable';

export type AssistantMode = 'HELP' | 'KNOWLEDGE' | 'BUSINESS_ANALYSIS' | 'SYSTEM_DIAGNOSTICS' | 'SUPPORT';

export type AssistantQuickActionKey =
    | 'explain_page'
    | 'how_to'
    | 'find'
    | 'ask_module'
    | 'report_problem'
    | 'system_status'
    | 'create_ticket';

export interface AssistantQuickAction {
    key: AssistantQuickActionKey | string;
    label: string;
}

export interface AssistantCapabilities {
    help: boolean;
    knowledge: boolean;
    analytics: boolean;
    diagnostics: boolean;
    incidents: boolean;
    support: boolean;
}

/** The record the server resolved for the page (through the module's own authorization). */
export interface AssistantPageRecord {
    type: string;
    id?: string | number;
    label?: string | null;
}

export interface AssistantPageInfo {
    route: string | null;
    module: string | null;
    module_label: string | null;
    help_available: boolean;
    record: AssistantPageRecord | null;
}

export interface AssistantBootstrap {
    enabled: boolean;
    status: AssistantStatus;
    ai: { available: boolean };
    capabilities: AssistantCapabilities;
    page: AssistantPageInfo;
    quick_actions: AssistantQuickAction[];
    welcome: string;
    disclaimer: string | null;
    /** Pre-phrased "Now on: {module}" line in the request locale (optional). */
    now_on?: string | null;
    limits: { max_message_chars: number };
    conversation: AssistantConversation | null;
}

export type AssistantFeedback = 'helpful' | 'not_helpful';

export interface AssistantMessageMeta {
    mode?: AssistantMode | string | null;
    ai?: boolean | null;
    confidence?: 'high' | 'medium' | 'low' | null;
    tools?: string[];
    status?: string | null;
}

export interface AssistantMessage {
    id: string;
    role: 'user' | 'assistant';
    text: string;
    blocks: AssistantBlock[];
    created_at: string;
    feedback: AssistantFeedback | null;
    meta?: AssistantMessageMeta;
}

export interface AssistantConversation {
    id: string;
    title: string | null;
    status?: string;
    messages: AssistantMessage[];
}

/** POST /assistant/messages → the stored user message and the assistant's reply. */
export interface SendMessageResponse {
    conversation_id: string;
    status?: AssistantStatus;
    message: AssistantMessage;
    reply: AssistantMessage;
}

/** POST /assistant/tickets → the ticket block plus the assistant's confirmation reply. */
export interface CreateTicketResponse {
    ticket: TicketBlock;
    text?: string;
    reply?: AssistantMessage;
}

export interface AssistantConversationSummary {
    id: string;
    title: string | null;
    last_activity_at: string | null;
}

// ---------------------------------------------------------------- blocks

export type ComponentState = 'healthy' | 'degraded' | 'unhealthy' | 'unknown';
export type IncidentSeverity = 'info' | 'low' | 'medium' | 'high' | 'critical';
export type IncidentState = 'open' | 'investigating' | 'mitigated' | 'resolved' | 'closed';
export type LabelKind = 'observed' | 'calculated' | 'hypothesis' | 'recommendation';

export interface TextBlock { type: 'text'; text: string }
export interface BulletsBlock { type: 'bullets'; items: string[] }
export interface StepsBlock { type: 'steps'; items: string[] }
export interface SourcesBlock { type: 'sources'; items: Array<{ title: string }> }
export interface EvidenceBlock {
    type: 'evidence';
    title: string;
    rows: Array<{ label: string; value: string | number | null }>;
    source?: string | null;
    updated_at?: string | null;
}
export interface StatusBlock {
    type: 'status';
    state: ComponentState | string;
    components: Array<{ key: string; label: string; state: ComponentState | string }>;
    updated_at?: string | null;
    reason?: string | null;
}
export interface IncidentBlock {
    type: 'incident';
    reference: string;
    title: string;
    severity: IncidentSeverity | string;
    status: IncidentState | string;
    impact?: string | null;
    updated_at: string | null;
    href?: string | null;
}
export interface ActionsBlock { type: 'actions'; items: Array<{ key: string; label: string; href: string }> }
export interface TicketOfferBlock {
    type: 'ticket_offer';
    category: string;
    description: string;
    error_reference?: string | null;
    incident_reference?: string | null;
}
export interface TicketBlock {
    type: 'ticket';
    reference: string;
    status: string;
    created_at: string;
    href: string | null;
}
export interface NoticeBlock { type: 'notice'; tone: 'info' | 'warning' | 'error'; text: string }
export interface DisclaimerBlock { type: 'disclaimer'; text: string }
export interface LabelsBlock { type: 'labels'; kind: LabelKind | string; text: string }

export type AssistantBlock =
    | TextBlock
    | BulletsBlock
    | StepsBlock
    | SourcesBlock
    | EvidenceBlock
    | StatusBlock
    | IncidentBlock
    | ActionsBlock
    | TicketOfferBlock
    | TicketBlock
    | NoticeBlock
    | DisclaimerBlock
    | LabelsBlock;
// A block type the server adds later arrives with a `type` outside this union; the
// renderer's `default` branch ignores it (the contract says unknown types are skipped).

// ---------------------------------------------------------------- requests

export interface AssistantPagePayload {
    route: string | null;
    path: string;
    record_type?: string | null;
    record_id?: string | number | null;
}

export interface SendMessagePayload {
    conversation_id?: string | null;
    message: string;
    action?: string | null;
    page: AssistantPagePayload;
}

export type TicketCategory = 'access' | 'error' | 'data' | 'how_to' | 'performance' | 'other';
export type TicketPriority = 'low' | 'normal' | 'high';

export interface CreateTicketPayload {
    conversation_id?: string | null;
    description: string;
    category: TicketCategory | string;
    priority: TicketPriority;
    page: { route: string | null; path: string };
    error_reference?: string | null;
    incident_reference?: string | null;
}

// ---------------------------------------------------------------- errors

/** Error codes from the contract plus the browser-side ones the panel distinguishes. */
export type AssistantErrorCode =
    | 'assistant_disabled'
    | 'forbidden'
    | 'rate_limited'
    | 'ai_unavailable'
    | 'validation'
    | 'support_disabled'
    | 'not_found'
    | 'session_expired'
    | 'network'
    | 'unknown';

export interface AssistantError {
    code: AssistantErrorCode;
    /** Server-provided sentence when there is one (validation text, for example). */
    message?: string | null;
}
