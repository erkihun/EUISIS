import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { SearchIcon, X } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { Input, Select, cx } from '@euisis/ui';
import { router } from '@inertiajs/react';
import { useEffect, useState, type JSX } from 'react';
import { statusLabelKey } from './feedbackUi';

export type FeedbackFilters = {
    organization_id?: string;
    organization_unit_id?: string;
    employee_id?: string;
    service_type_id?: string;
    service_no?: string;
    rating?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
    q?: string;
    /** `employee` (an employee's QR) or `organization` (an institution's QR). */
    target_type?: string;
};

export type FeedbackFilterOptions = {
    organizations: { id: string; name_en: string; name_am: string | null }[];
    /** Work units of the organization chosen in the bar; empty (or absent) until one is chosen. */
    units?: { id: string; name_en: string; name_am: string | null }[];
    serviceTypes: { id: string; name_en: string; name_am: string | null }[];
};

type FilterKey = keyof FeedbackFilters;

type Props = {
    /** Route name the filter change is submitted back to. */
    routeName: string;
    filters: FeedbackFilters;
    filterOptions: FeedbackFilterOptions;
    /** Shows the status dropdown. Omitted where status is chosen elsewhere, such as the inbox tabs. */
    statuses?: string[];
    /** Shows the free-text search box. */
    search?: boolean;
    /** Shows the 7 / 30 / 90 day and all-time shortcuts. */
    presets?: boolean;
    /** Filters owned by another control on the page; "Clear all" leaves them alone and no chip is drawn. */
    preserve?: FilterKey[];
    /** Drawn inside another panel: no border of its own, only a divider below. */
    embedded?: boolean;
};

const PRESET_DAYS = [7, 30, 90] as const;
const SEARCH_DELAY_MS = 350;

/** Local calendar date as Y-m-d, the format the controller filters on. */
function isoDate(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** First day of an N-day window that ends today, today included. */
function windowStart(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - (days - 1));
    return isoDate(date);
}

const filled = (value: string | undefined): value is string => value !== undefined && value !== '';

/**
 * Filter controls shared by the feedback screens so all of them submit the
 * same keys. The keys must match the controller's FILTER_KEYS, or the filter
 * is dropped server-side without any visible error.
 */
