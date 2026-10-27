import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { communicationApi } from '@/lib/communication/api';
import { channels, connectRealtime, listen } from '@/lib/communication/realtime';
import type { PageProps } from '@/types';

/** Fallback refresh while realtime is off or the socket is down. */
const POLL_MS = 60_000;
/** Several changes in a burst (a busy group) cost one summary request. */
const DEBOUNCE_MS = 800;

type SharedCommunication = { enabled: boolean; unread_count: number; realtime: boolean } | null;

/**
 * The person's total unread communication items, for the top-bar Messages
 * badge. Starts from the shared Inertia prop; afterwards it follows the
 * `unread.changed` signal on the person's own private channel (Reverb), the
 * `communication:totals-changed` event the workspace raises when something is
 * read, and a once-a-minute poll while the tab is visible. Null when
 * Communication is off for this person.
 */
export function useCommunicationUnreadCount(): number | null {
    const page = usePage<PageProps>();
    const shared = (page.props as { communication?: SharedCommunication }).communication ?? null;
    const userId = page.props.auth.user?.id as number | undefined;
    const [unread, setUnread] = useState<number | null>(shared ? shared.unread_count : null);

    useEffect(() => {
        setUnread(shared ? shared.unread_count : null);
    }, [shared?.unread_count, shared]);

    const enabled = shared !== null;
    const realtime = shared?.realtime ?? false;

    useEffect(() => {
        if (!enabled) return;
        let stopped = false;
        let timer: number | undefined;
        const refresh = () => {
            if (document.visibilityState !== 'visible') return;
            communicationApi.summary().then((totals) => { if (!stopped) setUnread(totals.total); }).catch(() => undefined);
        };
        const soon = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, DEBOUNCE_MS);
        };

        const poll = window.setInterval(refresh, POLL_MS);
        window.addEventListener('communication:totals-changed', soon);
        document.addEventListener('visibilitychange', refresh);

        let stopListening = () => {};
        if (realtime && userId) {
            void connectRealtime().then((connected) => {
                if (connected && !stopped) stopListening = listen(channels.user(userId), { 'unread.changed': soon });
            });
        }

        return () => {
            stopped = true;
            window.clearInterval(poll);
            window.clearTimeout(timer);
            window.removeEventListener('communication:totals-changed', soon);
            document.removeEventListener('visibilitychange', refresh);
            stopListening();
        };
    }, [enabled, realtime, userId]);

    return unread;
}

