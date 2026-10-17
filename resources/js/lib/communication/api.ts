/**
 * Communication JSON client. Same-origin session requests (CSRF via the
 * XSRF-TOKEN cookie, set up in bootstrap.ts). HTTP is the source of truth;
 * realtime only tells the page when to look again.
 */
import axios, { type AxiosProgressEvent } from 'axios';
import type {
    Attachment, ConversationDetail, ConversationPage, MessageItem, MessagePage, Person, Reaction, Totals,
} from '@/types/communication';

const r = (name: string, params?: unknown): string => (route as unknown as (n: string, p?: unknown) => string)(`communication.${name}`, params);

export const communicationApi = {
    conversations: (params: { type?: string | null; cursor?: string | null; q?: string | null } = {}) =>
        axios.get<ConversationPage>(r('api.conversations.index', Object.fromEntries(Object.entries(params).filter(([, v]) => v)))).then((res) => res.data),
    conversation: (id: string) => axios.get<ConversationDetail>(r('api.conversations.show', id)).then((res) => res.data),
    messages: (id: string, before?: string | null) =>
        axios.get<MessagePage>(r('api.conversations.messages', before ? [id, { before }] : id)).then((res) => res.data),
    send: (id: string, payload: { body?: string; parent_message_id?: string | null; attachment_ids?: string[]; client_message_id: string }) =>
        axios.post<{ data: MessageItem }>(r('api.messages.store', id), payload).then((res) => res.data.data),
    edit: (messageId: string, body: string) => axios.patch<{ data: MessageItem }>(r('api.messages.update', messageId), { body }).then((res) => res.data.data),
    remove: (messageId: string) => axios.delete(r('api.messages.destroy', messageId)),
    react: (messageId: string, emoji: string, add: boolean) => (add
        ? axios.post<{ reactions: Reaction[] }>(r('api.messages.react', messageId), { emoji })
        : axios.delete<{ reactions: Reaction[] }>(r('api.messages.unreact', messageId), { data: { emoji } })).then((res) => res.data.reactions),
    pin: (messageId: string, add: boolean) => (add ? axios.post(r('api.messages.pin', messageId)) : axios.delete(r('api.messages.unpin', messageId))),
    forward: (messageId: string, conversationIds: string[]) => axios.post(r('api.messages.forward', messageId), { conversation_ids: conversationIds }),
    read: (id: string, messageId?: string | null) => axios.post(r('api.conversations.read', id), messageId ? { message_id: messageId } : {}),
    typing: (id: string) => axios.post(r('api.conversations.typing', id)),
    mute: (id: string, mute: boolean, minutes?: number | null) => axios.post(r('api.conversations.mute', id), { mute, minutes: minutes ?? null }),
    pins: (id: string) => axios.get<{ data: { pinned_at: string | null; pinned_by: string | null; message: MessageItem }[] }>(r('api.conversations.pins', id)).then((res) => res.data.data),
    files: (id: string, before?: string | null) =>
        axios.get<{ data: (Attachment & { uploaded_by: string | null; created_at: string | null; message_id: string })[]; next_before: string | null }>(
            r('api.conversations.files', before ? [id, { before }] : id)).then((res) => res.data),
    upload: (id: string, file: File, onProgress: (percent: number) => void, signal: AbortSignal) => {
        const form = new FormData();
        form.append('file', file);
        return axios.post<{ data: Attachment }>(r('api.attachments.store', id), form, {
            signal,
            headers: { Accept: 'application/json' },
            onUploadProgress: (event: AxiosProgressEvent) => onProgress(event.total ? Math.round((event.loaded / event.total) * 100) : 0),
        }).then((res) => res.data.data);
    },
    discardUpload: (attachmentId: string) => axios.delete(r('api.attachments.destroy', attachmentId)),
    downloadUrl: (attachmentId: string, inline = false) => r('api.attachments.download', inline ? [attachmentId, { inline: 1 }] : attachmentId),
    photoUrl: (userId: number) => r('api.directory.photo', userId),
    directory: (q: string, signal?: AbortSignal) => axios.get<{ data: Person[] }>(r('api.directory.search', { q }), { signal }).then((res) => res.data.data),
    startDirect: (userId: number) => axios.post<{ id: string }>(r('api.conversations.store'), { user_id: userId }).then((res) => res.data.id),
    summary: () => axios.get<Totals>(r('summary')).then((res) => res.data),
    heartbeat: () => axios.post(r('api.presence')),
};

/** First validation message from a 422, or null. */
export function firstError(error: unknown): string | null {
    const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return first ?? null;
}

export function statusOf(error: unknown): number | null {
    return (error as { response?: { status?: number } })?.response?.status ?? null;
}

/** A client id that makes a retried send idempotent on the server. */
export function newClientId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `c-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** Tells the sidebar badge (and the dashboard) to refresh its counts. */
export function announceTotalsChanged(): void {
    window.dispatchEvent(new CustomEvent('communication:totals-changed'));
}