export default function FeedbackFilterBar({
    routeName,
    filters,
    filterOptions,
    statuses,
    search = false,
    presets = false,
    preserve = [],
    embedded = false,
}: Props): JSX.Element {
    const { locale, t } = useLocale();
    const calendar = useCalendarSystem();
    const am = locale === 'am';
    const [query, setQuery] = useState(filters.q ?? '');

    // Follow the server's value after navigation (Back button, Clear all).
    useEffect(() => setQuery(filters.q ?? ''), [filters.q]);

    /*
     * Filters round-trip through the server rather than filtering in the
     * browser: the result set is scoped and aggregated server-side, so a
     * client-side filter would only ever narrow what is already on screen.
     * Leaving `page` out sends the list back to its first page.
     */
    function visit(next: FeedbackFilters) {
        const params = Object.fromEntries(Object.entries(next).filter(([, value]) => filled(value)));
        router.get(route(routeName), params, { preserveState: true, preserveScroll: true, replace: true });
    }

    const apply = (changes: Partial<FeedbackFilters>) => visit({ ...filters, ...changes });

    useEffect(() => {
        const term = query.trim();
        if (term === (filters.q ?? '')) return;

        const timer = window.setTimeout(() => apply({ q: term }), SEARCH_DELAY_MS);
        return () => window.clearTimeout(timer);
        // Only typing should schedule a search; `filters` changing is its result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const nameOf = (options: { id: string; name_en: string; name_am: string | null }[], id: string) => {
        const option = options.find((entry) => entry.id === id);
        return option ? (am ? (option.name_am ?? option.name_en) : option.name_en) : id;
    };

    const chipLabels: Partial<Record<FilterKey, (value: string) => string>> = {
        q: (value) => `“${value}”`,
        organization_id: (value) => nameOf(filterOptions.organizations, value),
        service_type_id: (value) => nameOf(filterOptions.serviceTypes, value),
        rating: (value) => `${t('serviceFeedback.filterRating')}: ${value} ★`,
        status: (value) => `${t('serviceFeedback.filterStatus')}: ${t(statusLabelKey(value))}`,
        date_from: (value) => `${t('serviceFeedback.dateFrom')} ${formatDateDisplay(value, calendar, locale)}`,
        date_to: (value) => `${t('serviceFeedback.dateTo')} ${formatDateDisplay(value, calendar, locale)}`,
        target_type: (value) => t(value === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee'),
        employee_id: () => t('serviceFeedback.filterEmployee'),
        organization_unit_id: () => t('serviceFeedback.filterUnit'),
        service_no: (value) => `${t('serviceFeedback.serviceIdNo')}: ${value}`,
    };

    const chips = (Object.keys(chipLabels) as FilterKey[])
        .filter((key) => !preserve.includes(key) && filled(filters[key]))
        .map((key) => ({ key, label: chipLabels[key]!(filters[key] as string) }));

    function clearAll() {
        setQuery('');
        visit(Object.fromEntries(preserve.map((key) => [key, filters[key]])) as FeedbackFilters);
    }

    const activePreset = !filled(filters.date_from) && !filled(filters.date_to)
        ? 'all'
        : !filled(filters.date_to) ? PRESET_DAYS.find((days) => filters.date_from === windowStart(days)) : undefined;

    const presetOptions: { id: 'all' | (typeof PRESET_DAYS)[number]; label: string }[] = [
        { id: 7, label: t('serviceFeedback.last7Days') },
        { id: 30, label: t('serviceFeedback.last30Days') },
        { id: 90, label: t('serviceFeedback.last90Days') },
        { id: 'all', label: t('serviceFeedback.allTime') },
    ];

    // The compact 30px height leaves no room for the forms plugin's 8px top and bottom padding:
    // the option text was cut off at the bottom. The select centres its text without it.
    const selectCls = 'h-(--control-h-sm) py-0 w-full sm:w-auto sm:min-w-42 sm:max-w-56';

    return (
        <section
            aria-label={t('common.filter')}
            className={embedded
                ? 'border-b border-(--app-border) px-4 py-3'
                : 'rounded-panel border border-(--app-border) bg-(--app-surface) p-3'}
        >
            <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
                {presets && (
                    <div role="group" aria-label={t('serviceFeedback.period')} className="flex gap-0.5 self-start rounded-lg bg-(--app-surface-muted) p-[3px]">
                        {presetOptions.map((option) => {
                            const active = activePreset === option.id;
                            return (
                                <button
                                    key={option.id}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => apply(option.id === 'all'
                                        ? { date_from: undefined, date_to: undefined }
                                        : { date_from: windowStart(option.id), date_to: undefined })}
                                    className={cx(
                                        'h-7 whitespace-nowrap rounded-md px-3 text-[13px] transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                        active
                                            ? 'bg-(--app-surface) font-semibold text-(--app-foreground) shadow-xs ring-1 ring-(--app-border)'
                                            : 'font-medium text-(--app-muted-foreground) hover:text-(--app-foreground)',
                                    )}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                )}

                {search && (
                    <div className="relative min-w-0 lg:min-w-[16rem] lg:flex-1">
                        <SearchIcon aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" />
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('serviceFeedback.searchPlaceholder')}
                            aria-label={t('serviceFeedback.searchLabel')}
                            maxLength={100}
                            className="h-(--control-h-sm) pl-9"
                        />
                    </div>
                )}

                <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                    <Select
                        className={selectCls}
                        value={filters.organization_id ?? ''}
                        onChange={(event) => apply({ organization_id: event.target.value, organization_unit_id: undefined })}
                        aria-label={t('serviceFeedback.filterOrganization')}
                    >
                        <option value="">{t('serviceFeedback.allOrganizations')}</option>
                        {filterOptions.organizations.map((org) => (
                            <option key={org.id} value={org.id}>{am ? (org.name_am ?? org.name_en) : org.name_en}</option>
                        ))}
                    </Select>

                    {filters.organization_id && filterOptions.units && filterOptions.units.length > 0 && (
                        <Select
                            className={selectCls}
                            value={filters.organization_unit_id ?? ''}
                            onChange={(event) => apply({ organization_unit_id: event.target.value })}
                            aria-label={t('serviceFeedback.filterUnit')}
                        >
                            <option value="">{t('serviceFeedback.allUnits')}</option>
                            {filterOptions.units.map((unit) => (
                                <option key={unit.id} value={unit.id}>{am ? (unit.name_am ?? unit.name_en) : unit.name_en}</option>
                            ))}
                        </Select>
                    )}

                    <Select
                        className={selectCls}
                        value={filters.service_type_id ?? ''}
                        onChange={(event) => apply({ service_type_id: event.target.value })}
                        aria-label={t('serviceFeedback.filterServiceType')}
                    >
                        <option value="">{t('serviceFeedback.allServiceTypes')}</option>
                        {filterOptions.serviceTypes.map((type) => (
                            <option key={type.id} value={type.id}>{am ? (type.name_am ?? type.name_en) : type.name_en}</option>
                        ))}
                    </Select>

                    <Select
                        className={selectCls}
                        value={filters.rating ?? ''}
                        onChange={(event) => apply({ rating: event.target.value })}
                        aria-label={t('serviceFeedback.filterRating')}
                    >
                        <option value="">{t('serviceFeedback.allRatings')}</option>
                        {[5, 4, 3, 2, 1].map((star) => (
                            <option key={star} value={star}>{star} ★ · {t(`serviceFeedback.rating${star}`)}</option>
                        ))}
                    </Select>

                    <Select
                        className={selectCls}
                        value={filters.target_type ?? ''}
                        onChange={(event) => apply({ target_type: event.target.value })}
                        aria-label={t('serviceFeedback.columnTarget')}
                    >
                        <option value="">{t('serviceFeedback.allTargets')}</option>
                        <option value="employee">{t('serviceFeedback.targetEmployee')}</option>
                        <option value="organization">{t('serviceFeedback.targetOrganization')}</option>
                    </Select>

                    {statuses && (
                        <Select
                            className={selectCls}
                            value={filters.status ?? ''}
                            onChange={(event) => apply({ status: event.target.value })}
                            aria-label={t('serviceFeedback.filterStatus')}
                        >
                            <option value="">{t('serviceFeedback.allStatuses')}</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>{t(statusLabelKey(status))}</option>
                            ))}
                        </Select>
                    )}

                    {/* Ethiopian calendar in Amharic, Gregorian in English; the value is always Gregorian ISO. */}
                    <div className="w-full sm:w-44" aria-label={t('serviceFeedback.dateFrom')} role="group">
                        <LocalizedDatePicker
                            id="filter-date-from"
                            value={filters.date_from ?? ''}
                            max={filters.date_to || undefined}
                            placeholder={t('serviceFeedback.dateFrom')}
                            onChange={(iso) => apply({ date_from: iso })}
                            className="h-(--control-h-sm) text-[13px]"
                        />
                    </div>
                    <div className="w-full sm:w-44" aria-label={t('serviceFeedback.dateTo')} role="group">
                        <LocalizedDatePicker
                            id="filter-date-to"
                            value={filters.date_to ?? ''}
                            min={filters.date_from || undefined}
                            placeholder={t('serviceFeedback.dateTo')}
                            onChange={(iso) => apply({ date_to: iso })}
                            className="h-(--control-h-sm) text-[13px]"
                        />
                    </div>
                </div>
            </div>

            {chips.length > 0 && (
                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-(--app-border) pt-3 text-[13px]">
                    <span className="text-(--app-muted-foreground)">{t('serviceFeedback.filteredBy')}</span>
                    {chips.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            onClick={() => {
                                if (chip.key === 'q') setQuery('');
                                apply({ [chip.key]: undefined });
                            }}
                            aria-label={`${t('serviceFeedback.removeFilter')}: ${chip.label}`}
                            className="inline-flex h-7 max-w-full items-center gap-1.5 rounded-full border border-primary-200 bg-primary-50 pl-3 pr-2 text-xs font-medium text-primary-800 transition-colors hover:border-primary-300 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary dark:border-primary-800 dark:bg-primary-950 dark:text-primary-200"
                        >
                            <span className="truncate">{chip.label}</span>
                            <X aria-hidden="true" className="h-3.5 w-3.5 shrink-0" />
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={clearAll}
                        className="h-7 rounded-md px-2 text-xs font-semibold text-primary hover:underline focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary"
                    >
                        {t('serviceFeedback.clearAll')}
                    </button>
                </div>
            )}
        </section>
    );
}
