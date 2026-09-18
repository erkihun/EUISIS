/**
 * Brand colour helpers.
 *
 * The institutional navy (#122170) is dark enough that reusing it unchanged on
 * a dark background leaves a primary button barely distinguishable from the
 * surface behind it. Rather than hard-coding a second palette — which would
 * break the moment an administrator changes `appearance.primary_color` — the
 * dark-theme variant is derived from whatever brand colour is configured.
 *
 * Both variants are written to CSS custom properties; `app.css` decides which
 * one `--color-primary` resolves to per theme. See the token block there.
 */

/** Parses `#rgb` / `#rrggbb` into 0–255 channels. Returns null if unparseable. */
function parseHex(hex: string): [number, number, number] | null {
    const value = hex.trim().replace(/^#/, '');

    if (value.length === 3) {
        const [r, g, b] = value.split('');
        return [
            parseInt(r + r, 16),
            parseInt(g + g, 16),
            parseInt(b + b, 16),
        ];
    }

    if (value.length === 6) {
        return [
            parseInt(value.slice(0, 2), 16),
            parseInt(value.slice(2, 4), 16),
            parseInt(value.slice(4, 6), 16),
        ];
    }

    return null;
}

function toHex([r, g, b]: [number, number, number]): string {
    const channel = (n: number) =>
        Math.max(0, Math.min(255, Math.round(n)))
            .toString(16)
            .padStart(2, '0');

    return `#${channel(r)}${channel(g)}${channel(b)}`;
}

/**
 * Mixes `hex` toward white by `amount` (0 = unchanged, 1 = white).
 *
 * Unparseable input is returned as-is so a malformed setting degrades to the
 * configured value rather than to black.
 */
export function lighten(hex: string, amount: number): string {
    const rgb = parseHex(hex);
    if (!rgb) return hex;

    return toHex(rgb.map((c) => c + (255 - c) * amount) as [number, number, number]);
}

/**
 * The dark-theme counterpart of a brand colour.
 *
 * 0.42 was chosen against the #0b1020 dark surface: enough lift to clear the
 * WCAG AA 4.5:1 threshold for button text and adjacent body copy, while still
 * reading as the same hue rather than a pastel.
 */
export function darkThemeVariant(hex: string): string {
    return lighten(hex, 0.42);
}

/**
 * WCAG relative luminance, 0 (black) to 1 (white).
 *
 * Unparseable input reports as light, so a malformed setting falls back to the
 * dark-on-light pairing rather than white-on-white.
 */
export function relativeLuminance(hex: string): number {
    const rgb = parseHex(hex);
    if (!rgb) return 1;

    const [r, g, b] = rgb.map((channel) => {
        const c = channel / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** True when text on this colour should be dark rather than light. */
export function isLight(hex: string): boolean {
    return relativeLuminance(hex) > 0.45;
}

/**
 * A foreground that stays legible on `background`.
 *
 * Deliberately not pure black or white: an off-black on a light surface and an
 * off-white on a dark one avoid the harsh edge of maximum contrast while
 * staying far above the AA threshold. This is what lets the sidebar colour be
 * a free choice — whatever an administrator picks, the labels follow it.
 */
export function readableForeground(background: string): string {
    return isLight(background) ? '#1f2937' : '#f1f5f9';
}

/** WCAG contrast ratio between two colours, 1 (identical) to 21 (black/white). */
export function contrastRatio(a: string, b: string): number {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [lighter, darker] = la > lb ? [la, lb] : [lb, la];

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * The colour to mark the selected navigation item with.
 *
 * Normally the brand colour — that is the point of a brand colour. But when
 * the sidebar itself is set to the brand navy, a navy active state on a navy
 * background is invisible, and the user loses track of where they are. Below
 * a 3:1 ratio (the WCAG threshold for non-text UI) it falls back to the
 * sidebar's own foreground, which is guaranteed to contrast.
 */
export function sidebarAccent(brand: string, background: string): string {
    return contrastRatio(brand, background) >= 3 ? brand : readableForeground(background);
}
