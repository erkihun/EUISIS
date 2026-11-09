import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay, formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useLocale } from '@/hooks/useLocale';
import { Card, FilterField, FilterPanel, SearchableSelect, Select, cx, type SearchableOption } from '@euisis/ui';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export type Name = { name_en: string | null; name_am: string | null };
export type Counts = {
    total: number;
    feedback: number;
    suggestion: number;
    complaint: number;
    unresolved: number;
    resolved: number;
    unrouted: number;
    unresolved_complaints: number;
};
export type SubmissionType = 'feedback' | 'suggestion' | 'complaint';
export const TYPES: SubmissionType[] = ['feedback', 'suggestion', 'complaint'];

export type ScopeProps = {
    node: ({ id: string; code: string | null } & Name) | null;
    breadcrumb: ({ id: string } & Name)[];
    unrestricted?: boolean;
    visibleTargets?: string[];
    hierarchyPublished?: boolean;
};

export type Filters = {
    period: string;
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    type?: string;
    target?: string;
    status?: string;
    unit: 'day' | 'week' | 'month';
};

export type FilterOptions = {
    periods: string[];
    types: string[];
    targets: string[];
    statuses: string[];
};

/** Fill {placeholders} in a translated string. */
export function fmt(text: string, params: Record<string, string | number | null | undefined>): string {
    return text.replace(/\{(\w+)\}/g, (match, key: string) => {
        const value = params[key];
        return value === null || value === undefined ? match : typeof value === 'number' ? value.toLocaleString() : value;
    });
}

/** The name in the reader's language, falling back to the other one. */
export function useNamer() {
    const { locale } = useLocale();
    return (row: Name | null | undefined): string => {
        if (!row) return '—';
        return (locale === 'am' ? row.name_am || row.name_en : row.name_en || row.name_am) ?? '—';
    };
}

export function useDateLabel() {
    const { locale } = useLocale();
    const system = useCalendarSystem();
    return {
        date: (value: string | null | undefined) => (value ? formatDateDisplay(value, system, locale) || value : '—'),
        dateTime: (value: string | null | undefined) => (value ? formatDateTimeDisplay(value, system, locale) || value : '—'),
    };
}

/** The query string that keeps the current view: institution plus filters. */
export function viewQuery(scope: ScopeProps, filters: Filters, extra: Record<string, string | number | undefined> = {}): Record<string, string | number> {
    const query: Record<string, string | number | undefined> = {
        organization: scope.node?.id,
        period: filters.period,
        from: filters.period === 'custom' ? filters.from : undefined,
        to: filters.period === 'custom' ? filters.to : undefined,
        type: filters.type,
        target: filters.target,
        status: filters.status,
        ...extra,
    };

    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined && value !== '')) as Record<string, string | number>;
}

/** Where a drill-down into one institution goes, keeping the filters. */
export function institutionHref(id: string, filters: Filters, scope?: ScopeProps): string {
    const { organization: _ignored, ...rest } = viewQuery(scope ?? { node: null, breadcrumb: [] }, filters);
    return route('situation-room.organization', { organization: id, ...rest });
}

