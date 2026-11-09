import { ChevronDown } from '@/Components/Icons';
import { useDesignText } from './ui';

type Item = { number: string; text: string; subs: { letter: string; text: string }[] };
type Article = { number: string; intro: string; items: Item[]; paragraphs: string[] };

/** Reads the "አንቀጽ N፦" layout mandate text is stored in; null when the text is written some other way. */
function parseArticles(text: string): Article[] | null {
    const blocks = text.split(/\n\s*\n/).map((block) => block.trim()).filter(Boolean);
    const articles: Article[] = [];

    for (const block of blocks) {
        const [first, ...rest] = block.split('\n');
        const heading = first.match(/^አንቀጽ\s+(\d+)፦\s*(.*)$/u);
        if (!heading) return null;

        const article: Article = { number: heading[1], intro: heading[2], items: [], paragraphs: [] };
        for (const line of rest) {
            const item = line.match(/^(\d+)\.\s+(.*)$/u);
            const sub = line.match(/^\s*(\p{Script=Ethiopic})\)\s+(.*)$/u);
            const current = article.items[article.items.length - 1];
            if (item) article.items.push({ number: item[1], text: item[2], subs: [] });
            else if (sub && current) current.subs.push({ letter: sub[1], text: sub[2] });
            else if (line.trim()) article.paragraphs.push(line.trim());
        }
        articles.push(article);
    }

    return articles.length > 0 ? articles : null;
}

/**
 * A mandate's text, one folding section per article, as a numbered list the eye
 * can follow. Text not in that layout is shown as written.
 */
export default function MandateText({ text, articleTitle }: { text: string; articleTitle: (number: string) => string | null }) {
    const { tt } = useDesignText();
    const articles = parseArticles(text);

    if (articles === null) {
        return <p className="max-w-3xl whitespace-pre-line wrap-break-word p-5 text-sm leading-7 text-(--app-foreground)">{text}</p>;
    }

    return (
        <div className="divide-y divide-(--app-border)">
            {articles.map((article, index) => {
                const title = articleTitle(article.number);
                return (
                    <details key={article.number} open={index === 0} className="group">
                        <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-3 hover:bg-(--app-surface-muted) [&::-webkit-details-marker]:hidden">
                            <ChevronDown className="h-4 w-4 shrink-0 -rotate-90 text-(--app-muted-foreground) transition-transform group-open:rotate-0" aria-hidden="true" />
                            <span className="min-w-0 flex-1 text-sm font-semibold text-(--app-foreground)">
                                {tt('mandates.article').replace(':number', article.number)}
                                {title && <span className="font-normal text-(--app-muted-foreground)"> · {title}</span>}
                            </span>
                            {article.items.length > 0 && (
                                <span className="shrink-0 rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs tabular-nums text-(--app-muted-foreground)">
                                    {tt('mandates.itemCount').replace(':count', String(article.items.length))}
                                </span>
                            )}
                        </summary>
                        <div className="px-5 pb-5">
                            {article.intro && <p className="mb-3 max-w-3xl text-sm font-medium text-(--app-foreground)">{article.intro}</p>}
                            {article.paragraphs.map((paragraph, i) => (
                                <p key={i} className="mb-2 max-w-3xl text-sm leading-7 text-(--app-foreground)">{paragraph}</p>
                            ))}
                            {article.items.length > 0 && (
                                <ol className="max-w-3xl space-y-2.5">
                                    {article.items.map((item) => (
                                        <li key={item.number} className="grid grid-cols-[2rem_minmax(0,1fr)] gap-1 text-sm leading-7">
                                            <span className="pt-px text-right font-medium tabular-nums text-(--app-muted-foreground)">{item.number}.</span>
                                            <div className="min-w-0 pl-1 text-(--app-foreground)">
                                                {item.text}
                                                {item.subs.length > 0 && (
                                                    <ul className="mt-1.5 space-y-1.5 border-l-2 border-(--app-border) pl-3">
                                                        {item.subs.map((sub) => (
                                                            <li key={sub.letter} className="grid grid-cols-[1.5rem_minmax(0,1fr)] gap-1">
                                                                <span className="text-(--app-muted-foreground)">{sub.letter})</span>
                                                                <span className="min-w-0">{sub.text}</span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </details>
                );
            })}
        </div>
    );
}
