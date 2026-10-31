import { Field, inputCls, nameOf, useEnumLabel } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';

/*
 * Pieces shared by the strategy pages, which follow the WLMS layout (ADR 0019):
 * bordered tables, "Given: x% | y%" weights and the balanced / needs-fix badge.
 */

export type Named = { id: string; name_en: string; name_am?: string | null };
export type Cycle = Named & { code: string; organization_id: string | null; status: string; is_current: boolean };
export type Filters = { cycle_id: string; organization_id: string };
export type Can = { create: boolean; update: boolean; delete: boolean; plan: boolean };
export type Context = { organization_id: string; cycle_id: string; organization: Named | null; cycle: (Named & { code: string }) | null };

export type StrategyRow = { id: string; order_no: number; title: string; description: string | null; weight_percent: string; normalized: string | null; has_goal: boolean };
export type MainRow = {
    id: string; number: number; title: string; description: string | null; weight_percent: string;
    strategies: StrategyRow[]; strategies_total: string; normalized_total: string | null; balanced: boolean;
};
export type ActivityRow = { id: string; serial: string; title: string; weight_percent: string; time_minutes: number | null };
export type ObjectiveRow = { id: string; serial: string; title: string; weight_percent: string; normalized: string | null; activities_total: string; activities: ActivityRow[] };
export type GoalRow = {
    id: string; code: string; serial: string; strategy_title: string; strategy_weight: string; description: string | null;
    is_active: boolean; status: string; objectives: ObjectiveRow[]; activities_count: number;
    objectives_total: string; normalized_total: string | null; balanced: boolean;
};

export function truncate(text: string | null | undefined, length: number): string {
    if (!text) return '';
    return text.length > length ? `${text.slice(0, length)}...` : text;
}

export const wTable ='w-full border-collapse text-sm';
export const wTh = 'border border-(--app-border) bg-(--app-surface-muted) p-2 text-left text-xs font-semibold text-(--app-foreground)';
export const wTd = 'border border-(--app-border) p-2 align-top';
export const ok = 'text-emerald-700 dark:text-emerald-400';
export const off = 'text-red-700 dark:text-red-400';
export const boxCls = 'rounded-panel border border-(--app-border) bg-(--app-surface-muted)/40 p-3';

/** Two decimals, as the WLMS pages print every weight. */
export const pct = (value: string | number | null | undefined) => (Number(value ?? 0) || 0).toFixed(2);
/** Weights summed in ten-thousandths, the stored scale, so 33.33 + 66.67 is exactly 100. */
export const units = (value: string | number) => Math.round((parseFloat(String(value)) || 0) * 10000);

/** "Given: 40.00% | 66.67%": the entered weight, then its share of the level above. */
export function GivenWeight({ given, share, label }: { given: string | number; share: string | number | null; label: string }) {
    return <span className="tabular-nums"><span className="text-(--app-muted-foreground)">{label}: {pct(given)}%</span> | {share === null ? '—' : `${pct(share)}%`}</span>;
}

export function BalanceBadge({ balanced, labels }: { balanced: boolean; labels: { balanced: string; needsFix: string } }) {
    return (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${balanced ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'}`}>
            {balanced ? labels.balanced : labels.needsFix}
        </span>
    );
}

/** Small summary card like the WLMS list headers. */
export function Card({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="rounded-card border border-(--app-border) bg-(--app-surface) p-4">
            <p className="text-xs text-(--app-muted-foreground)">{label}</p>
            <div className="mt-1 text-xl font-semibold tabular-nums text-(--app-foreground)">{children}</div>
        </div>
    );
}

/** Organization and cycle: EUISIS keeps one strategy per organization and performance cycle. */
export function ContextFilter({ routeName, filters, cycles, organizations }: { routeName: string; filters: Filters; cycles: Cycle[]; organizations: Named[] }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const cycleOptions = cycles.filter((c) => !filters.organization_id || c.organization_id === null || c.organization_id === filters.organization_id);

    function filter(key: keyof Filters, value: string) {
        const next = { ...filters, [key]: value };
        if (key === 'organization_id' && next.cycle_id) {
            const chosen = cycles.find((c) => c.id === next.cycle_id);
            if (chosen?.organization_id && chosen.organization_id !== value) next.cycle_id = '';
        }
        router.get(route(routeName), next, { preserveState: true, replace: true });
    }

    return (
        <section className="grid gap-4 rounded-panel border border-(--app-border) bg-(--app-surface) p-4 sm:grid-cols-2" aria-label={t('performance.strategy.selectContext')}>
            <Field label={t('performance.fields.organization')} htmlFor="st-organization">
                <select id="st-organization" className={inputCls} value={filters.organization_id} onChange={(e) => filter('organization_id', e.target.value)}>
                    <option value="">{t('performance.strategicGoals.selectOrganization')}</option>
                    {organizations.map((o) => <option key={o.id} value={o.id}>{nameOf(o, locale)}</option>)}
                </select>
            </Field>
            <Field label={t('performance.fields.cycle')} htmlFor="st-cycle">
                <select id="st-cycle" className={inputCls} value={filters.cycle_id} onChange={(e) => filter('cycle_id', e.target.value)}>
                    <option value="">{t('performance.strategicGoals.selectCycle')}</option>
                    {cycleOptions.map((c) => (
                        <option key={c.id} value={c.id}>{c.code} · {nameOf(c, locale)} ({label('cycle', c.status)}{c.is_current ? ` · ${t('performance.cycles.current')}` : ''})</option>
                    ))}
                </select>
            </Field>
        </section>
    );
}

/** The organization and cycle a form or detail page belongs to, under its title. */
export function contextLine(context: Context, locale: string): string {
    return `${nameOf(context.organization, locale)} · ${context.cycle?.code ?? ''} ${nameOf(context.cycle, locale)}`;
}
