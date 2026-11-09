import axios from 'axios';
import { useCallback, useMemo, useSyncExternalStore } from 'react';
import type {
    AssistantBlock,
    AssistantBootstrap,
    AssistantConversation,
    AssistantError,
    AssistantErrorCode,
    AssistantFeedback,
    AssistantMessage,
    AssistantPagePayload,
    CreateTicketPayload,
    CreateTicketResponse,
    SendMessagePayload,
    SendMessageResponse,
    TicketBlock,
} from '@/types/assistant';
import type { DeclaredPageRecord } from './AssistantPageContext';

/*
 * Conversation state of the Addis One Assistant.
 *
 * The layout that mounts the assistant is re-created on every Inertia visit
 * (pages are not persistent layouts), so everything the panel must keep
 * across navigations lives in this module-level store rather than in
 * component state. Nothing here is written to storage: message content stays
 * in memory for the tab's lifetime only (docs/assistant/architecture.md A-6).
 */

export type ThinkingMode = 'analyzing' | 'searching' | 'status';

export type TimelineItem =
    | { kind: 'message'; message: AssistantMessage }
    /** "Now on: {module}" after a navigation; `text` is the server's phrasing when it sent one. */
    | { kind: 'system'; id: string; moduleLabel: string; text: string | null };

export interface AssistantState {
    bootstrap: AssistantBootstrap | null;
    bootstrapError: AssistantError | null;
    loading: boolean;
    conversationId: string | null;
    items: TimelineItem[];
    pending: boolean;
    thinking: ThinkingMode | null;
    error: AssistantError | null;
    showActions: boolean;
    draft: string;
    expanded: boolean;
    /** `${messageId}:${blockIndex}` of ticket offers already turned into tickets. */
    usedOffers: string[];
    /** Route name of the page the current bootstrap was fetched for. */
    bootstrapRoute: string | null;
}

const initialState: AssistantState = {
    bootstrap: null,
    bootstrapError: null,
    loading: false,
    conversationId: null,
    items: [],
    pending: false,
    thinking: null,
    error: null,
    showActions: true,
    draft: '',
    expanded: false,
    usedOffers: [],
    bootstrapRoute: null,
};

let state: AssistantState = initialState;
const listeners = new Set<() => void>();

