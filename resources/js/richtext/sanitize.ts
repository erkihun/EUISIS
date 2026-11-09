/*
 * The viewer's defence in depth. Stored values were sanitized by the server
 * (RichTextSanitizer) when saved; this runs the same allowlist again in the
 * browser before anything reaches the DOM, so a row written before the
 * policy existed, or by a path that skipped the cast, still cannot run
 * script. Never a replacement for the server sanitizer.
 */
import DOMPurify, { type Config } from 'dompurify';
import { RICH_TEXT_POLICY, allowedAttributes, isAllowedUrl } from './policy';

const ALL_ATTRIBUTES = Array.from(new Set(RICH_TEXT_POLICY.tags.flatMap((tag) => allowedAttributes(tag))));

const CONFIG: Config = {
    ALLOWED_TAGS: [...RICH_TEXT_POLICY.tags],
    ALLOWED_ATTR: ALL_ATTRIBUTES,
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    // Drop the contents of these entirely rather than unwrapping them as text.
    FORBID_CONTENTS: ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math'],
    KEEP_CONTENT: true,
};

const purifier = typeof window === 'undefined' ? null : DOMPurify(window);

purifier?.addHook('uponSanitizeAttribute', (node, data) => {
    const tag = node.nodeName.toLowerCase();

    if (!allowedAttributes(tag).includes(data.attrName)) {
        data.keepAttr = false;
        return;
    }

    if (data.attrName === 'style') {
        const match = /^\s*text-align\s*:\s*([a-z]+)\s*;?\s*$/i.exec(data.attrValue);
        const alignment = match?.[1].toLowerCase();
        if (alignment && RICH_TEXT_POLICY.alignments.includes(alignment)) {
            data.attrValue = `text-align: ${alignment};`;
        } else {
            data.keepAttr = false;
        }
    }

    if (data.attrName === 'href' && !isAllowedUrl(data.attrValue)) {
        data.keepAttr = false;
    }

    if (data.attrName === 'target' && !RICH_TEXT_POLICY.targets.includes(data.attrValue)) {
        data.keepAttr = false;
    }
});

purifier?.addHook('afterSanitizeAttributes', (node) => {
    if (node.nodeName.toLowerCase() === 'a' && node.getAttribute('target') === '_blank') {
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

/** Stored rich text, reduced to the shared allowlist. Empty string on the server side of SSR. */
export function sanitizeRichText(html: string): string {
    return purifier ? String(purifier.sanitize(html, CONFIG)) : '';
}
