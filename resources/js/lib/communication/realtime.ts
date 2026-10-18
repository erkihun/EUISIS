/**
 * Realtime for Communication over Laravel Echo + Reverb, when it is configured
 * (resources/js/echo.ts sets window.Echo). Every subscription is authorized by
 * the server (routes/channels.php); the payloads are ids and small facts.
 *
 * When Echo is not configured or the socket is down, `available()` is false
 * and the pages poll over HTTP instead, so chat keeps working without it.
 */

type Listener = (payload: Record<string, unknown>) => void;

type EchoChannel = {
    listen: (event: string, callback: Listener) => EchoChannel;
    /** With a callback, only that handler is removed (others on the same event keep listening). */
    stopListening: (event: string, callback?: Listener) => EchoChannel;
};

type EchoLike = {
    private: (name: string) => EchoChannel;
    leave: (name: string) => void;
    connector?: { pusher?: { connection?: { state?: string; bind: (event: string, cb: (states: { current: string }) => void) => void; unbind?: (event: string, cb: (states: { current: string }) => void) => void } } };
};

function echo(): EchoLike | null {
    return ((window as unknown as { Echo?: EchoLike }).Echo) ?? null;
}

export type RealtimeState = 'connected' | 'connecting' | 'unavailable';

export function realtimeState(): RealtimeState {
    const instance = echo();
    if (!instance) return 'unavailable';
    const state = instance.connector?.pusher?.connection?.state;
    if (state === 'connected') return 'connected';
    if (state === 'connecting' || state === 'initialized') return 'connecting';
    return 'unavailable';
}

/** Calls back on every connection state change; returns an unsubscribe. */
export function onRealtimeState(callback: (state: RealtimeState) => void): () => void {
    const connection = echo()?.connector?.pusher?.connection;
    if (!connection) return () => {};
    const handler = () => callback(realtimeState());
    connection.bind('state_change', handler);
    return () => connection.unbind?.('state_change', handler);
}

/*
 * Several parts of a page share one channel (the top-bar badge and the
 * Messages workspace both use the person's own channel), so the channel is
 * left only when its last listener goes; leaving earlier would silently cut
 * the others off.
 */
const subscribers = new Map<string, number>();

/** Listens on a private channel; returns an unsubscribe that leaves the channel once nobody else listens. */
export function listen(channel: string, handlers: Record<string, Listener>): () => void {
    const instance = echo();
    if (!instance) return () => {};
    const subscription = instance.private(channel);
    subscribers.set(channel, (subscribers.get(channel) ?? 0) + 1);
    for (const [event, handler] of Object.entries(handlers)) subscription.listen(`.${event}`, handler);

    let done = false;
    return () => {
        if (done) return;
        done = true;
        for (const [event, handler] of Object.entries(handlers)) subscription.stopListening(`.${event}`, handler);
        const left = (subscribers.get(channel) ?? 1) - 1;
        if (left > 0) {
            subscribers.set(channel, left);
        } else {
            subscribers.delete(channel);
            instance.leave(channel);
        }
    };
}

let connecting: Promise<boolean> | null = null;

/** Starts the socket once per page load; resolves false when realtime is not configured or fails to load. */
export function connectRealtime(): Promise<boolean> {
    if (echo()) return Promise.resolve(true);
    connecting ??= import('@/lib/communication/echo')
        .then(({ createEcho }) => createEcho())
        .catch(() => false);
    return connecting;
}

export const channels = {
    conversation: (id: string) => `communication.conversation.${id}`,
    user: (id: number) => `communication.user.${id}`,
    announcements: 'communication.announcements',
};
