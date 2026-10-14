import { useCallback, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import AppToaster from '@/Components/ui/AppToaster';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { ChevronLeft, QrCodeIcon } from '@/Components/NavIcons';
import { useBranding } from '@/hooks/useBranding';

/**
 * The frame of the QR feedback pages: a screen of a phone app, not a page of
 * the public site.
 *
 * A visitor arrives by scanning a code at a service desk, on a phone, with one
 * job to do. So there is no site menu and no footer: a slim top bar (back,
 * where they are, language, theme), the screen, and — on every screen of the
 * flow — the same bottom navigation (`dock`), fixed to the bottom edge. A form
 * screen pins its Send button directly above it (see `FeedbackActionBar`). On a
 * wider screen the same phone-width column sits centred, so a desktop visitor
 * sees the same app.
 */
export default function FeedbackAppLayout({ title, subtitle, backHref, backLabel, dock, children }: {
    /** What this screen is; also the browser tab title. */
    title: string;
    subtitle?: string | null;
    /** Where the back arrow goes. Without it the bar shows the brand mark. */
    backHref?: string;
    backLabel?: string;
    /** The bottom navigation, fixed to the bottom edge of every screen that has one. */
    dock?: ReactNode;
    children: ReactNode;
}) {
    const { brandName, logoUrl } = useBranding();
    const [dockRef, dockHeight] = useMeasuredHeight<HTMLDivElement>();

    return (
        <LocalizedUiProvider>
            <Head title={title}>
                {/* Scanned-code pages carry a private token: never indexed, never sent on as a Referer. */}
                <meta head-key="robots" name="robots" content="noindex, nofollow, noarchive" />
                <meta head-key="referrer" name="referrer" content="no-referrer" />
            </Head>

            <div className="min-h-dvh bg-slate-100 dark:bg-slate-950" style={bottomSpacing(dockHeight)}>
                <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col bg-[color:var(--app-background)] sm:border-x sm:border-[color:var(--app-border)] sm:shadow-xl">
                    <header className="sticky top-0 z-30 border-b border-[color:var(--app-border)] bg-[color:var(--app-surface)] pt-[env(safe-area-inset-top)]">
                        <div className="flex h-14 items-center gap-1 px-2">
                            {backHref ? (
                                <Link
                                    href={backHref}
                                    aria-label={backLabel}
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[color:var(--app-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[color:var(--color-primary)]"
                                >
                                    <ChevronLeft className="h-6 w-6" aria-hidden="true" />
                                </Link>
                            ) : (
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center" aria-hidden="true">
                                    {logoUrl
                                        ? <img src={logoUrl} alt="" width={32} height={32} decoding="async" className="h-8 w-8 object-contain" />
                                        : <QrCodeIcon className="h-6 w-6 text-[color:var(--color-primary)]" />}
                                </span>
                            )}
                            <div className="min-w-0 flex-1 px-1">
                                <h1 className="truncate text-[15px] font-semibold leading-tight text-[color:var(--app-foreground)]">{title}</h1>
                                <p className="truncate text-xs leading-tight text-[color:var(--app-muted-foreground)]">{subtitle || brandName}</p>
                            </div>
                            <LanguageSwitcher variant="toolbar" />
                            <ThemeToggle variant="toolbar" />
                        </div>
                    </header>

                    <main id="main-content" className="flex flex-1 flex-col">
                        {children}
                    </main>

                    {/* The dock covers the bottom of the screen, so the end of every screen scrolls clear of it. */}
                    <div className="h-[var(--feedback-dock-height,0px)] shrink-0" aria-hidden="true" />
                </div>

                {dock && (
                    <div ref={dockRef} className="fixed inset-x-0 bottom-0 z-30 mx-auto w-full max-w-md">
                        {dock}
                    </div>
                )}
            </div>

            {/* Every outcome of a submission is a popup, wherever the visitor has scrolled. */}
            <AppToaster />
        </LocalizedUiProvider>
    );
}

/**
 * What the fixed bottom of the screen takes up, published to the whole frame as
 * custom properties: the dock's own height, and the room a bar sitting on top of
 * the dock needs below its button. Both are measured or derived rather than
 * guessed, so a dock whose labels wrap in one language still leaves the screen
 * above it fully reachable.
 */
function bottomSpacing(dockHeight: number): CSSProperties {
    return {
        '--feedback-dock-height': `${dockHeight}px`,
        // The dock already clears the home indicator; with no dock the bar clears it itself.
        '--feedback-action-bar-padding': 'max(12px, calc(env(safe-area-inset-bottom) - var(--feedback-dock-height)))',
    } as CSSProperties;
}

/**
 * The rendered height of an element, kept current while it is on screen.
 * A callback ref, so the first measurement lands in the same commit the element
 * appears in and nothing shifts after paint.
 */
function useMeasuredHeight<T extends HTMLElement>(): [(node: T | null) => void, number] {
    const [height, setHeight] = useState(0);
    const observer = useRef<ResizeObserver | null>(null);

    useEffect(() => () => observer.current?.disconnect(), []);

    const ref = useCallback((node: T | null) => {
        observer.current?.disconnect();
        observer.current = null;

        if (node === null) {
            setHeight(0);

            return;
        }

        setHeight(node.getBoundingClientRect().height);

        if (typeof ResizeObserver === 'undefined') return;

        observer.current = new ResizeObserver(() => setHeight(node.getBoundingClientRect().height));
        observer.current.observe(node);
    }, []);

    return [ref, height];
}

/**
 * The bottom action bar of a form or receipt screen: the Send button stays under
 * the thumb while the visitor scrolls, directly above the dock and clear of the
 * home indicator. Rendered as the last child of the <form>, so its button
 * submits the form. It is fixed to the screen at every width (sticky
 * positioning was not reliable in every phone browser), so the spacer below
 * keeps the last field clear of it.
 */
export function FeedbackActionBar({ children }: { children: ReactNode }) {
    return (
        <>
            <div className="mt-auto h-[calc(4.25rem+var(--feedback-action-bar-padding))] shrink-0" aria-hidden="true" />
            <div className="fixed inset-x-0 bottom-[var(--feedback-dock-height,0px)] z-30 mx-auto w-full max-w-md border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 pb-[var(--feedback-action-bar-padding)] pt-3 shadow-[0_-1px_8px_rgba(15,23,42,0.06)] sm:border-x sm:border-[color:var(--app-border)]">
                {children}
            </div>
        </>
    );
}
