import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button, FilterField, FilterPanel, Select } from '@euisis/ui';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Building2, ChevronDown, SearchIcon } from '@/Components/Icons';
import { formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { useSituationLabels } from './useSituationLabels';
import type { SrFilters, SrNamedOption, SrQuery, SrScope } from '@/types/situationRoom';

export const PERIOD_PRESETS = ['today', 'week', 'month', 'quarter', 'year', 'custom'] as const;

export interface FilterOptions {
    units: SrNamedOption[];
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
                    {PERIOD_PRESETS.map((p) => <option key={p} value={p}>{sr(`ui.periods.${p}`)}</option>)}
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
export function ScopeTrail({ url, query, scope, actions }: { url: string; query: SrQuery; scope: SrScope; actions?: ReactNode }) {
    const { sr, label } = useSituationLabels();
    const without = { ...query };
    delete without.organization_id;
    delete without.organization_unit_id;

    return (
        <nav aria-label={sr('ui.organization')} className="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-(--app-border) bg-(--app-surface) px-4 py-3 text-sm">
            <div className="flex min-w-0 items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-(--app-surface-muted) text-primary"><Building2 className="h-5 w-5" aria-hidden="true" /></span>
                <div className="flex min-w-0 flex-wrap items-center gap-x-1 gap-y-0.5">
                    {scope.breadcrumb.length === 0
                        ? <span aria-current="page" className="font-semibold text-(--app-foreground)">{sr(scope.unrestricted ? 'ui.scope_all' : 'ui.scope_mine')}</span>
                        : <Link href={url} data={without} className="text-primary hover:underline">{sr(scope.unrestricted ? 'ui.scope_all' : 'ui.scope_mine')}</Link>}
                    {scope.breadcrumb.map((step, i) => (
                        <span key={step.id} className="flex items-center gap-1">
                            <span aria-hidden="true" className="text-(--app-muted-foreground)">›</span>
                            {i === scope.breadcrumb.length - 1
                                ? <span aria-current="page" className="font-semibold text-(--app-foreground)">{label(step.label)}</span>
                                : <Link href={url} data={{ ...without, organization_id: step.id }} className="text-primary hover:underline">{label(step.label)}</Link>}
                        </span>
                    ))}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {actions}
                {scope.children.length > 0 && <LookInside url={url} query={without} scope={scope} />}
            </div>
        </nav>
    );
}

/** Every organization one level down, searchable: a city has too many to lay out as chips. */
function LookInside({ url, query, scope }: { url: string; query: SrQuery; scope: SrScope }) {
    const { sr, label } = useSituationLabels();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const root = useRef<HTMLDivElement>(null);
    const needle = search.trim().toLowerCase();
    const matches = scope.children.filter((child) => label(child.label).toLowerCase().includes(needle));

    useEffect(() => {
        if (!open) return undefined;
        const onPointer = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onPointer);
        return () => document.removeEventListener('mousedown', onPointer);
    }, [open]);

    return (
        <div
            ref={root}
            className="relative"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    setOpen(false);
                    root.current?.querySelector('button')?.focus();
                }
            }}
        >
            <Button variant="outline" size="sm" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
                <span className="inline-flex items-center whitespace-nowrap">
                    {sr('ui.drill_down')}
                    <span className="ml-1.5 rounded-full bg-(--app-surface-muted) px-1.5 text-xs tabular-nums text-(--app-foreground)">{scope.children.length}</span>
                    <ChevronDown className={`ml-1 h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
                </span>
            </Button>
            {open && (
                <div className="absolute right-0 top-full z-30 mt-2 w-[min(36rem,calc(100vw-3rem))] rounded-card border border-(--app-border) bg-(--app-surface) p-2 shadow-lg">
                    <label className="flex items-center gap-2 rounded-control border border-(--app-border) px-2.5 py-1.5 focus-within:border-primary">
                        <SearchIcon className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
                        <span className="sr-only">{sr('ui.search_organization')}</span>
                        <input
                            autoFocus
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && matches.length > 0) {
                                    event.preventDefault();
                                    setOpen(false);
                                    router.get(url, { ...query, organization_id: matches[0].id });
                                }
                            }}
                            placeholder={sr('ui.search_organization')}
                            className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-(--app-foreground) outline-hidden placeholder:text-(--app-muted-foreground) focus:ring-0"
                        />
                    </label>
                    {matches.length === 0
                        ? <p className="px-2 py-4 text-center text-sm text-(--app-muted-foreground)" role="status">{sr('ui.no_organization_match')}</p>
                        : (
                            <ul className="mt-2 grid max-h-80 grid-cols-1 gap-0.5 overflow-y-auto sm:grid-cols-2">
                                {matches.map((child) => (
                                    <li key={child.id}>
                                        <Link href={url} data={{ ...query, organization_id: child.id }} onClick={() => setOpen(false)} className="block rounded-control px-2.5 py-1.5 text-sm text-(--app-foreground) hover:bg-(--app-surface-muted) focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary">
                                            {label(child.label)}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                </div>
            )}
        </div>
    );
}

/** When the figures were computed and how long they may be reused; never passed off as live. */
export function Freshness({ generatedAt, cachedFor, only = ['result'], tone = 'light' }: { generatedAt: string; cachedFor: number; only?: string[]; tone?: 'light' | 'dark' }) {
    const { sr, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const text = `${sr('ui.computed_at', { time: formatDateTimeDisplay(generatedAt, system, locale) })}${cachedFor > 0 ? ` · ${sr('ui.reused_for', { minutes: Math.round(cachedFor / 60) })}` : ''}`;

    if (tone === 'dark') {
        return (
            <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-white/70" aria-live="polite">
                <span>{text}</span>
                <button type="button" onClick={() => router.reload({ only })} className="rounded-full bg-white/12 px-2.5 py-1 font-semibold text-white transition-colors hover:bg-white/20 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-white">
                    {sr('ui.refresh')}
                </button>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 text-xs text-(--app-muted-foreground)" aria-live="polite">
            <span>{text}</span>
            <Button variant="ghost" size="xs" onClick={() => router.reload({ only })}>{sr('ui.refresh')}</Button>
        </div>
    );
}
