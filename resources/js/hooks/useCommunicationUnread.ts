import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { communicationApi } from '@/lib/communication/api';

const REFRESH_MS = 60_000;

/**
 * Unread total for the Communication sidebar badge: starts from the shared
 * Inertia prop, refreshes when a chat page reports a change and once a minute
 * while the tab is visible. Null when the module is off for this person.
 */
export function useCommunicationUnread(): number | null {
    const shared = (usePage().props as { communication?: { unread: number } | null }).communication ?? null;
    const [unread, setUnread] = useState<number | null>(shared ? shared.unread : null);

    useEffect(() => {
        setUnread(shared ? shared.unread : null);
    }, [shared?.unread, shared]);

    useEffect(() => {
        if (!shared) return;
        let stopped = false;
        const refresh = () => {
            if (document.visibilityState !== 'visible') return;
            communicationApi.summary().then((totals) => { if (!stopped) setUnread(totals.total); }).catch(() => undefined);
        };
        const timer = window.setInterval(refresh, REFRESH_MS);
        window.addEventListener('communication:totals-changed', refresh);
        return () => {
            stopped = true;
            window.clearInterval(timer);
            window.removeEventListener('communication:totals-changed', refresh);
        };
    }, [shared]);

    return unread;
}
