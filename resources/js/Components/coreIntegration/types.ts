import type { PaginationMeta } from '@euisis/ui';

/**
 * The standalone Core's Integration Center, as its server sends it (the
 * optional `center` prop of Core/IntegrationCenter). Each tab's rows come
 * only when that tab is open; every action URL comes from the server.
 * No secret is part of this data: a created or rotated one arrives once,
 * in the `integration_secret_once` flash.
 */
export type IntegrationTab = 'clients' | 'webhooks' | 'deliveries' | 'events' | 'logs';

export type Paged<T> = { data: T[]; meta: PaginationMeta };

export type OrganizationOption = { id: string; code: string; name_en: string; name_am: string | null };

export type ApiClientRow = {
    uuid: string;
    name: string;
    description: string | null;
    scope_type: string | null;
    owner_organization_id: string | null;
    scopes: string[];
    allowed_ips: string[] | null;
    rate_limit_per_minute: number;
    is_active: boolean;
    expires_at: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
    secret_rotated_at: string | null;
    last_used_at: string | null;
    calls_24h: number;
    errors_24h: number;
    /** Usage over the last week (ADR 0010). */
    calls_7d?: number;
    errors_7d?: number;
    denials_7d?: number;
    mean_ms_7d?: number | null;
    urls: { update: string; rotate: string; revoke: string };
};

export type WebhookRow = {
    id: string;
    name: string;
    url: string;
    event_types: string[];
    scope_organization_id: string | null;
    is_active: boolean;
    secret_rotated_at: string | null;
    last_success_at: string | null;
    last_failure_at: string | null;
    /** Suspension after repeated failures, and the old secret's grace period (ADR 0010). */
    consecutive_failures?: number;
    suspended_at?: string | null;
    suspended_reason?: string | null;
    previous_secret_expires_at?: string | null;
    deliveries: Record<string, number>;
    urls: { update: string; rotate: string; resume?: string | null };
};

export type DeliveryRow = {
    id: string;
    endpoint_name: string;
    event_type: string;
    entity_id: string | null;
    status: string;
    attempts: number;
    response_code: number | null;
    error: string | null;
    next_attempt_at: string | null;
    delivered_at: string | null;
    created_at: string;
    retry_url: string | null;
};

export type EventRow = {
    id: string;
    event_type: string;
    entity_type: string;
    entity_id: string | null;
    organization_id: string | null;
    payload_version: number | string;
    data: unknown;
    occurred_at: string;
};

export type ApiCallRow = {
    id: number | string;
    client_name: string | null;
    method: string;
    route: string;
    status: number;
    duration_ms: number | null;
    ip: string | null;
    denial_code: string | null;
    request_id: string | null;
    created_at: string;
};

export type IntegrationCenterData = {
    tab: IntegrationTab;
    summary: Record<'active_clients' | 'calls_24h' | 'errors_24h' | 'active_webhooks' | 'events_24h' | 'pending_deliveries' | 'failed_deliveries', number>;
    filters: Record<string, string | boolean>;
    clients: ApiClientRow[] | null;
    webhooks: WebhookRow[] | null;
    deliveries: Paged<DeliveryRow> | null;
    events: Paged<EventRow> | null;
    logs: Paged<ApiCallRow> | null;
    options: {
        scopes: string[];
        event_types: string[];
        organizations: OrganizationOption[];
        webhooks: Array<{ id: string; name: string }>;
        clients: Array<{ uuid: string; name: string }>;
    };
    urls: { page: string; store_client: string; store_webhook: string };
};

export type SecretOnce = { kind: 'client' | 'webhook'; name: string; secret: string };
