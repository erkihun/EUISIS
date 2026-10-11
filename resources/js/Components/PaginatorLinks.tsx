import { ChevronLeft, ChevronRight } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { paginationItemClassName } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

export type PaginatorLink = { url: string | null; label: string; active: boolean };

/**
 * Laravel's paginator `links`, with its "« Previous" / "Next »" labels
 * swapped for chevrons, drawn as the shared page buttons. Renders nothing
 * when everything fits on one page. Prefer ListPagination, which also states
 * the visible range, when the page has the paginator's totals.
 */
export default function PaginatorLinks({ links, label }: { links: PaginatorLink[]; label: string }): JSX.Element | null {
    const { t } = useLocale();

    if (links.length <= 3) return null;

    const last = links.length - 1;

    return (
        <nav aria-label={label} className="flex flex-wrap gap-1">
            {links.map((link, index) => {
                const content = index === 0
                    ? <ChevronLeft aria-hidden="true" className="h-4 w-4" />
                    : index === last
                      ? <ChevronRight aria-hidden="true" className="h-4 w-4" />
                      : <span dangerouslySetInnerHTML={{ __html: link.label }} />;
                const aria = index === 0 ? t('common.previous') : index === last ? t('common.next') : undefined;

                return link.url ? (
                    <Link key={index} href={link.url} preserveScroll aria-label={aria} aria-current={link.active ? 'page' : undefined} className={paginationItemClassName(link.active)}>
                        {content}
                    </Link>
                ) : (
                    <span key={index} aria-label={aria} aria-disabled="true" className={paginationItemClassName()}>{content}</span>
                );
            })}
        </nav>
    );
}
