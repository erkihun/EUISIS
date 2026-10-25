import { Component, Suspense, lazy, useCallback, useRef, useState, type ErrorInfo, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import { ChatIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { PANEL_BOTTOM, TRIGGER_BOTTOM, TRIGGER_RIGHT } from './shared';

/*
 * The one mount point of Addis One Help (PublicLayout and FeedbackAppLayout).
 * This file stays small: the panel and everything it needs load on the first
 * open, and nothing here imports the authenticated assistant. The layouts are
 * re-created on every Inertia visit, so whether the visitor left the panel open
 * lives in a module variable for the tab — never in storage, never auto-opened.
 */

const PublicAssistantPanel = lazy(() => import('./PublicAssistantPanel'));

const shell = { open: false, loaded: false };

class PanelErrorBoundary extends Component<{ fallback: (reset: () => void) => ReactNode; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        if (import.meta.env.DEV) console.error('Public help panel failed', error, info);
    }

    render() {
        if (this.state.failed) return this.props.fallback(() => this.setState({ failed: false }));
        return this.props.children;
    }
}

export default function PublicAssistant() {
    const { t } = useLocale();
    const enabled = usePage<PageProps>().props.publicAssistant?.enabled === true;
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpenState] = useState<boolean>(shell.open);
    const [loaded, setLoaded] = useState<boolean>(shell.loaded);

    const setOpen = useCallback((next: boolean) => {
        shell.open = next;
        setOpenState(next);
        if (next) {
            shell.loaded = true;
            setLoaded(true);
        }
    }, []);

    const close = useCallback(() => {
        setOpen(false);
        window.setTimeout(() => triggerRef.current?.focus(), 0);
    }, [setOpen]);

    const toggle = useCallback(() => setOpen(!open), [open, setOpen]);

    if (!enabled) return null;

    return (
        <>
            <Button
                ref={triggerRef}
                variant="primary"
                size="icon"
                className="fixed z-40 h-12 w-12 rounded-full shadow-lg motion-safe:transition-transform motion-safe:hover:scale-105"
                style={{ right: TRIGGER_RIGHT, bottom: TRIGGER_BOTTOM }}
                onClick={toggle}
                aria-label={t('publicAssistant.title')}
                title={open ? t('publicAssistant.close') : t('publicAssistant.open')}
                aria-haspopup="dialog"
                aria-expanded={open}
                icon={<ChatIcon className="h-6 w-6" />}
            />
            {loaded && (
                <PanelErrorBoundary
                    fallback={(reset) => (
                        <div
                            role="alert"
                            className="fixed z-40 flex items-center gap-2 rounded-card border border-(--app-border) bg-(--app-surface) px-3 py-2 text-xs text-(--app-foreground) shadow-lg"
                            style={{ right: TRIGGER_RIGHT, bottom: PANEL_BOTTOM }}
                        >
                            <span>{t('publicAssistant.errors.panelFailed')}</span>
                            <Button variant="outline" size="xs" onClick={() => { reset(); close(); }}>{t('publicAssistant.close')}</Button>
                        </div>
                    )}
                >
                    <Suspense fallback={null}>
                        <PublicAssistantPanel open={open} onClose={close} />
                    </Suspense>
                </PanelErrorBoundary>
            )}
        </>
    );
}
