import { useMemo } from 'react';
import { cx } from '@/Components/ui';
import { isRichTextEmpty, isRichTextHtml } from '@/richtext/policy';
import { sanitizeRichText } from '@/richtext/sanitize';

interface RichTextViewerProps {
    /** Stored value: sanitized HTML, or plain text saved before the field was formatted. */
    html: string | null | undefined;
    /** Shown when there is nothing to read. Omit to render nothing. */
    empty?: React.ReactNode;
    className?: string;
    /** `lang` for the text when it differs from the page (an Amharic field on an English page). */
    lang?: 'en' | 'am';
    id?: string;
}

/**
 * The one way formatted text is shown. The value is sanitized again here
 * against the shared allowlist before it reaches the DOM (the server did so on
 * save). Legacy plain text keeps its line breaks and is never parsed as HTML.
 */
export default function RichTextViewer({ html, empty = null, className, lang, id }: RichTextViewerProps) {
    const safe = useMemo(() => (isRichTextHtml(html) ? sanitizeRichText(html ?? '') : null), [html]);

    if (isRichTextEmpty(html)) {
        return <>{empty}</>;
    }

    if (safe === null) {
        return <div id={id} lang={lang} className={cx('rich-text whitespace-pre-line', className)}>{html}</div>;
    }

    // Sanitized above with DOMPurify against policy.json; see richtext/sanitize.ts.
    return <div id={id} lang={lang} className={cx('rich-text', className)} dangerouslySetInnerHTML={{ __html: safe }} />;
}
