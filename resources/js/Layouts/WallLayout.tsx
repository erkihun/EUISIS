import type { ReactNode } from 'react';

/**
 * Full-screen frame for the Situation Room wall display: no sidebar, no
 * menus, large type, theme-aware contrast. The page is still an
 * authenticated route; this layout only removes the chrome.
 */
export default function WallLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-[color:var(--app-background)] text-[color:var(--app-foreground)]">
            <main className="mx-auto flex min-h-screen max-w-[1920px] flex-col gap-6 p-4 sm:p-6 xl:p-8">{children}</main>
        </div>
    );
}
