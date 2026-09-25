import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslator } from '@/hooks/useLocale';

export type SessionPolicy = {
    idle_timeout_seconds: number;
    warning_seconds: number;
    heartbeat_seconds: number;
    login_url: string;
    logout_url: string;
};

type SessionState = { remaining_seconds: number };

const INTERACTION_EVENTS = ['pointerdown', 'keydown', 'input', 'wheel', 'touchstart'] as const;
const PASSIVE = { 'X-Activity': 'passive' };
const TICK_MS = 15_000;

/**
 * The browser half of the session idle policy (docs/session-management.md).
 *
 * The server owns the clock; this component only keeps a signed-in user who
 * is genuinely working from being signed out, and warns one who is not:
 *
 *  - real interaction (typing, clicking) with no request reaching the server
 *    sends one heartbeat per `heartbeat_seconds` — an open, untouched tab
 *    sends nothing;
 *  - near expiry it asks the server (passively) how much time is left, so
 *    work in another tab counts, and only then shows the warning;
 *  - showing the warning does not extend anything; "Stay signed in" does, by
 *    sending a heartbeat the server may still refuse.
 *
 * Mounted beside <App>, so it covers every portal; it is inert on pages with
 * no `session_policy` prop (signed out).
 */
export default function SessionTimeoutManager({ initialPolicy }: { initialPolicy: SessionPolicy | null }) {
    const { t } = useTranslator();
    const [policy, setPolicy] = useState<SessionPolicy | null>(initialPolicy);
    const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
    const lastServerActivity = useRef(Date.now());
    const lastInteraction = useRef(0);
    const busy = useRef(false);
    const heartbeatRefused = useRef(false);
    const channel = useRef<BroadcastChannel | null>(null);

    const label = (key: string, params?: Record<string, string>) => {
        let text = t(`auth.session.${key}`);
        Object.entries(params ?? {}).forEach(([name, value]) => { text = text.replace(`{${name}}`, value); });
        return text;
    };

    /** The session is over: go to sign-in. The server has set the message. */
    const leave = useCallback((url?: string) => {
        window.location.assign(url || policy?.login_url || '/login');
    }, [policy]);

    const adopt = useCallback((state: SessionState, timeout: number) => {
        lastServerActivity.current = Date.now() - Math.max(0, timeout - state.remaining_seconds) * 1000;
    }, []);

    const handleFailure = useCallback((error: unknown) => {
        if (!axios.isAxiosError(error) || !error.response) return; // offline: try again next tick
        const { status, data } = error.response as { status: number; data?: { redirect?: string } };
        if (status === 401 || status === 419) leave(data?.redirect);
        if (status === 403) heartbeatRefused.current = true; // e.g. forced password change screen
    }, [leave]);

    const syncStatus = useCallback(async (current: SessionPolicy) => {
        busy.current = true;
        try {
            const { data } = await axios.get<SessionState>(route('session.status'), { headers: PASSIVE });
            adopt(data, current.idle_timeout_seconds);
            setSecondsLeft(data.remaining_seconds <= current.warning_seconds ? data.remaining_seconds : null);
        } catch (error) {
            handleFailure(error);
        } finally {
            busy.current = false;
        }
    }, [adopt, handleFailure]);

    const heartbeat = useCallback(async (current: SessionPolicy) => {
        busy.current = true;
        try {
            const { data } = await axios.post<SessionState>(route('session.activity'));
            adopt(data, current.idle_timeout_seconds);
            setSecondsLeft(null);
        } catch (error) {
            handleFailure(error);
        } finally {
            busy.current = false;
        }
    }, [adopt, handleFailure]);

    // Each page carries the current policy; none means signed out.
    useEffect(() => router.on('navigate', (event) => {
        const next = (event.detail.page.props as { session_policy?: SessionPolicy | null }).session_policy ?? null;
        setPolicy((previous) => {
            if (previous && !next) channel.current?.postMessage('signed-out');
            return next;
        });
        if (!next) setSecondsLeft(null);
    }), []);

    // A completed, non-passive page visit is activity the server has recorded.
    useEffect(() => router.on('finish', (event) => {
        const visit = event.detail.visit;
        const headers = (visit.headers ?? {}) as Record<string, string>;
        if (visit.completed && headers['X-Activity'] !== 'passive') {
            lastServerActivity.current = Date.now();
            setSecondsLeft(null);
        }
    }), []);

    // Signing out in one tab takes the others to sign-in too.
    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined') return;
        channel.current = new BroadcastChannel('euisis-session');
        channel.current.onmessage = (message) => {
            if (message.data === 'signed-out' && policy) void syncStatus(policy);
        };
        return () => { channel.current?.close(); channel.current = null; };
    }, [policy, syncStatus]);

    useEffect(() => {
        if (!policy) return;
        const mark = () => { lastInteraction.current = Date.now(); };
        INTERACTION_EVENTS.forEach((name) => window.addEventListener(name, mark, { capture: true, passive: true }));
        return () => INTERACTION_EVENTS.forEach((name) => window.removeEventListener(name, mark, { capture: true }));
    }, [policy]);

    useEffect(() => {
        if (!policy) return;
        const tick = () => {
            if (busy.current || secondsLeft !== null || heartbeatRefused.current) return;
            const idleFor = (Date.now() - lastServerActivity.current) / 1000;
            const interacted = lastInteraction.current > lastServerActivity.current;

            if (interacted && !heartbeatRefused.current && idleFor >= policy.heartbeat_seconds) {
                void heartbeat(policy);
            } else if (idleFor >= policy.idle_timeout_seconds - policy.warning_seconds) {
                void syncStatus(policy);
            }
        };
        const id = window.setInterval(tick, TICK_MS);
        document.addEventListener('visibilitychange', tick);
        return () => { window.clearInterval(id); document.removeEventListener('visibilitychange', tick); };
    }, [policy, secondsLeft, heartbeat, syncStatus]);

    // Countdown while the warning is open; at zero the server decides.
    useEffect(() => {
        if (secondsLeft === null || !policy) return;
        if (secondsLeft <= 0) {
            void syncStatus(policy);
            return;
        }
        const id = window.setTimeout(() => setSecondsLeft((s) => (s === null ? null : s - 1)), 1000);
        return () => window.clearTimeout(id);
    }, [secondsLeft, policy, syncStatus]);

    if (!policy) return null;

    const remaining = Math.max(0, secondsLeft ?? 0);
    const time = `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;

    return (
        <Dialog open={secondsLeft !== null} onClose={() => undefined} className="relative z-[100]">
            <DialogBackdrop className="fixed inset-0 bg-slate-950/50" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-md rounded-xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6 text-[color:var(--app-foreground)] shadow-xl">
                    <DialogTitle className="text-lg font-semibold">{label('warningTitle')}</DialogTitle>
                    <p className="mt-2 text-sm text-[color:var(--app-muted-foreground)]">{label('warningBody')}</p>
                    <p className="mt-3 text-sm font-medium" aria-live="polite">{label('warningCountdown', { time })}</p>
                    <div className="mt-6 flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => router.post(policy.logout_url)}
                            className="rounded-lg border border-[color:var(--app-border)] px-4 py-2 text-sm font-medium hover:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]"
                        >
                            {label('signOut')}
                        </button>
                        <button
                            type="button"
                            data-autofocus
                            onClick={() => void heartbeat(policy)}
                            className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-primary)]"
                        >
                            {label('staySignedIn')}
                        </button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
