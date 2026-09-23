import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

export type FeedbackFilters = {
    organization_id?: string;
    organization_unit_id?: string;
    employee_id?: string;
    service_type_id?: string;
    rating?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
};

export type FeedbackFilterOptions = {
    organizations: { id: string; name_en: string; name_am: string | null }[];
    serviceTypes: { id: string; name_en: string; name_am: string | null }[];
};

type Props = {
    /** Route name the filter change is submitted back to. */
    routeName: string;
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    /** Omitted on pages that report across every status. */
    statuses?: string[];
};

const inputCls =
    'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/**
 * Filter controls shared by the feedback list and the reports page so both
 * submit the same keys. The keys must match the controller's FILTER_KEYS, or
 * the filter is dropped server-side without any visible error.
 */
export default function FeedbackFilterBar({ routeName, filters, filterOptions, statuses }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const hasActiveFilter = Object.values(filters).some((value) => value !== undefined && value !== '');

    /*
     * Filters round-trip through the server rather than filtering in the
     * browser: the result set is scoped and aggregated server-side, so a
     * client-side filter would only ever narrow what is already on screen.
     */
    function applyFilter(key: keyof FeedbackFilters, value: string) {
        router.get(
            route(routeName),
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function clearFilters() {
        router.get(route(routeName), {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <div className="flex flex-wrap gap-2 rounded-card border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <select
                className={inputCls}
                value={filters.organization_id ?? ''}
                onChange={(e) => applyFilter('organization_id', e.target.value)}
                aria-label={t('serviceFeedback.filterOrganization')}
            >
                <option value="">{t('serviceFeedback.filterOrganization')}</option>
                {filterOptions.organizations.map((org) => (
                    <option key={org.id} value={org.id}>
                        {am ? (org.name_am ?? org.name_en) : org.name_en}
                    </option>
                ))}
            </select>

            <select
                className={inputCls}
                value={filters.service_type_id ?? ''}
                onChange={(e) => applyFilter('service_type_id', e.target.value)}
                aria-label={t('serviceFeedback.filterServiceType')}
            >
                <option value="">{t('serviceFeedback.filterServiceType')}</option>
                {filterOptions.serviceTypes.map((type) => (
                    <option key={type.id} value={type.id}>
                        {am ? (type.name_am ?? type.name_en) : type.name_en}
                    </option>
                ))}
            </select>

            <select
                className={inputCls}
                value={filters.rating ?? ''}
                onChange={(e) => applyFilter('rating', e.target.value)}
                aria-label={t('serviceFeedback.filterRating')}
            >
                <option value="">{t('serviceFeedback.allRatings')}</option>
                {[5, 4, 3, 2, 1].map((star) => (
                    <option key={star} value={star}>
                        {star} ★
                    </option>
                ))}
            </select>

            {statuses && (
                <select
                    className={inputCls}
                    value={filters.status ?? ''}
                    onChange={(e) => applyFilter('status', e.target.value)}
                    aria-label={t('serviceFeedback.filterStatus')}
                >
                    <option value="">{t('serviceFeedback.allStatuses')}</option>
                    {statuses.map((status) => (
                        <option key={status} value={status}>
                            {t(`serviceFeedback.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)}
                        </option>
                    ))}
                </select>
            )}

            <input
                type="date"
                className={inputCls}
                value={filters.date_from ?? ''}
                onChange={(e) => applyFilter('date_from', e.target.value)}
                aria-label={t('serviceFeedback.filterDateRange')}
            />
            <input
                type="date"
                className={inputCls}
                value={filters.date_to ?? ''}
                onChange={(e) => applyFilter('date_to', e.target.value)}
                aria-label={t('serviceFeedback.filterDateRange')}
            />

            {hasActiveFilter && (
                <button
                    type="button"
                    onClick={clearFilters}
                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('common.clear')}
                </button>
            )}
        </div>
    );
}
