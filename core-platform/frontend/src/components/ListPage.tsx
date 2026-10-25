import { useEffect, useState, type ReactNode } from 'react';
import { api, type Page, type Query } from '../api';
import { useI18n } from '../i18n';
import { buttonClass, controlClass, Notice } from './ui';

export type Column<T> = { label: string; render: (row: T) => ReactNode; className?: string };

export type Filter = { name: string; label: string; options: { value: string; label: string }[] };

type Props<T> = {
    title: string;
    endpoint: string;
    columns: Column<T>[];
    filters?: Filter[];
    toggles?: { name: string; label: string }[];
    searchable?: boolean;
    onRowClick?: (row: T) => void;
    actions?: ReactNode;
    reloadKey?: number;
};

/** One list screen: search, filters, a table and the Laravel paginator, all scoped by the API. */
export function ListPage<T extends { id: string }>({ title, endpoint, columns, filters = [], toggles = [], searchable = true, onRowClick, actions, reloadKey = 0 }: Props<T>) {
    const { t } = useI18n();
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [values, setValues] = useState<Query>({});
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page<T> | null>(null);
    const [error, setError] = useState(false);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(search.trim()), 300);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => setPage(1), [debounced, values]);

    useEffect(() => {
        let live = true;
        setLoading(true);
        api<Page<T>>(endpoint, { query: { ...values, search: debounced, page } })
            .then((response) => live && (setResult(response), setError(false)))
            .catch(() => live && setError(true))
            .finally(() => live && setLoading(false));
        return () => {
            live = false;
        };
    }, [endpoint, debounced, values, page, reloadKey]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-ink">{title}</h1>
                {actions}
            </div>

            <div className="flex flex-wrap items-end gap-3">
                {searchable && (
                    <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('common.search')} aria-label={t('common.search')} className={`${controlClass} w-full max-w-xs`} />
                )}
                {filters.map((filter) => (
                    <select
                        key={filter.name}
                        aria-label={filter.label}
                        value={String(values[filter.name] ?? '')}
                        onChange={(event) => setValues((current) => ({ ...current, [filter.name]: event.target.value }))}
                        className={controlClass}
                    >
                        <option value="">
                            {filter.label}: {t('common.all')}
                        </option>
                        {filter.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                ))}
                {toggles.map((toggle) => (
                    <label key={toggle.name} className="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" checked={values[toggle.name] === 1} onChange={(event) => setValues((current) => ({ ...current, [toggle.name]: event.target.checked ? 1 : undefined }))} />
                        {toggle.label}
                    </label>
                ))}
            </div>

            {error && <Notice kind="error">{t('common.error')}</Notice>}

            <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                <table className="min-w-full divide-y divide-line text-sm">
                    <thead className="bg-subtle">
                        <tr>
                            {columns.map((column) => (
                                <th key={column.label} scope="col" className="px-4 py-2 text-left text-xs font-semibold tracking-wide text-muted uppercase">
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className={`divide-y divide-line ${loading ? 'opacity-60' : ''}`}>
                        {result?.data.map((row) => (
                            <tr
                                key={row.id}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                                onKeyDown={onRowClick ? (event) => event.key === 'Enter' && onRowClick(row) : undefined}
                                tabIndex={onRowClick ? 0 : undefined}
                                className={onRowClick ? 'cursor-pointer hover:bg-subtle focus:bg-subtle focus:outline-none' : ''}
                            >
                                {columns.map((column) => (
                                    <td key={column.label} className={`px-4 py-2 align-top text-ink ${column.className ?? ''}`}>
                                        {column.render(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {result && result.data.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-8 text-center text-muted">
                                    {t('common.empty')}
                                </td>
                            </tr>
                        )}
                        {!result && loading && (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-8 text-center text-muted">
                                    {t('common.loading')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {result && result.last_page > 1 && (
                <nav className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted" aria-label="Pagination">
                    <span>{t('common.pageOf', { page: result.current_page, last: result.last_page, total: result.total })}</span>
                    <div className="flex gap-2">
                        <button type="button" className={buttonClass.secondary} disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            {t('common.previous')}
                        </button>
                        <button type="button" className={buttonClass.secondary} disabled={page >= result.last_page} onClick={() => setPage(page + 1)}>
                            {t('common.next')}
                        </button>
                    </div>
                </nav>
            )}
        </div>
    );
}
