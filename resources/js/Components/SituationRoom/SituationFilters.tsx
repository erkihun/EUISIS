import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button, FilterField, FilterPanel, Select } from '@euisis/ui';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { useSituationLabels } from './useSituationLabels';
import type { SrFilters, SrNamedOption, SrQuery, SrScope } from '@/types/situationRoom';

const PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'] as const;

export interface FilterOptions {
    units: SrNamedOption[];
    feedback_categories: SrNamedOption[];
    public_voice: boolean;
    tip_types?: boolean;
}

/**
 * The view's filters, kept in the URL so a view can be bookmarked, shared and
 * reloaded. The organization list is the scope seen from here: everything in
 * scope, the path to the current focus, and the next level down to drill
 * into. The server re-checks every value against the viewer's scope.
 */
export function SituationFilterBar({ url, filters, query, scope, options }: {
    url: string;
    filters: SrFilters;
    query: SrQuery;
    scope: SrScope;
    options?: FilterOptions;
}) {
    const { sr, label } = useSituationLabels();
    const [draft, setDraft] = useState<SrQuery>({
        ...query,
        from: query.from ?? filters.period.from,
        to: query.to ?? filters.period.to,
    });
    const set = (key: string) => (value: string) => setDraft((d) => ({ ...d, [key]: value }));
    const clean = (q: SrQuery): SrQuery => Object.fromEntries(Object.entries(q).filter(([k, v]) => v !== '' && v !== undefined && (q.period === 'custom' || (k !== 'from' && k !== 'to'))));
    const apply = () => router.get(url, clean(draft), { preserveScroll: true });
    const hasFilters = Object.keys(clean(query)).some((k) => k !== 'period') || (query.period ?? 'month') !== 'month';
    const organizationOptions: SrNamedOption[] = [...scope.breadcrumb, ...scope.children.filter((c) => !scope.breadcrumb.some((b) => b.id === c.id))];

    return (
        <FilterPanel onSubmit={apply} onReset={() => router.get(url)} hasActiveFilters={hasFilters} label={sr('ui.filters')} submitLabel={sr('ui.apply')} resetLabel={sr('ui.reset')}>
            <FilterField label={sr('ui.period')} htmlFor="sr-period">
                <Select id="sr-period" value={draft.period ?? 'month'} onChange={(e) => set('period')(e.target.value)}>
                    {PRESETS.map((p) => <option key={p} value={p}>{sr(`ui.periods.${p}`)}</option>)}
                </Select>
            </FilterField>
            {draft.period === 'custom' && (
                <>
                    <FilterField label={sr('ui.from')} htmlFor="sr-from">
                        <LocalizedDatePicker id="sr-from" value={draft.from ?? ''} onChange={set('from')} max={draft.to} />
                    </FilterField>
                    <FilterField label={sr('ui.to')} htmlFor="sr-to">
                        <LocalizedDatePicker id="sr-to" value={draft.to ?? ''} onChange={set('to')} min={draft.from} />
                    </FilterField>
                </>
            )}
            {organizationOptions.length > 0 && (
                <FilterField label={sr('ui.organization')} htmlFor="sr-organization" wide>
                    <Select id="sr-organization" value={draft.organization_id ?? ''} onChange={(e) => setDraft((d) => ({ ...d, organization_id: e.target.value, organization_unit_id: '' }))}>
                        <option value="">{sr('ui.all_in_scope')}</option>
                        {organizationOptions.map((o) => <option key={o.id} value={o.id}>{label(o.label)}</option>)}
                    </Select>
                </FilterField>
            )}
            {options && options.units.length > 0 && (
                <FilterField label={sr('ui.unit')} htmlFor="sr-unit">
                    <Select id="sr-unit" value={draft.organization_unit_id ?? ''} onChange={(e) => set('organization_unit_id')(e.target.value)}>
                        <option value="">{sr('ui.all_units')}</option>
                        {options.units.map((u) => <option key={u.id} value={u.id}>{label(u.label)}</option>)}
                    </Select>
                </FilterField>
            )}
            {options?.public_voice && (
                <>
                    {options.feedback_categories.length > 0 && (
                        <FilterField label={sr('ui.category_filter')} htmlFor="sr-category">
                            <Select id="sr-category" value={draft.category_id ?? ''} onChange={(e) => set('category_id')(e.target.value)}>
                                <option value="">{sr('ui.all_categories')}</option>
                                {options.feedback_categories.map((c) => <option key={c.id} value={c.id}>{label(c.label)}</option>)}
                            </Select>
                        </FilterField>
                    )}
                    <FilterField label={sr('ui.target_type')} htmlFor="sr-target">
                        <Select id="sr-target" value={draft.target_type ?? ''} onChange={(e) => set('target_type')(e.target.value)}>
                            <option value="">{sr('ui.all_targets')}</option>
                            {['employee', 'organization'].map((t) => <option key={t} value={t}>{sr(`values.target_type.${t}`)}</option>)}
                        </Select>
                    </FilterField>
                    {options.tip_types && (
                        <FilterField label={sr('ui.tip_type')} htmlFor="sr-tip">
                            <Select id="sr-tip" value={draft.tip_type ?? ''} onChange={(e) => set('tip_type')(e.target.value)}>
                                <option value="">{sr('ui.all_tip_types')}</option>
                                {['CORRUPTION', 'IMPROPER_PRACTICE'].map((t) => <option key={t} value={t}>{sr(`values.tip_type.${t}`)}</option>)}
                            </Select>
                        </FilterField>
                    )}
                </>
            )}
        </FilterPanel>
    );
}

