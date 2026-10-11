import { FormEvent, ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { FilterPanel, cx, inputClassName } from '@euisis/ui';

/** Classes for a filter control: the shared control style; inside a FilterField it fills the field. */
export const filterInputCls = cx(inputClassName, 'min-w-40');

interface AppFilterBarProps {
    /** Route name used for `router.get` on submit. */
    routeName: string;
    /** Current filter values — decide whether Reset is offered. */
    filters?: Record<string, string | null | undefined>;
    children: ReactNode;
    /** Called with the raw FormData values before navigation. Useful for controlled inputs not in the form. */
    onBeforeSubmit?: (params: Record<string, string>) => Record<string, string>;
    /** Accessible name of the form, e.g. "KPI filters". */
    label?: string;
    /** Page actions that belong with the filters (Export). */
    actions?: ReactNode;
}

/**
 * The filter panel for an Inertia list whose controls are uncontrolled
 * (`name` + `defaultValue`): Filter sends them as the query string, Reset
 * clears it. Wrap each control in a FilterField so it is labelled.
 *
 * ```tsx
 * <AppFilterBar routeName="kpis.index" filters={filters}>
 *   <FilterField label={t('common.search')} wide><Input name="search" defaultValue={filters.search ?? ''} /></FilterField>
 *   <FilterField label={t('kpis.status')}><Select name="status" defaultValue={filters.status ?? ''}>…</Select></FilterField>
 * </AppFilterBar>
 * ```
 */
export default function AppFilterBar({ routeName, filters = {}, children, onBeforeSubmit, label, actions }: AppFilterBarProps) {
    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        let params = Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>;
        if (onBeforeSubmit) params = onBeforeSubmit(params);
        // Remove empty strings to keep URLs clean
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        router.get(route(routeName), params, { preserveState: true });
    }

    function handleReset() {
        router.get(route(routeName), {}, { preserveState: false });
    }

    return (
        <FilterPanel
            label={label}
            onSubmit={handleSubmit}
            onReset={handleReset}
            hasActiveFilters={Object.entries(filters).some(([key, value]) => key !== 'page' && Boolean(value))}
            actions={actions}
        >
            {children}
        </FilterPanel>
    );
}
