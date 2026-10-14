import type { ReactNode } from 'react';
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
 * where they are, language, theme), then the screen. On a wider screen the same
 * phone-width column sits centred, so a desktop visitor sees the same app.
 * Forms pin their Send button to the bottom edge themselves (see
 * `FeedbackActionBar`).
 */
export default function FeedbackAppLayout({ title, subtitle, backHref, backLabel, children }: {
    /** What this screen is; also the browser tab title. */
    title: string;
    subtitle?: string | null;
    /** Where the back arrow goes. Without it the bar shows the brand mark. */
    backHref?: string;
    backLabel?: string;
    children: ReactNode;
}) {
    const { brandName, logoUrl } = useBranding();

    return (
        <LocalizedUiProvider>
            <Head title={title}>
                {/* Scanned-code pages carry a private token: never indexed, never sent on as a Referer. */}
                <meta head-key="robots" name="robots" content="noindex, nofollow, noarchive" />
                <meta head-key="referrer" name="referrer" content="no-referrer" />
            </Head>

            <div className="min-h-dvh bg-slate-100 dark:bg-slate-950">
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
                </div>
            </div>

            {/* Every outcome of a submission is a popup, wherever the visitor has scrolled. */}
            <AppToaster />
        </LocalizedUiProvider>
    );
}

/**
 * The bottom action bar of a form screen: the Send button stays under the
 * thumb while the visitor scrolls the form, and clears the home indicator.
 * Rendered as the last child of the <form>, so its button submits the form.
 */
export function FeedbackActionBar({ children }: { children: ReactNode }) {
    return (
        <div className="sticky bottom-0 z-20 mt-auto border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-4 pb-[max(12px,env(safe-area-inset-bottom))] pt-3">
            {children}
        </div>
    );
}
