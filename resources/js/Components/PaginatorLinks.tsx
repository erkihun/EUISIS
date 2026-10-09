import { ChevronLeft, ChevronRight } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { cx } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

export type PaginatorLink = { url: string | null; label: string; active: boolean };

/**
 * Laravel's paginator `links`, with its "« Previous" / "Next »" labels
 * swapped for chevrons. Renders nothing when everything fits on one page.
 */
export default function PaginatorLinks({ links, label }: { links: PaginatorLink[]; label: string }): JSX.Element | null {
    const { t } = useLocale();

    if (links.length <= 3) return null;

    const last = links.length - 1;

    return (
        <nav aria-label={label} className="flex flex-wrap gap-1">
            {links.map((link, index) => {
                const edge = index === 0 || index === last;
                const content = index === 0
                    ? <ChevronLeft aria-hidden="true" className="h-4 w-4" />
                    : index === last
                      ? <ChevronRight aria-hidden="true" className="h-4 w-4" />
                      : <span dangerouslySetInnerHTML={{ __html: link.label }} />;
                const className = cx(
                    'inline-flex h-8 min-w-8 items-center justify-center rounded-[var(--radius-control)] px-2 text-[13px] tabular-nums transition-colors',
                    link.active
                        ? 'bg-[color:var(--color-primary)] font-semibold text-white'
                        : link.url
                          ? 'border border-[color:var(--app-border)] text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]'
                          : 'text-[color:var(--app-muted-foreground)]',
                    edge && !link.url && 'opacity-50',
                );
                const aria = index === 0 ? t('common.previous') : index === last ? t('common.next') : undefined;

                return link.url ? (
                    <Link key={index} href={link.url} preserveScroll aria-label={aria} aria-current={link.active ? 'page' : undefined} className={className}>
                        {content}
                    </Link>
                ) : (
                    <span key={index} aria-label={aria} aria-disabled="true" className={className}>{content}</span>
                );
            })}
        </nav>
    );
}
