import { Component, Suspense, lazy, useCallback, useEffect, useLayoutEffect, useRef, useState, type ErrorInfo, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import { AssistantIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { assistantDock, useAssistantDock } from './assistantDock';

/*
 * The one mount point of the assistant (AuthenticatedLayout). This file stays
 * small: the panel and everything it needs load on first open. The layout is
 * re-created on every Inertia visit, so the open/minimised state lives in
 * module variables; only `assistant.open` is persisted.
 *
 * The trigger floats at the bottom-right corner, except while a page shows an
 * AssistantDockButton in its own toolbar (assistantDock.ts): full-height
 * workspaces such as Communication keep that corner for their composer, its
 * send button and the "jump to latest" control.
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

    // Focus returns to whichever trigger is on screen: the page's dock button or the floating one.
    const focusTrigger = useCallback(() => {
        window.setTimeout(() => (assistantDock.element() ?? triggerRef.current)?.focus(), 0);
    }, []);

    const close = useCallback(() => {
        setOpen(false);
        focusTrigger();
    }, [setOpen, focusTrigger]);

    const minimize = useCallback(() => {
        shell.minimized = true;
        setMinimizedState(true);
        focusTrigger();
    }, [focusTrigger]);

    const toggle = useCallback(() => {
        if (open && minimized) {
            shell.minimized = false;
            setMinimizedState(false);
            return;
        }
        setOpen(!open);
    }, [open, minimized, setOpen]);

    const panelVisible = open && !minimized;

    // A page's own dock button drives the same panel; it learns the state from here.
    const { dock } = useAssistantDock();
    useEffect(() => assistantDock.publish(panelVisible, toggle), [panelVisible, toggle]);

    // A docked trigger sits at the top, so its panel opens just below it rather than over the page's bottom bar.
    const [anchorTop, setAnchorTop] = useState<number | null>(null);
    useLayoutEffect(() => {
        if (!dock || !panelVisible) return;
        const place = () => setAnchorTop(Math.round(dock.getBoundingClientRect().bottom + 8));
        place();
        window.addEventListener('resize', place);
        return () => window.removeEventListener('resize', place);
    }, [dock, panelVisible]);

    if (!enabled) return null;

    const trigger = dock
        ? null
        : (
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
                icon={<AssistantIcon className="h-5 w-5" />}
            />
        );

    return (
        <>
            {trigger}
            {loaded && (
                <AssistantErrorBoundary
                    fallback={(reset) => (
                        <div
                            role="alert"
                            className="fixed z-40 flex items-center gap-2 rounded-card border border-(--app-border) bg-(--app-surface) px-3 py-2 text-xs text-(--app-foreground) shadow-lg"
                            style={{ right: 'calc(1rem + env(safe-area-inset-right))', ...(anchorTop !== null && dock ? { top: anchorTop } : { bottom: 'calc(4.75rem + env(safe-area-inset-bottom))' }) }}
                        >
                            <span>{t('assistant.errors.panelFailed')}</span>
                            <Button variant="outline" size="xs" onClick={() => { reset(); close(); }}>{t('assistant.close')}</Button>
                        </div>
                    )}
                >
                    <Suspense fallback={null}>
                        <AssistantPanel open={panelVisible} onClose={close} onMinimize={minimize} anchorTop={dock ? anchorTop : null} />
                    </Suspense>
                </AssistantErrorBoundary>
            )}
        </>
    );
}
