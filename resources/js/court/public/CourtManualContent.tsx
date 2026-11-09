import { useMemo } from 'react';
import DOMPurify from 'dompurify';
import { cx } from '@/Components/ui';

/*
 * The user manual's own allowlist: the tags the manual's editor writes, with
 * their attributes (inline colours, direction, table spans, links).
 * The shared rich-text policy is narrower (no colours, no direction), so the
 * manual keeps this one: the server purifies the HTML (HTMLPurifier) when it is
 * saved and again when it is sent, and the browser cleans it once more here
 * before it reaches the DOM.
 */
const MANUAL_TAGS = [
    'p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'pre', 'code',
    'ul', 'ol', 'li', 'blockquote', 'a', 'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'hr', 'div', 'span', 'figure', 'figcaption',
];
const MANUAL_ATTRIBUTES = ['href', 'title', 'target', 'rel', 'style', 'dir', 'lang', 'colspan', 'rowspan', 'span', 'align'];

const purifier = typeof window === 'undefined' ? null : DOMPurify(window);

/** The manual, as the Blade page printed it, cleaned again in the browser. */
export default function CourtManualContent({ html, className }: { html: string; className?: string }) {
    const safe = useMemo(() => purifier?.sanitize(html, {
        ALLOWED_TAGS: MANUAL_TAGS,
        ALLOWED_ATTR: MANUAL_ATTRIBUTES,
        ALLOW_DATA_ATTR: false,
        FORBID_CONTENTS: ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math'],
    }) ?? '', [html]);

    return (
        <div
            className={cx(
                'rich-text text-base leading-7 text-slate-700 wrap-anywhere dark:text-slate-200',
                '[&_h1]:text-2xl [&_h1]:font-bold [&_h1]:text-slate-900 dark:[&_h1]:text-white',
                '[&_h2]:border-b [&_h2]:border-(--app-border) [&_h2]:pb-1.5 [&_h2]:text-xl [&_h2]:font-bold',
                '[&_h5]:font-semibold [&_h6]:font-semibold [&_hr]:my-8 [&_hr]:border-(--app-border)',
                '[&_pre]:overflow-x-auto [&_pre]:rounded-card [&_pre]:bg-(--app-surface-muted) [&_pre]:p-3 [&_code]:font-mono [&_code]:text-sm',
                '[&_blockquote]:rounded-e-card [&_blockquote]:border-blue-500 [&_blockquote]:bg-blue-50 [&_blockquote]:px-5 [&_blockquote]:py-4 dark:[&_blockquote]:bg-blue-950/40',
                '[&_s]:line-through [&_em]:italic [&_i]:italic [&_b]:font-semibold',
                className,
            )}
            // Sanitized above with DOMPurify against the manual's allowlist (and on the server when saved).
            dangerouslySetInnerHTML={{ __html: safe }}
        />
    );
}
