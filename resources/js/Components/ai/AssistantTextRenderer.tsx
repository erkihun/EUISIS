import { Fragment, type ReactNode } from 'react';

/**
 * Strict renderer for assistant text: paragraphs split on blank lines, lines
 * starting with "- " become list items and `**bold**` becomes <strong>.
 * Everything else is a text node. No HTML, no URL auto-linking — the text is
 * untrusted (docs/assistant/architecture.md, "Untrusted text everywhere").
 */

const BOLD = /(\*\*[^*\n]+\*\*)/g;

function inline(text: string): ReactNode[] {
    return text.split(BOLD).filter((part) => part !== '').map((part, index) => {
        if (part.length > 4 && part.startsWith('**') && part.endsWith('**')) {
            return <strong key={index} className="font-semibold">{part.slice(2, -2)}</strong>;
        }
        return <Fragment key={index}>{part}</Fragment>;
    });
}

type Chunk = { kind: 'lines'; lines: string[] } | { kind: 'list'; items: string[] };

function chunksOf(paragraph: string): Chunk[] {
    const chunks: Chunk[] = [];
    for (const raw of paragraph.split('\n')) {
        const line = raw.replace(/\s+$/, '');
        if (line.trim() === '') continue;
        const bullet = /^\s*- (.*)$/.exec(line);
        const last = chunks[chunks.length - 1];
        if (bullet) {
            if (last && last.kind === 'list') last.items.push(bullet[1]);
            else chunks.push({ kind: 'list', items: [bullet[1]] });
        } else if (last && last.kind === 'lines') {
            last.lines.push(line.trim());
        } else {
            chunks.push({ kind: 'lines', lines: [line.trim()] });
        }
    }
    return chunks;
}

export default function AssistantTextRenderer({ text, className }: { text: string; className?: string }) {
    const paragraphs = text.replace(/\r\n?/g, '\n').split(/\n[ \t]*\n/);

    return (
        <div className={className}>
            {paragraphs.map((paragraph, pIndex) =>
                chunksOf(paragraph).map((chunk, cIndex) =>
                    chunk.kind === 'list' ? (
                        <ul key={`${pIndex}-${cIndex}`} className="my-1.5 list-disc space-y-0.5 ps-5">
                            {chunk.items.map((item, iIndex) => <li key={iIndex}>{inline(item)}</li>)}
                        </ul>
                    ) : (
                        <p key={`${pIndex}-${cIndex}`} className="my-1.5 first:mt-0 last:mb-0">
                            {chunk.lines.map((line, lIndex) => (
                                <Fragment key={lIndex}>
                                    {lIndex > 0 && <br />}
                                    {inline(line)}
                                </Fragment>
                            ))}
                        </p>
                    ),
                ),
            )}
        </div>
    );
}
