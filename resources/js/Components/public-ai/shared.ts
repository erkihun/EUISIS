/*
 * Small pieces the trigger, the panel and the blocks share. Kept outside the
 * component files so the lazily loaded panel never imports the mount point.
 */

/**
 * Where the floating pieces sit above the bottom edge: clear of the QR
 * screens' dock and action bar (both publish their measured height as a CSS
 * custom property; the site pages have neither, so the terms are 0px).
 */
const BOTTOM_STACK = 'var(--feedback-dock-height, 0px) + var(--feedback-action-bar-height, 0px) + env(safe-area-inset-bottom)';
export const TRIGGER_BOTTOM = `calc(${BOTTOM_STACK} + 1rem)`;
export const TRIGGER_RIGHT = 'calc(1rem + env(safe-area-inset-right))';
/** Trigger (3rem) + its gap (1rem) + the usual margin (0.75rem). */
export const PANEL_BOTTOM = `calc(${BOTTOM_STACK} + 4.75rem)`;

export type SafeHrefKind = 'app' | 'tel' | 'mailto';

/**
 * The only links a block may open: an app-relative path (never `//host` or
 * `/\host`, which browsers read as another origin), a phone number or an
 * e-mail address. Everything else is dropped, whatever the server sent.
 */
export function hrefKind(href: unknown): SafeHrefKind | null {
    if (typeof href !== 'string' || href === '') return null;
    if (href.startsWith('/')) return /^\/[\\/]/.test(href) ? null : 'app';
    if (/^tel:\+?[0-9][0-9\s().-]{2,}$/i.test(href)) return 'tel';
    if (/^mailto:[^\s@/?#]+@[^\s@/?#]+$/i.test(href)) return 'mailto';
    return null;
}

export function isAppHref(href: unknown): href is string {
    return hrefKind(href) === 'app';
}

/** A named route's URL, or the literal path when the route is not in the registry. */
export function safeRoute(name: string, fallback: string): string {
    try {
        return route(name);
    } catch {
        return fallback;
    }
}
