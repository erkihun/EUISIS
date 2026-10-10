import { FormEvent, ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { Button, controlClassName, cx } from '@euisis/ui';

/** Classes for any filter input/select: the shared control style, sized to its content. */
export const filterInputCls = cx(controlClassName, 'w-auto min-w-40');

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
        <form className="flex flex-wrap items-center gap-2 rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-3" onSubmit={handleSubmit}>
            {children}

            <Button type="submit" variant="primary">
                {t('common.filter')}
            </Button>

            {hasActiveFilters && (
                <Button variant="ghost" onClick={handleReset}>
                    {t('common.reset')}
                </Button>
            )}
        </form>
    );
}
