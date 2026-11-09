import { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { cx } from '@/Components/ui';
import { useCaseText } from './useCaseText';

const ETHIOPIC = /[ሀ-፿]/;
/** No Amharic word runs to 16 letters (the longest, with all their affixes, stay near 13): a run that long is several words with their spaces lost. */
const JOINED_RUN = /[ሀ-፿]{16,}/;

/**
 * Text exported from Word sometimes keeps every word in its own run
 * (`<strong><span>ቃል</span></strong><strong><span>ቃል</span></strong>`) with
 * the spaces between the runs dropped, so the words read as one and the
 * justified line before them stretches. In a block that shows such a run, a
 * space goes back between two runs that meet letter to letter. Display only:
 * the filed text is not changed.
 */
export function restoreLostSpaces(html: string): string {
    if (html === '' || typeof DOMParser === 'undefined' || !JOINED_RUN.test(html.replace(/<[^>]*>/g, ''))) return html;
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    doc.body.querySelectorAll('p, li, td, th, h1, h2, h3, h4, h5, h6, div').forEach((block) => {
        if (!JOINED_RUN.test(block.textContent ?? '')) return;
        const walker = doc.createTreeWalker(block, NodeFilter.SHOW_TEXT);
        let previous: Text | null = null;
        for (let node = walker.nextNode() as Text | null; node; node = walker.nextNode() as Text | null) {
            const value = node.data;
            if (value === '') continue;
            if (previous && ETHIOPIC.test(previous.data.slice(-1)) && ETHIOPIC.test(value[0])) node.data = ` ${value}`;
            previous = node;
        }
    });

    return doc.body.innerHTML;
}

interface Props {
    /** Purified HTML from the server (mews/purifier, the `cases` profile). */
    html: string;
    /** Shown in place of the text when there is none. */
    empty?: string;
    /** Folds a long text to this height (rem) behind "Show the full text"; 0 shows it all. */
    foldAt?: number;
    className?: string;
}

/**
 * A filed text (the claim, the relief, a response) read as the paper it came
 * from: a sheet in the document face, justified, with Word's pasted sizes
 * evened out (`.case-doc`, court.css). A long text folds behind a fade.
 */
export default function DocumentSheet({ html, empty, foldAt = 0, className }: Props) {
    const { text } = useCaseText();
    const bodyRef = useRef<HTMLDivElement>(null);
    const [overflows, setOverflows] = useState(false);
    const [open, setOpen] = useState(false);
    const shown = useMemo(() => restoreLostSpaces(html), [html]);

    useLayoutEffect(() => {
        const body = bodyRef.current;
        if (!body || foldAt <= 0) return;
        const rootSize = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        setOverflows(body.scrollHeight > foldAt * rootSize + 48);
    }, [shown, foldAt]);

    const folded = overflows && !open;

    return (
        <div className={cx('rounded-control border border-(--app-border) bg-(--app-surface) shadow-[0_1px_2px_rgba(15,23,42,0.05)]', className)}>
            <div className="relative">
                <div
                    ref={bodyRef}
                    className={cx('rich-text case-doc px-5 py-4 text-(--app-foreground) sm:px-8 sm:py-6', folded && 'overflow-hidden')}
                    style={folded ? { maxHeight: `${foldAt}rem` } : undefined}
                    {...(shown !== '' ? { dangerouslySetInnerHTML: { __html: shown } } : { children: <p className="italic text-(--app-muted-foreground)">{empty}</p> })}
                />
                {folded && <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-0 h-20 rounded-b-control bg-gradient-to-t from-(--app-surface) to-transparent" />}
            </div>
            {overflows && (
                <div className="border-t border-(--app-border) px-5 py-2 text-center print:hidden sm:px-8">
                    <button type="button" aria-expanded={open} onClick={() => setOpen((value) => !value)} className="text-sm font-semibold text-primary hover:underline">
                        {open ? text('show_less') : text('show_full_text')}
                    </button>
                </div>
            )}
        </div>
    );
}
