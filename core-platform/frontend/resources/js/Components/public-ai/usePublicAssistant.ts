import axios from 'axios';
import { useCallback, useMemo, useSyncExternalStore } from 'react';
import type {
    PublicAssistantBlock,
    PublicAssistantBootstrap,
    PublicAssistantError,
    PublicAssistantErrorCode,
    PublicAssistantMessage,
    PublicAssistantPagePayload,
    SendPublicMessagePayload,
    SendPublicMessageResponse,
} from '@/types/publicAssistant';

/*
 * Conversation state of Addis One Help (the public-site assistant).
 *
 * The public layouts are re-created on every Inertia visit, so what the panel
 * keeps across navigations lives in this module-level store rather than in
 * component state. Nothing is written to storage: the conversation stays in
 * memory for the tab, and a reload re-fetches the session's turns from the
 * server (docs/public-assistant/architecture.md P-4).
 */

/** A message whose delivery failed on the network; Retry re-sends exactly this one. */
interface PendingRequest {
    id: string;
    message: string;
    action: string | null;
}

export interface PublicAssistantState {
    bootstrap: PublicAssistantBootstrap | null;
    bootstrapError: PublicAssistantError | null;
    /** Path the current bootstrap was fetched for. */
    bootstrapPath: string | null;
    loading: boolean;
    messages: PublicAssistantMessage[];
    pending: boolean;
    pendingRequest: PendingRequest | null;
    error: PublicAssistantError | null;
    showActions: boolean;
    draft: string;
}

const initialState: PublicAssistantState = {
    bootstrap: null,
    bootstrapError: null,
    bootstrapPath: null,
    loading: false,
    messages: [],
    pending: false,
    pendingRequest: null,
    error: null,
    showActions: true,
    draft: '',
};

let state: PublicAssistantState = initialState;
const listeners = new Set<() => void>();

