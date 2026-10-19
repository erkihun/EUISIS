import { Component, Suspense, lazy, useCallback, useRef, useState, type ErrorInfo, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import { ChatIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

/*
 * The one mount point of the Addis One Assistant (AuthenticatedLayout). This
 * file stays small: the panel and everything it needs load on first open.
 * The layout is re-created on every Inertia visit, so the open/minimised
 * state lives in module variables; only `assistant.open` is persisted.
 */

const AssistantPanel = lazy(() => import('./AssistantPanel'));

const OPEN_KEY = 'assistant.open';

function readStoredOpen(): boolean {
    try {
        return localStorage.getItem(OPEN_KEY) === 'true';
    } catch {
        return false;
    }
}

function storeOpen(open: boolean): void {
    try {
        localStorage.setItem(OPEN_KEY, open ? 'true' : 'false');
    } catch {
        // Storage can be unavailable (private mode, blocked); the state still works for the visit.
    }
}

const shell = { open: null as boolean | null, minimized: false, loaded: false };

class AssistantErrorBoundary extends Component<{ fallback: (reset: () => void) => ReactNode; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        if (import.meta.env.DEV) console.error('Assistant panel failed', error, info);
    }

    render() {
        if (this.state.failed) return this.props.fallback(() => this.setState({ failed: false }));
        return this.props.children;
    }
}

export default function GlobalAIAssistant() {
    const { t } = useLocale();
    const enabled = usePage<PageProps>().props.assistant?.enabled === true;
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpenState] = useState<boolean>(() => {
        if (shell.open === null) shell.open = readStoredOpen();
        return shell.open;
    });
    const [minimized, setMinimizedState] = useState<boolean>(shell.minimized);
    const [loaded, setLoaded] = useState<boolean>(shell.loaded || (shell.open ?? false));

    const setOpen = useCallback((next: boolean) => {
        shell.open = next;
        shell.minimized = false;
        storeOpen(next);
        setOpenState(next);
        setMinimizedState(false);
        if (next) {
            shell.loaded = true;
            setLoaded(true);
        }
    }, []);

    const close = useCallback(() => {
        setOpen(false);
        window.setTimeout(() => triggerRef.current?.focus(), 0);
    }, [setOpen]);

    const minimize = useCallback(() => {
        shell.minimized = true;
        setMinimizedState(true);
        window.setTimeout(() => triggerRef.current?.focus(), 0);
    }, []);

    const toggle = useCallback(() => {
        if (open && minimized) {
            shell.minimized = false;
            setMinimizedState(false);
            return;
        }
        setOpen(!open);
    }, [open, minimized, setOpen]);

    if (!enabled) return null;

    const panelVisible = open && !minimized;

    return (
        <>
            <Button
                ref={triggerRef}
                variant="primary"
                size="icon"
                className="fixed z-40 h-11 w-11 rounded-full shadow-lg"
                style={{ right: 'calc(1rem + env(safe-area-inset-right))', bottom: 'calc(1rem + env(safe-area-inset-bottom))' }}
                onClick={toggle}
                aria-label={t('assistant.title')}
                title={t('assistant.title')}
                aria-haspopup="dialog"
                aria-expanded={panelVisible}
                icon={<ChatIcon className="h-5 w-5" />}
            />
            {loaded && (
                <AssistantErrorBoundary
                    fallback={(reset) => (
                        <div
                            role="alert"
                            className="fixed z-40 flex items-center gap-2 rounded-card border border-(--app-border) bg-(--app-surface) px-3 py-2 text-xs text-(--app-foreground) shadow-lg"
                            style={{ right: 'calc(1rem + env(safe-area-inset-right))', bottom: 'calc(4.75rem + env(safe-area-inset-bottom))' }}
                        >
                            <span>{t('assistant.errors.panelFailed')}</span>
                            <Button variant="outline" size="xs" onClick={() => { reset(); close(); }}>{t('assistant.close')}</Button>
                        </div>
                    )}
                >
                    <Suspense fallback={null}>
                        <AssistantPanel open={panelVisible} onClose={close} onMinimize={minimize} />
                    </Suspense>
                </AssistantErrorBoundary>
            )}
        </>
    );
}