export function Breadcrumb({ scope, filters }: { scope: ScopeProps; filters: Filters }) {
    const { t } = useLocale();
    const name = useNamer();
    const { organization: _ignored, ...rest } = viewQuery(scope, filters);

    return (
        <nav aria-label={t('situationRoom.breadcrumbLabel')} className="text-sm">
            <ol className="flex flex-wrap items-center gap-1 text-(--app-muted-foreground)">
                <li><Link href={route('situation-room.community-voice', rest)} className="underline-offset-4 hover:underline">{t('situationRoom.backToAll')}</Link></li>
                {scope.breadcrumb.map((crumb, index) => {
                    const last = index === scope.breadcrumb.length - 1;
                    return (
                        <li key={crumb.id} className="flex items-center gap-1">
                            <span aria-hidden="true">›</span>
                            {last ? <span aria-current="page" className="font-medium text-(--app-foreground)">{name(crumb)}</span>
                                : <Link href={institutionHref(crumb.id, filters, scope)} className="underline-offset-4 hover:underline">{name(crumb)}</Link>}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * The executive filter bar: institution in view, period, type, target
 * and status. Applying reloads the page with the new query; the
 * server re-validates everything.
 */
export function FilterBar({ scope, filters, options, routeName = 'situation-room.community-voice', showTypeFilters = true }: {
    scope: ScopeProps;
    filters: Filters;
    options: FilterOptions;
    routeName?: string;
    showTypeFilters?: boolean;
}) {
    const { t } = useLocale();
    const name = useNamer();
    const [draft, setDraft] = useState({
        organization: scope.node?.id ?? '',
        period: filters.period,
        from: filters.from,
        to: filters.to,
        type: filters.type ?? '',
        target: filters.target ?? '',
        status: filters.status ?? '',
    });
    const set = (key: keyof typeof draft) => (value: string) => setDraft((current) => ({ ...current, [key]: value }));
    const nodeOption: SearchableOption[] = scope.node ? [{ value: scope.node.id, label: name(scope.node), description: scope.node.code ?? undefined }] : [];

    async function searchInstitutions(query: string, signal: AbortSignal): Promise<SearchableOption[]> {
        const response = await fetch(route('situation-room.organization-options', { q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const rows = (await response.json()) as ({ id: string; code: string | null } & Name)[];
        return rows.map((row) => ({ value: row.id, label: name(row), description: row.code ?? undefined }));
    }

    function apply() {
        const query = Object.fromEntries(Object.entries({
            ...draft,
            from: draft.period === 'custom' ? draft.from : '',
            to: draft.period === 'custom' ? draft.to : '',
        }).filter(([, value]) => value !== ''));
        router.get(route(routeName), query, { preserveScroll: true });
    }

    return (
        <FilterPanel
            onSubmit={apply}
            onReset={() => router.get(route(routeName), {}, { preserveScroll: true })}
            hasActiveFilters={Boolean(scope.node || filters.type || filters.target || filters.status || filters.period !== '30d')}
            label={t('common.filters')}
            submitLabel={t('situationRoom.apply')}
        >
                <FilterField label={t('situationRoom.scopeLabel')} htmlFor="sr-scope" wide>
                    <SearchableSelect className="w-full" id="sr-scope" label={t('situationRoom.scopeLabel')} value={draft.organization} options={draft.organization === scope.node?.id ? nodeOption : undefined} loadOptions={searchInstitutions} onChange={(value) => set('organization')(value)} placeholder={t('situationRoom.cityWide')} searchPlaceholder={t('situationRoom.searchInstitutions')} emptyText={t('situationRoom.noInstitutionsFound')} />
                </FilterField>
                <FilterField label={t('situationRoom.periodLabel')} htmlFor="sr-period">
                    <Select id="sr-period" value={draft.period} onChange={(e) => set('period')(e.target.value)}>
                        {options.periods.map((period) => <option key={period} value={period}>{t(`situationRoom.period_${period}`)}</option>)}
                    </Select>
                </FilterField>
                {draft.period === 'custom' && (
                    <>
                        <FilterField label={t('situationRoom.from')} htmlFor="sr-from">
                            <LocalizedDatePicker id="sr-from" value={draft.from} onChange={set('from')} />
                        </FilterField>
                        <FilterField label={t('situationRoom.to')} htmlFor="sr-to">
                            <LocalizedDatePicker id="sr-to" value={draft.to} min={draft.from || undefined} onChange={set('to')} />
                        </FilterField>
                    </>
                )}
                {showTypeFilters && (
                    <>
                        <FilterField label={t('situationRoom.typeLabel')} htmlFor="sr-type">
                            <Select id="sr-type" value={draft.type} onChange={(e) => set('type')(e.target.value)}>
                                <option value="">{t('situationRoom.all')}</option>
                                {options.types.map((type) => <option key={type} value={type}>{t(`situationRoom.type_${type}`)}</option>)}
                            </Select>
                        </FilterField>
                        <FilterField label={t('situationRoom.targetLabel')} htmlFor="sr-target">
                            <Select id="sr-target" value={draft.target} onChange={(e) => set('target')(e.target.value)}>
                                <option value="">{t('situationRoom.all')}</option>
                                {options.targets.map((target) => <option key={target} value={target}>{t(`situationRoom.target_${target}`)}</option>)}
                            </Select>
                        </FilterField>
                    </>
                )}
                <FilterField label={t('situationRoom.statusLabel')} htmlFor="sr-status">
                    <Select id="sr-status" value={draft.status} onChange={(e) => set('status')(e.target.value)}>
                        <option value="">{t('situationRoom.all')}</option>
                        {options.statuses.map((status) => <option key={status} value={status}>{t(`situationRoom.status_${status}`)}</option>)}
                    </Select>
                </FilterField>
        </FilterPanel>
    );
}

/** A section card with a heading; children render the body. */
export function Section({ title, intro, actions, children, className, id }: { title: ReactNode; intro?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; id?: string }) {
    const headingId = id ? `${id}-heading` : undefined;
    return (
        <section aria-labelledby={headingId} className={cx('min-w-0', className)}>
            <Card className="h-full p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id={headingId} className="font-semibold">{title}</h2>
                        {intro && <p className="mt-1 text-sm text-(--app-muted-foreground)">{intro}</p>}
                    </div>
                    {actions}
                </div>
                <div className="mt-4">{children}</div>
            </Card>
        </section>
    );
}

/** Placeholder while a deferred section loads. */
export function SectionSkeleton({ rows = 3, className }: { rows?: number; className?: string }) {
    const { t } = useLocale();
    return (
        <Card className={cx('p-5', className)} aria-busy="true">
            <span className="sr-only">{t('situationRoom.loading')}</span>
            <div aria-hidden="true" className="space-y-3">
                <div className="h-4 w-1/3 animate-pulse rounded-sm bg-(--app-surface-muted)" />
                {Array.from({ length: rows }, (_, i) => <div key={i} className="h-3 animate-pulse rounded-sm bg-(--app-surface-muted)" />)}
            </div>
        </Card>
    );
}

/** A thin proportional bar under a label, for small ranked lists. */
export function MeterRow({ label, value, max, hint, href }: { label: string; value: number; max: number; hint?: string; href?: string }) {
    const width = max > 0 && value > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;
    const text = href ? <Link href={href} className="truncate font-medium underline-offset-4 hover:underline">{label}</Link> : <span className="truncate font-medium">{label}</span>;

    return (
        <li>
            <div className="flex items-baseline justify-between gap-3 text-sm">
                {text}
                <span className="font-semibold tabular-nums">{value.toLocaleString()}</span>
            </div>
            <div className="mt-1 h-1.5 rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                <div className="h-1.5 rounded-full bg-primary" style={{ width: `${width}%` }} />
            </div>
            {hint && <p className="mt-1 text-xs text-(--app-muted-foreground)">{hint}</p>}
        </li>
    );
}
