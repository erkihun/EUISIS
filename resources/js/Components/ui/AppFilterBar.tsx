import { FormEvent, ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

/** Tailwind classes for any filter input/select. Import and reuse in pages. */
export const filterInputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:border-[color:var(--color-primary)]';

interface AppFilterBarProps {
    /** Route name used for `router.get` on submit. */
    routeName: string;
    /** Current filter values — restored into form on mount via `defaultValue`. */
    filters?: Record<string, string>;
    children: ReactNode;
    /** Called with the raw FormData values before navigation. Useful for controlled inputs not in the form. */
    onBeforeSubmit?: (params: Record<string, string>) => Record<string, string>;
}

/**
 * Wraps a filter form with consistent styles and handles the Inertia submit.
 *
 * Usage:
 * ```tsx
 * <AppFilterBar routeName="employees.index" filters={filters}>
 *   <input name="search" defaultValue={filters.search ?? ''} className={filterInputCls} />
 *   <select name="status" defaultValue={filters.status ?? ''} className={filterInputCls}>
 *     ...
 *   </select>
 * </AppFilterBar>
 * ```
 */
export default function AppFilterBar({
    routeName,
    filters = {},
    children,
    onBeforeSubmit,
}: AppFilterBarProps) {
    const { t } = useLocale();

    function handleSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        let params = Object.fromEntries(new FormData(e.currentTarget)) as Record<string, string>;
        if (onBeforeSubmit) params = onBeforeSubmit(params);
        // Remove empty strings to keep URLs clean
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        router.get(route(routeName), params, { preserveState: true });
    }

    function handleReset() {
        router.get(route(routeName), {}, { preserveState: true });
    }

    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <form className="flex flex-wrap items-center gap-3" onSubmit={handleSubmit}>
            {children}

            <button
                type="submit"
                className="inline-flex items-center rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
            >
                {t('common.filter')}
            </button>

            {hasActiveFilters && (
                <button
                    type="button"
                    onClick={handleReset}
                    className="text-sm text-gray-500 hover:text-gray-700 underline dark:text-slate-400 dark:hover:text-slate-200"
                >
                    {t('common.reset')}
                </button>
            )}
        </form>
    );
}
