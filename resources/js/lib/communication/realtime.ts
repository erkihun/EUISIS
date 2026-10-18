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
    stopListening: (event: string) => EchoChannel;
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

/** Listens on a private channel; returns an unsubscribe that leaves the channel. */
export function listen(channel: string, handlers: Record<string, Listener>): () => void {
    const instance = echo();
    if (!instance) return () => {};
    const subscription = instance.private(channel);
    for (const [event, handler] of Object.entries(handlers)) subscription.listen(`.${event}`, handler);

    return () => {
        for (const event of Object.keys(handlers)) subscription.stopListening(`.${event}`);
        instance.leave(channel);
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
