import { router } from '@inertiajs/react';
import { Pagination, cx } from '@euisis/ui';

/**
 * Whether the current URL carries any filter, for lists whose server echoes
 * default filter values (a default period) that the viewer never chose.
 */
export function hasFilterQuery(ignore: string[] = ['page', 'sort', 'direction']): boolean {
    if (typeof window === 'undefined') return false;
    return [...new URLSearchParams(window.location.search).entries()].some(([key, value]) => value !== '' && !ignore.includes(key));
}

/** The page fields every Laravel length-aware paginator sends. */
export type LaravelPageMeta = { current_page: number; last_page: number; per_page?: number; total: number; from?: number | null; to?: number | null };

/**
 * A Laravel paginator's pagination bar, as the footer of a table card: the
 * visible range on the left, a bounded set of page buttons on the right.
 * Without `onPageChange` it re-requests the current URL with the new page,
 * keeping every filter in the query string.
 */
export default function ListPagination({ meta, onPageChange, disabled = false, label, bare = false, className }: {
    meta: LaravelPageMeta | null | undefined;
    onPageChange?: (page: number) => void;
    /** While a page loads, or while the filters have unapplied changes. */
    disabled?: boolean;
    label?: string;
    /** Outside a table card: no top border or padding. */
    bare?: boolean;
    className?: string;
}) {
    if (!meta || meta.total <= 0) return null;

    const goTo = onPageChange ?? ((page: number) => {
        const url = new URL(window.location.href);
        url.searchParams.set('page', String(page));
        router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true });
    });

    return (
        <Pagination
            meta={{ currentPage: meta.current_page, lastPage: meta.last_page, perPage: meta.per_page ?? 0, total: meta.total, from: meta.from, to: meta.to }}
            onPageChange={goTo}
            disabled={disabled}
            label={label}
            className={cx(!bare && 'border-t border-[color:var(--app-border)] px-4 py-3', className)}
        />
    );
}
