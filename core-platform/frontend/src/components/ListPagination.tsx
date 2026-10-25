import type { Page } from '../api';
import { Pagination, cx } from '../ui';

/** EUISIS Components/ui/ListPagination.tsx: the UI kit's pagination over a Laravel page. */
export default function ListPagination({ meta, onPageChange, disabled = false, bare = false, className }: {
    meta: Page<unknown> | null | undefined;
    onPageChange: (page: number) => void;
    disabled?: boolean;
    bare?: boolean;
    className?: string;
}) {
    if (!meta || meta.total <= 0) return null;

    return (
        <Pagination
            meta={{ currentPage: meta.current_page, lastPage: meta.last_page, perPage: meta.per_page ?? 0, total: meta.total, from: meta.from, to: meta.to }}
            onPageChange={onPageChange}
            disabled={disabled}
            className={cx(!bare && 'border-t border-(--app-border) px-4 py-3', className)}
        />
    );
}
