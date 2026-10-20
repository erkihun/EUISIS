import type { LaravelPageMeta, Tone } from '@/Components/ui';

/* Support help desk (docs/assistant/support-helpdesk.md) — the shapes SupportTicketController sends. */

export type SupportTicketCategory = 'access' | 'error' | 'data' | 'how_to' | 'performance' | 'other';
export type SupportTicketPriority = 'low' | 'normal' | 'high';
export type SupportTicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed';
export type SupportTicketEventKind = 'created' | 'status_changed' | 'assigned' | 'note';

/** The allow-listed, redacted context keys a ticket may carry (SupportTicketService::CONTEXT_KEYS). */
export type SupportContextKey = 'route' | 'path' | 'module' | 'locale' | 'browser' | 'os' | 'device' | 'error_reference' | 'incident_reference' | 'timestamp' | 'viewport';

export interface SupportTicketRow {
    id: string;
    reference: string;
    title: string;
    category: SupportTicketCategory;
    priority: SupportTicketPriority;
    status: SupportTicketStatus;
    module: string | null;
    assigned_team: string | null;
    assignee: string | null;
    /** Only sent to viewers with `support-tickets.view-all`. */
    reporter: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface SupportTicketDetail extends SupportTicketRow {
    description: string;
    route_name: string | null;
    path: string | null;
    context: Partial<Record<SupportContextKey, string>>;
    error_reference: string | null;
    incident: { reference: string; severity: string; status: string } | null;
    assigned_user_id: number | null;
    resolved_at: string | null;
    closed_at: string | null;
}

export interface SupportTicketEvent {
    id: string;
    event: SupportTicketEventKind;
    from: string | null;
    to: string | null;
    note: string | null;
    actor: string | null;
    occurred_at: string;
}

export type SupportPaginated<T> = LaravelPageMeta & { data: T[] };

/** Badge tones shared by the list and the detail page. */
export const SUPPORT_STATUS_TONE: Record<SupportTicketStatus, Tone> = { open: 'warning', in_progress: 'info', resolved: 'success', closed: 'neutral' };
export const SUPPORT_PRIORITY_TONE: Record<SupportTicketPriority, Tone> = { low: 'neutral', normal: 'info', high: 'danger' };