function setState(patch: Partial<PublicAssistantState> | ((prev: PublicAssistantState) => Partial<PublicAssistantState>)): void {
    const next = typeof patch === 'function' ? patch(state) : patch;
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function snapshot(): PublicAssistantState {
    return state;
}

// ---------------------------------------------------------------- page payload

/** The QR token is read from the URL only on the two scanned-code paths; anything after it is ignored. */
const QR_PATH = /^\/(service-feedback|feedback)\/([A-Za-z0-9_-]+)/;

export function tokenFromPath(path: string): string | null {
    const match = QR_PATH.exec(path);
    return match ? match[2] : null;
}

function currentRouteName(): string | null {
    try {
        return (route().current() as string | undefined) ?? null;
    } catch {
        return null;
    }
}

export function pagePayload(): PublicAssistantPagePayload {
    const path = typeof window === 'undefined' ? '/' : window.location.pathname;
    const payload: PublicAssistantPagePayload = { route: currentRouteName(), path };
    const token = tokenFromPath(path);
    if (token) payload.token = token;
    return payload;
}

// ---------------------------------------------------------------- errors

const KNOWN_CODES: PublicAssistantErrorCode[] = ['assistant_disabled', 'rate_limited', 'validation', 'unavailable'];

/** Turns an axios failure into the typed error the panel explains. */
export function classifyError(error: unknown): PublicAssistantError {
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

    if (status === 419) return { code: 'session_expired' };
    if (data.error && (KNOWN_CODES as string[]).includes(data.error)) {
        return { code: data.error as PublicAssistantErrorCode, message };
    }
    if (status === 403) return { code: 'assistant_disabled', message };
    if (status === 422) return { code: 'validation', message };
    if (status === 429) return { code: 'rate_limited', message };
    if (status === 503) return { code: 'unavailable', message };

    return { code: 'unknown', message };
}

// ---------------------------------------------------------------- messages

function isMessage(value: unknown): value is PublicAssistantMessage {
    return Boolean(value) && typeof value === 'object'
        && typeof (value as PublicAssistantMessage).id !== 'undefined'
        && ((value as PublicAssistantMessage).role === 'user' || (value as PublicAssistantMessage).role === 'assistant');
}

function normalizeMessage(message: PublicAssistantMessage): PublicAssistantMessage {
    return {
        ...message,
        id: String(message.id),
        text: typeof message.text === 'string' ? message.text : '',
        blocks: Array.isArray(message.blocks) ? message.blocks : [],
    };
}

function normalizeAll(messages: unknown): PublicAssistantMessage[] {
    return Array.isArray(messages) ? messages.filter(isMessage).map(normalizeMessage) : [];
}

let tempCounter = 0;
function tempId(prefix: string): string {
    tempCounter += 1;
    return `${prefix}-${Date.now()}-${tempCounter}`;
}

/** Visible text of a message (text, bullets and steps), for the live region. */
export function visibleText(message: PublicAssistantMessage): string {
    const parts: string[] = [];
    const blocks = message.blocks as PublicAssistantBlock[];
    const textBlocks = blocks.filter((block) => block.type === 'text' || block.type === 'bullets' || block.type === 'steps');

    if (textBlocks.length === 0 && message.text) parts.push(message.text);

    for (const block of blocks) {
        if (block.type === 'text' && typeof block.text === 'string') parts.push(block.text);
        if (block.type === 'bullets' && Array.isArray(block.items)) parts.push(block.items.map((item) => `- ${item}`).join('\n'));
        if (block.type === 'steps' && Array.isArray(block.items)) parts.push(block.items.map((item, index) => `${index + 1}. ${item}`).join('\n'));
    }

    return parts.join('\n\n').trim();
}

// ---------------------------------------------------------------- actions

/** Fetches the page's bootstrap; a second call for the same path reuses the one already loaded. */
async function loadBootstrap(): Promise<void> {
    const page = pagePayload();
    if (state.loading) return;
    if (state.bootstrap && !state.bootstrapError && state.bootstrapPath === page.path) return;

    setState({ loading: true, bootstrapError: null });

    try {
        const { data } = await axios.get<PublicAssistantBootstrap>(route('public-assistant.bootstrap'), { params: page });
        const messages = normalizeAll(data.messages);
        setState({
            bootstrap: { ...data, messages },
            bootstrapPath: page.path,
            loading: false,
            // The server session is the record: it replaces whatever the tab held.
            messages,
            pendingRequest: null,
            error: null,
            showActions: messages.length === 0,
        });
    } catch (error) {
        setState({ loading: false, bootstrapError: classifyError(error) });
    }
}

async function deliver(id: string, message: string, action: string | null): Promise<void> {
    const payload: SendPublicMessagePayload = { message, action, page: pagePayload() };

    try {
        const { data } = await axios.post<Partial<SendPublicMessageResponse>>(route('public-assistant.messages.store'), payload);
        const serverUser = isMessage(data.message) && data.message.role === 'user' ? normalizeMessage(data.message) : null;
        const reply = isMessage(data.reply) ? normalizeMessage(data.reply) : null;
        const status = data.status;

        setState((prev) => ({
            pending: false,
            pendingRequest: null,
            // The reply carries the live status (AI help may have become limited mid-conversation).
            bootstrap: prev.bootstrap && status && status !== prev.bootstrap.status ? { ...prev.bootstrap, status } : prev.bootstrap,
            messages: [
                ...prev.messages.map((item) => (item.id === id && serverUser ? serverUser : item)),
                ...(reply ? [reply] : []),
            ],
        }));
    } catch (error) {
        const failure = classifyError(error);
        if (failure.code === 'network') {
            // Keep the message in place so Retry re-sends it without a duplicate.
            setState({ pending: false, error: failure });
            return;
        }
        setState((prev) => ({
            pending: false,
            pendingRequest: null,
            error: failure,
            // Give the text back so it can be corrected and sent again.
            draft: action ? prev.draft : message,
            messages: prev.messages.filter((item) => item.id !== id),
        }));
    }
}

async function send(text: string, action?: string | null): Promise<void> {
    const message = text.trim();
    if (state.pending || state.pendingRequest || message === '') return;

    const optimistic: PublicAssistantMessage = {
        id: tempId('user'),
        role: 'user',
        text: message,
        blocks: [],
        created_at: new Date().toISOString(),
    };

    setState((prev) => ({
        pending: true,
        error: null,
        showActions: false,
        draft: action ? prev.draft : '',
        messages: [...prev.messages, optimistic],
        pendingRequest: { id: optimistic.id, message, action: action ?? null },
    }));

    await deliver(optimistic.id, message, action ?? null);
}

/** Re-sends the message whose delivery failed on the network. */
async function retry(): Promise<void> {
    const request = state.pendingRequest;
    if (!request || state.pending) return;
    setState({ pending: true, error: null });
    await deliver(request.id, request.message, request.action);
}

/** Forgets the session's turns on the server, then in the tab. */
async function reset(): Promise<void> {
    if (state.pending) return;
    setState({ pending: true, error: null });

    try {
        await axios.post(route('public-assistant.reset'));
        setState({ pending: false, messages: [], pendingRequest: null, draft: '', showActions: true });
    } catch (error) {
        setState({ pending: false, error: classifyError(error) });
    }
}

function dismissError(): void {
    setState((prev) => {
        const request = prev.pendingRequest;
        if (!request) return { error: null };
        // Giving up on a failed delivery: take the message out of the log and back into the composer.
        return {
            error: null,
            pendingRequest: null,
            draft: request.action ? prev.draft : request.message,
            messages: prev.messages.filter((item) => item.id !== request.id),
        };
    });
}

// ---------------------------------------------------------------- hook

export function usePublicAssistant() {
    const current = useSyncExternalStore(subscribe, snapshot, snapshot);

    const setDraft = useCallback((draft: string) => setState({ draft }), []);
    const toggleActions = useCallback(() => setState((prev) => ({ showActions: !prev.showActions })), []);

    const actions = useMemo(
        () => ({ loadBootstrap, send, retry, reset, setDraft, toggleActions, dismissError }),
        [setDraft, toggleActions],
    );

    return { state: current, ...actions };
}