/** Where the view is looking: a trail of the scope, each step a link that keeps the other filters. */
export function ScopeTrail({ url, query, scope }: { url: string; query: SrQuery; scope: SrScope }) {
    const { sr, label } = useSituationLabels();
    const without = { ...query };
    delete without.organization_id;
    delete without.organization_unit_id;

    return (
        <nav aria-label={sr('ui.organization')} className="flex flex-wrap items-center gap-1 text-sm">
            <Link href={url} data={without} className="text-[color:var(--color-primary)] hover:underline">{sr(scope.unrestricted ? 'ui.scope_all' : 'ui.scope_mine')}</Link>
            {scope.breadcrumb.map((step, i) => (
                <span key={step.id} className="flex items-center gap-1">
                    <span aria-hidden="true" className="text-[color:var(--app-muted-foreground)]">›</span>
                    {i === scope.breadcrumb.length - 1
                        ? <span aria-current="page" className="font-medium text-[color:var(--app-foreground)]">{label(step.label)}</span>
                        : <Link href={url} data={{ ...without, organization_id: step.id }} className="text-[color:var(--color-primary)] hover:underline">{label(step.label)}</Link>}
                </span>
            ))}
            {scope.children.length > 0 && (
                <span className="ml-2 flex flex-wrap items-center gap-1">
                    <span className="text-xs text-[color:var(--app-muted-foreground)]">{sr('ui.drill_down')}:</span>
                    {scope.children.slice(0, 12).map((child) => (
                        <Link key={child.id} href={url} data={{ ...without, organization_id: child.id }} className="rounded-control border border-[color:var(--app-border)] px-2 py-0.5 text-xs text-[color:var(--app-foreground)] hover:border-[color:var(--color-primary)]">
                            {label(child.label)}
                        </Link>
                    ))}
                </span>
            )}
        </nav>
    );
}

/** When the figures were computed and how long they may be reused; never passed off as live. */
export function Freshness({ generatedAt, cachedFor }: { generatedAt: string; cachedFor: number }) {
    const { sr, locale } = useSituationLabels();
    const system = useCalendarSystem();

    return (
        <div className="flex flex-wrap items-center gap-2 text-xs text-[color:var(--app-muted-foreground)]" aria-live="polite">
            <span>
                {sr('ui.computed_at', { time: formatDateTimeDisplay(generatedAt, system, locale) })}
                {cachedFor > 0 && ` · ${sr('ui.reused_for', { minutes: Math.round(cachedFor / 60) })}`}
            </span>
            <Button variant="ghost" size="xs" onClick={() => router.reload({ only: ['result'] })}>{sr('ui.refresh')}</Button>
        </div>
    );
}
