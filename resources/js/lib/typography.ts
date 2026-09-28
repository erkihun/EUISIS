/**
 * Application web fonts for documents rendered outside the page's own DOM.
 *
 * An html-to-image capture is drawn through an SVG image, and an SVG image
 * cannot use the page's web fonts — without them an exported ID card falls
 * back to Arial/Nyala while the preview shows Inter / Noto Sans Ethiopic.
 * A print window is a separate document with no stylesheet of its own.
 *
 * Both get the same @font-face rules the page already declares (fonts.css),
 * read from the loaded stylesheets, so nothing here names a font file.
 */

/** Families declared in resources/css/fonts.css. */
const APP_FONT_FAMILIES = ['Inter', 'Noto Sans Ethiopic'];

const URL_PATTERN = /url\((['"]?)([^'")]+)\1\)/g;

const unquote = (value: string): string => value.replace(/["']/g, '').trim();

function appFontFaceRules(): CSSFontFaceRule[] {
    const rules: CSSFontFaceRule[] = [];

    for (const sheet of Array.from(document.styleSheets)) {
        let cssRules: CSSRuleList;
        try {
            cssRules = sheet.cssRules;
        } catch {
            continue; // A cross-origin sheet; the app's own fonts are never in one.
        }

        for (const rule of Array.from(cssRules)) {
            if (rule instanceof CSSFontFaceRule && APP_FONT_FAMILIES.includes(unquote(rule.style.getPropertyValue('font-family')))) {
                rules.push(rule);
            }
        }
    }

    return rules;
}

const absolute = (url: string, rule: CSSFontFaceRule): string => new URL(url, rule.parentStyleSheet?.href ?? window.location.href).href;

/**
 * Only the faces this page has actually loaded — the weights and scripts in
 * use — so a capture does not inline every file. Falls back to all rules when
 * the browser's font list cannot be matched.
 */
function loadedRules(): CSSFontFaceRule[] {
    const rules = appFontFaceRules();
    const loaded = new Set(
        Array.from(document.fonts ?? [])
            .filter((face) => face.status === 'loaded')
            .map((face) => `${unquote(face.family)}|${face.weight}|${face.unicodeRange}`),
    );

    const used = rules.filter((rule) => loaded.has(
        `${unquote(rule.style.getPropertyValue('font-family'))}|${rule.style.getPropertyValue('font-weight')}|${rule.style.getPropertyValue('unicode-range') || 'U+0-10FFFF'}`,
    ));

    return used.length > 0 ? used : rules;
}

async function toDataUrl(url: string): Promise<string> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`Font request failed: ${response.status}`);

    const blob = await response.blob();
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
    });
}

const inlined = new Map<string, Promise<string>>();

/** @font-face CSS with the font files inlined, for html-to-image's `fontEmbedCSS`. */
export async function embeddedAppFontCss(): Promise<string> {
    const parts = await Promise.all(loadedRules().map(async (rule) => {
        let css = rule.cssText;
        for (const [match, , url] of rule.cssText.matchAll(URL_PATTERN)) {
            const href = absolute(url, rule);
            if (!inlined.has(href)) {
                const request = toDataUrl(href);
                request.catch(() => inlined.delete(href)); // allow a retry on the next export
                inlined.set(href, request);
            }
            css = css.replace(match, `url("${await inlined.get(href)}")`);
        }
        return css;
    }));

    return parts.join('\n');
}

/**
 * html-to-image font options: the app's fonts inlined when possible, else
 * `skipFonts` so an export still succeeds with system fonts.
 */
export async function captureFontOptions(): Promise<{ fontEmbedCSS: string } | { skipFonts: true }> {
    try {
        const fontEmbedCSS = await embeddedAppFontCss();
        if (fontEmbedCSS) return { fontEmbedCSS };
    } catch (error) {
        if (import.meta.env.DEV) console.warn('[typography] could not embed fonts for capture', error);
    }

    return { skipFonts: true };
}

/** @font-face CSS with absolute URLs, for a same-origin print window. */
export function appFontFaceCss(): string {
    return appFontFaceRules()
        .map((rule) => rule.cssText.replace(URL_PATTERN, (_match, _quote, url: string) => `url("${absolute(url, rule)}")`))
        .join('\n');
}

/** A typography token's current value, e.g. `cssToken('--font-ui')`. */
export function cssToken(name: string): string {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
