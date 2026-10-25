import { useEffect, useState } from 'react';
import { api, type Page, type Query } from '../api';

/**
 * One server-paginated list: the applied filters and page go to the API, the
 * Laravel page comes back. Filters apply on submit (EUISIS FilterPanel), so the
 * server is asked once per intent, not per keystroke.
 */
export function useList<T>(endpoint: string, initial: Query = {}) {
    const [filters, setFilters] = useState<Query>(initial);
    const [page, setPage] = useState(1);
    const [reloadKey, setReloadKey] = useState(0);
    const [result, setResult] = useState<Page<T> | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        api<Page<T>>(endpoint, { query: { ...filters, page }, signal: controller.signal })
            .then((response) => { setResult(response); setError(false); })
            .catch((caught: unknown) => { if (!controller.signal.aborted) setError(Boolean(caught)); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [endpoint, filters, page, reloadKey]);

    return {
        result,
        loading,
        error,
        filters,
        /** Applies new filters and returns to the first page. */
        apply: (next: Query) => { setFilters(next); setPage(1); },
        setPage,
        reload: () => setReloadKey((key) => key + 1),
    };
}