function setState(patch: Partial<AssistantState> | ((prev: AssistantState) => Partial<AssistantState>)): void {
    const next = typeof patch === 'function' ? patch(state) : patch;
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function snapshot(): AssistantState {
    return state;
}

// ---------------------------------------------------------------- helpers

function currentRouteName(): string | null {
    try {
        return (route().current() as string | undefined) ?? null;
    } catch {
        return null;
    }
}

export function pagePayload(record: DeclaredPageRecord | null): AssistantPagePayload {
    const payload: AssistantPagePayload = {
        route: currentRouteName(),
        path: typeof window === 'undefined' ? '/' : window.location.pathname,
    };
    if (record) {
        payload.record_type = record.type;
        payload.record_id = record.id;
    }
    return payload;
}

const KNOWN_CODES: AssistantErrorCode[] = [
    'assistant_disabled',
    'forbidden',
    'rate_limited',
    'ai_unavailable',
    'validation',
    'support_disabled',
    'not_found',
];

/** Turns an axios failure into the typed error the panel explains. */
export function classifyError(error: unknown): AssistantError {
    if (!axios.isAxiosError(error)) return { code: 'unknown' };
    if (!error.response) return { code: 'network' };

    const status = error.response.status;
    const data = (error.response.data ?? {}) as {
        error?: string;
        message?: string;
        errors?: Record<string, string[] | string>;
    };
    const firstValidation = data.errors
        ? Object.values(data.errors).flat().find((text) => typeof text === 'string' && text.trim() !== '') ?? null
        : null;
    const message = firstValidation ?? (typeof data.message === 'string' && data.message.trim() !== '' ? data.message : null);

    if (status === 401 || status === 419) return { code: 'session_expired' };
    if (data.error && (KNOWN_CODES as string[]).includes(data.error)) {
        return { code: data.error as AssistantErrorCode, message };
    }
    if (status === 403) return { code: 'forbidden', message };
    if (status === 404) return { code: 'not_found', message };
    if (status === 422) return { code: 'validation', message };
    if (status === 429) return { code: 'rate_limited', message };
    if (status === 503) return { code: 'ai_unavailable', message };

    return { code: 'unknown', message };
}

function isMessage(value: unknown): value is AssistantMessage {
    return Boolean(value) && typeof value === 'object'
        && typeof (value as AssistantMessage).id !== 'undefined'
        && ((value as AssistantMessage).role === 'user' || (value as AssistantMessage).role === 'assistant');
}

function normalizeMessage(message: AssistantMessage): AssistantMessage {
    return {
        ...message,
        id: String(message.id),
        text: typeof message.text === 'string' ? message.text : '',
        blocks: Array.isArray(message.blocks) ? message.blocks : [],
        feedback: message.feedback ?? null,
    };
}

function toItems(messages: AssistantMessage[]): TimelineItem[] {
    return messages.filter(isMessage).map((message) => ({ kind: 'message', message: normalizeMessage(message) }));
}

/** Guess what the assistant is doing from the request, for the "thinking" line. */
export function thinkingModeFor(text: string, action?: string | null): ThinkingMode {
    if (action === 'system_status' || action === 'report_problem') return 'status';
    if (action === 'explain_page' || action === 'how_to') return 'analyzing';
    if (action) return 'searching';
    if (/\b(status|health|down|slow|outage)\b|ሁኔታ|ጤንነት|ተቋርጧል|ቀርፋፋ/i.test(text)) return 'status';
    if (/\b(how|where|explain|what is|why)\b|እንዴት|የት|አብራራ|ምንድን|ለምን/i.test(text)) return 'analyzing';
    return 'searching';
}

/** The contract's shape (`message` + `reply`) plus the aliases an earlier draft used. */
interface MessagesResponse extends Partial<SendMessageResponse> {
    conversation?: { id?: string };
    user?: AssistantMessage;
    assistant?: AssistantMessage;
    messages?: AssistantMessage[];
}

interface ConversationResponse {
    conversation?: Partial<AssistantConversation> & { id?: string };
    id?: string;
    title?: string | null;
    messages?: AssistantMessage[];
}

interface TicketResponse extends Partial<Omit<CreateTicketResponse, 'ticket'>> {
    ticket?: TicketBlock | (Partial<TicketBlock> & { type?: string });
    block?: TicketBlock;
    message?: AssistantMessage;
    type?: string;
    reference?: string;
    status?: string;
    created_at?: string;
    href?: string | null;
}

function ticketBlockFrom(data: TicketResponse): TicketBlock | null {
    const candidate = data.block ?? data.ticket ?? (data.type === 'ticket' ? data : null);
    if (!candidate || typeof candidate !== 'object' || typeof candidate.reference !== 'string') return null;

    return {
        type: 'ticket',
        reference: candidate.reference,
        status: typeof candidate.status === 'string' ? candidate.status : 'open',
        created_at: typeof candidate.created_at === 'string' ? candidate.created_at : new Date().toISOString(),
        href: typeof candidate.href === 'string' ? candidate.href : null,
    };
}

let tempCounter = 0;
function tempId(prefix: string): string {
    tempCounter += 1;
    return `${prefix}-${Date.now()}-${tempCounter}`;
}

// ---------------------------------------------------------------- actions

async function loadBootstrap(record: DeclaredPageRecord | null, reason: 'open' | 'navigate'): Promise<void> {
    const page = pagePayload(record);
    if (reason === 'navigate' && state.bootstrapRoute === page.route && state.bootstrap && !state.bootstrapError) {
        // Same page (e.g. a filter change): nothing new to learn.
        return;
    }
    if (reason === 'open' && state.loading) return;

    setState({ loading: true, bootstrapError: null });

    try {
        const { data } = await axios.get<AssistantBootstrap>(route('assistant.bootstrap'), { params: page });
        setState((prev) => {
            const next: Partial<AssistantState> = {
                bootstrap: data,
                loading: false,
                bootstrapRoute: page.route,
            };
            const previousModule = prev.bootstrap?.page?.module ?? null;
            const nextModule = data.page?.module ?? null;

            if (reason === 'navigate' && prev.items.length > 0 && previousModule !== nextModule && data.page?.module_label) {
                next.items = [...prev.items, {
                    kind: 'system',
                    id: tempId('nav'),
                    moduleLabel: data.page.module_label,
                    text: typeof data.now_on === 'string' && data.now_on.trim() !== '' ? data.now_on : null,
                }];
            }
            if (!prev.conversationId && data.conversation) {
                next.conversationId = data.conversation.id;
                const loaded = toItems(data.conversation.messages ?? []);
                next.items = [...(next.items ?? prev.items), ...loaded];
                next.showActions = loaded.length === 0;
            }

            return next;
        });
    } catch (error) {
        setState({ loading: false, bootstrapError: classifyError(error) });
    }
}

async function send(text: string, record: DeclaredPageRecord | null, action?: string | null): Promise<void> {
    const message = text.trim();
    if (state.pending || message === '') return;

    const optimistic: AssistantMessage = {
        id: tempId('user'),
        role: 'user',
        text: message,
        blocks: [],
        created_at: new Date().toISOString(),
        feedback: null,
    };

    setState((prev) => ({
        pending: true,
        thinking: thinkingModeFor(message, action),
        error: null,
        showActions: false,
        draft: action ? prev.draft : '',
        items: [...prev.items, { kind: 'message', message: optimistic }],
    }));

    const payload: SendMessagePayload = {
        conversation_id: state.conversationId,
        message,
        action: action ?? null,
        page: pagePayload(record),
    };

    try {
        const { data } = await axios.post<MessagesResponse>(route('assistant.messages.store'), payload);
        const returned = Array.isArray(data.messages)
            ? data.messages
            : [data.message ?? data.user, data.reply ?? data.assistant].filter((item): item is AssistantMessage => isMessage(item));
        const serverUser = returned.find((item) => item.role === 'user') ?? null;
        const others = returned.filter((item) => item !== serverUser);
        const conversationId = data.conversation_id ?? data.conversation?.id ?? state.conversationId;
        const status = data.status;

        setState((prev) => ({
            pending: false,
            thinking: null,
            conversationId: conversationId ?? prev.conversationId,
            // The reply carries the live status (the AI may have become unavailable mid-conversation).
            bootstrap: prev.bootstrap && status && status !== prev.bootstrap.status ? { ...prev.bootstrap, status } : prev.bootstrap,
            items: [
                ...prev.items.map((item) =>
                    item.kind === 'message' && item.message.id === optimistic.id && serverUser
                        ? { kind: 'message' as const, message: normalizeMessage(serverUser) }
                        : item,
                ),
                ...toItems(others),
            ],
        }));
    } catch (error) {
        const failure = classifyError(error);
        setState((prev) => ({
            pending: false,
            thinking: null,
            error: failure,
            // Give the text back so it can be corrected and sent again.
            draft: action ? prev.draft : message,
            items: prev.items.filter((item) => !(item.kind === 'message' && item.message.id === optimistic.id)),
        }));
    }
}

async function feedback(messageId: string, value: AssistantFeedback): Promise<void> {
    const apply = (next: AssistantFeedback | null) =>
        setState((prev) => ({
            items: prev.items.map((item) =>
                item.kind === 'message' && item.message.id === messageId
                    ? { kind: 'message' as const, message: { ...item.message, feedback: next } }
                    : item,
            ),
        }));

    const previous = state.items.find((item): item is Extract<TimelineItem, { kind: 'message' }> => item.kind === 'message' && item.message.id === messageId)?.message.feedback ?? null;
    apply(value);

    try {
        await axios.post(route('assistant.messages.feedback', messageId), { value });
    } catch (error) {
        apply(previous);
        setState({ error: classifyError(error) });
    }
}

async function createTicket(offerKey: string, payload: Omit<CreateTicketPayload, 'conversation_id'>): Promise<boolean> {
    if (state.pending) return false;
    setState({ pending: true, thinking: null, error: null });

    try {
        const { data } = await axios.post<TicketResponse>(route('assistant.tickets.store'), {
            ...payload,
            conversation_id: state.conversationId,
        });
        const block = ticketBlockFrom(data);
        const reply = isMessage(data.reply) ? data.reply : isMessage(data.message) ? data.message : null;
        const replyHasTicket = reply?.blocks?.some((item) => item.type === 'ticket') ?? false;
        const confirmation: AssistantMessage = reply
            ? normalizeMessage(replyHasTicket || !block ? reply : { ...reply, blocks: [...(reply.blocks ?? []), block] })
            : {
                id: tempId('ticket'),
                role: 'assistant',
                text: typeof data.text === 'string' ? data.text : '',
                blocks: block ? [block] : [],
                created_at: block?.created_at ?? new Date().toISOString(),
                feedback: null,
                meta: { mode: 'SUPPORT', ai: false },
            };

        setState((prev) => ({
            pending: false,
            usedOffers: [...prev.usedOffers, offerKey],
            items: [...prev.items, { kind: 'message', message: confirmation }],
        }));

        return true;
    } catch (error) {
        setState({ pending: false, error: classifyError(error) });
        return false;
    }
}

async function newConversation(): Promise<void> {
    if (state.pending) return;
    setState({ pending: true, thinking: null, error: null });

    try {
        const { data } = await axios.post<ConversationResponse>(route('assistant.conversations.store'));
        const id = data.conversation?.id ?? data.id ?? null;
        const messages = data.conversation?.messages ?? data.messages ?? [];

        setState({
            pending: false,
            conversationId: id,
            items: toItems(messages),
            showActions: true,
            draft: '',
            usedOffers: [],
        });
    } catch (error) {
        setState({ pending: false, error: classifyError(error) });
    }
}

// ---------------------------------------------------------------- hook

export function useAssistant() {
    const current = useSyncExternalStore(subscribe, snapshot, snapshot);

    const setDraft = useCallback((draft: string) => setState({ draft }), []);
    const setExpanded = useCallback((expanded: boolean) => setState({ expanded }), []);
    const toggleActions = useCallback(() => setState((prev) => ({ showActions: !prev.showActions })), []);
    const dismissError = useCallback(() => setState({ error: null }), []);

    const actions = useMemo(
        () => ({ loadBootstrap, send, feedback, createTicket, newConversation, setDraft, setExpanded, toggleActions, dismissError }),
        [setDraft, setExpanded, toggleActions, dismissError],
    );

    return { state: current, ...actions };
}

/** Visible text of a message: its text plus bullet and step items, for the copy button. */
export function visibleText(message: AssistantMessage): string {
    const parts: string[] = [];
    const blocks = message.blocks as AssistantBlock[];
    const textBlocks = blocks.filter((block) => block.type === 'text' || block.type === 'bullets' || block.type === 'steps');

    if (textBlocks.length === 0 && message.text) parts.push(message.text);

    for (const block of blocks) {
        if (block.type === 'text' && typeof block.text === 'string') parts.push(block.text);
        if (block.type === 'bullets' && Array.isArray(block.items)) parts.push(block.items.map((item) => `- ${item}`).join('\n'));
        if (block.type === 'steps' && Array.isArray(block.items)) parts.push(block.items.map((item, index) => `${index + 1}. ${item}`).join('\n'));
    }

    return parts.join('\n\n').trim();
}
