import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import PageHeader from '@/Components/PageHeader';
import AppMetricCard from '@/Components/ui/AppMetricCard';
import { Empty, Section, filterInputCls, formatScore, pageCls, panelCls, secondaryBtn } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router } from '@inertiajs/react';
import { Alert, StatusBadge as UiStatusBadge, type Tone } from '@euisis/ui';
import { AlertTriangle } from '@/Components/Icons';
import type { FormEvent, ReactNode } from 'react';

/*
 * Assessment Oversight & Compliance page kit (docs/assessment-oversight.md).
 * Every page is cycle-aware: the selected cycle travels in ?cycle= and the
 * server never mixes cycles in one total.
 */

export type Named = { id?: string; code?: string | null; name_en?: string | null; name_am?: string | null } | null | undefined;

export type Cycle = {
    id: string; code: string; name_en: string; name_am: string | null; status: string; eligibility_status: string;
    period_start: string; period_end: string; reference_date: string; submission_deadline: string | null; verification_deadline: string | null;
    population_rule: string; min_service_days: number | null; exclusion_reduces_denominator: boolean | null; small_group_threshold: number | null;
    reminder_days_before: number | null; eligibility_finalized_at: string | null; needs_decision: string[];
};

export type CycleOption = { id: string; code: string; name_en: string; name_am: string | null; status: string; period_start: string; period_end: string };

export type Can = { dashboard: boolean; institutions: boolean; employees: boolean; results: boolean; demographics: boolean; dataQuality: boolean; submissions: boolean; reports: boolean; export: boolean; setup: boolean };

export type GenderCell = { eligible: number | null; assessed: number | null; unassessed: number | null; coverage_percent: string | null; suppressed?: boolean };

export type Metrics = {
    group_key?: string | null; population: number; eligible: number; excluded: number; approved_exclusions: number; assigned: number;
    assessed: number; unassessed: number; coverage_percent: string | null; gross_coverage_percent: string | null;
    outcomes: Record<string, number>; gender: Record<'male' | 'female' | 'other' | 'unknown', GenderCell> | null;
};

export type Band = { id: string; code: string; label_en: string; label_am: string | null; min_score: string; max_score: string; min_inclusive: boolean; max_inclusive: boolean; count: number; percent: string | null };

export type Distribution = { policy: { id: string; code: string; version_no: number; name_en: string; name_am: string | null } | null; bands: Band[]; breakdown: Record<string, Record<string, number>>; assessed: number; classified: number; unclassified: number };

export type ShellProps = { cycle: Cycle | null; cycles: CycleOption[]; can: Can; scope: { cityWide: boolean; unitLimited: boolean } };

export const OUTCOMES = ['assessed', 'approved_exception', 'reported_unassessed', 'awaiting_review', 'in_progress', 'not_started', 'not_assigned', 'invalid_result'] as const;
export const GENDERS = ['male', 'female', 'other', 'unknown'] as const;

export function named(value: Named, locale: string): string {
    if (!value) return '—';
    return (locale === 'am' && value.name_am) || value.name_en || value.name_am || value.code || '—';
}

export function pct(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : `${formatScore(value)}%`;
}

/** Link to an oversight page keeping the selected cycle. */
export function oversightHref(name: string, cycle: Cycle | null, params: Record<string, string | number | undefined | null> = {}): string {
    const query = Object.fromEntries(Object.entries({ cycle: cycle?.id, ...params }).filter(([, v]) => v !== undefined && v !== null && v !== ''));
    return route(name, query as Record<string, string>);
}

export function exportHref(report: string, format: 'csv' | 'xlsx' | 'pdf', cycle: Cycle, filters: Record<string, unknown> = {}): string {
    const params = Object.fromEntries(Object.entries({ ...filters, report, format, cycle: cycle.id }).filter(([, v]) => v !== undefined && v !== null && v !== ''));
    return route('assessment-oversight.export', params as Record<string, string>);
}

export function OversightLayout({ title, description, active, shell, actions, children, needsCycle = true }: {
    title: string; description?: string; active: string; shell: ShellProps; actions?: ReactNode; children: ReactNode; needsCycle?: boolean;
}) {
    const { t, locale } = useLocale();
    // Section navigation lives in the sidebar (its own Assessment Oversight category).
    const { cycle, cycles } = shell;
    const calendar = useCalendarSystem();

    function pickCycle(id: string) {
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        delete params.page;
        router.get(window.location.pathname, { ...params, cycle: id }, { preserveState: false });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={description} actions={actions} />}>
            <Head title={title} />
            <div className={pageCls}>
                <div className={`${panelCls} flex flex-wrap items-center gap-3 px-4 py-3`}>
                    <label htmlFor="ao-cycle" className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('assessmentOversight.cycle')}</label>
                    <select id="ao-cycle" className={`${filterInputCls} min-w-64`} value={cycle?.id ?? ''} onChange={(e) => pickCycle(e.target.value)}>
                        <option value="" disabled>{t('assessmentOversight.selectCycle')}</option>
                        {cycles.map((c) => <option key={c.id} value={c.id}>{named(c, locale)} ({formatDateDisplay(c.period_start, calendar, locale)} – {formatDateDisplay(c.period_end, calendar, locale)}) · {t(`assessmentOversight.cycleStatuses.${c.status}`)}</option>)}
                    </select>
                    {cycle && <UiStatusBadge tone={cycle.eligibility_status === 'finalized' ? 'success' : 'warning'}>{t(`assessmentOversight.eligibilityStatuses.${cycle.eligibility_status}`)}</UiStatusBadge>}
                    {cycle && <span className="text-xs text-gray-500 dark:text-slate-400">
                        {t('assessmentOversight.referenceDate')}: <LocalizedDateDisplay value={cycle.reference_date} />
                        {cycle.submission_deadline && <> · {t('assessmentOversight.submissionDeadline')}: <LocalizedDateDisplay value={cycle.submission_deadline} /></>}
                    </span>}
                </div>

                {cycle && cycle.needs_decision.length > 0 && <NeedsDecision items={cycle.needs_decision} />}
                {cycle && cycle.eligibility_status !== 'finalized' && <Alert tone="warning">{t('assessmentOversight.provisional')}</Alert>}

                {needsCycle && !cycle ? <section className={panelCls}><Empty>{t('assessmentOversight.chooseCycle')}</Empty></section> : children}
            </div>
        </AuthenticatedLayout>
    );
}

export function NeedsDecision({ items }: { items: string[] }) {
    const { t } = useLocale();
    return (
        <details className="rounded-card bg-sky-50 px-4 py-3 text-sm text-sky-800 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/30 dark:text-sky-200 dark:ring-sky-900">
            <summary className="cursor-pointer font-medium">{t('assessmentOversight.needsDecision.title').replace(':count', String(items.length))}</summary>
            <ul className="mt-2 list-disc space-y-1 ps-5">
                {items.map((item) => <li key={item}>{t(`assessmentOversight.needsDecision.${item}`)}</li>)}
            </ul>
        </details>
    );
}

/** KPI card; clicking it opens the filtered list behind the number. */
export function Kpi({ label, value, suffix = '', href, hint, tone }: { label: string; value: number | string | null | undefined; suffix?: string; href?: string; hint?: ReactNode; tone?: 'danger' | 'warning' }) {
    const { t } = useLocale();
    const missing = value === null || value === undefined;
    const numeric = value !== '' && Number.isFinite(Number(value));
    const display = missing ? t('assessmentOversight.notAvailable') : numeric
        ? `${typeof value === 'number' ? value.toLocaleString() : formatScore(value)}${suffix}` : String(value);
    const body = <AppMetricCard label={label} value={display} detail={hint}
        variant={tone && Number(value) > 0 ? tone : missing ? 'neutral' : 'primary'} />;
    return href ? <Link href={href} className="block rounded-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2">{body}</Link> : body;
}

const KPI_COLUMNS = {
    4: 'xl:grid-cols-4',
    5: 'xl:grid-cols-5',
    6: 'xl:grid-cols-6',
} as const;

/** A labelled row of KPI cards; the dashboard uses rows of 6 (institutions) and 5 (employees). */
export function KpiGroup({ title, children, columns = 4 }: { title: string; children: ReactNode; columns?: keyof typeof KPI_COLUMNS }) {
    return (
        <section aria-label={title}>
            <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{title}</h2>
            <div className={`grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 ${KPI_COLUMNS[columns]}`}>{children}</div>
        </section>
    );
}

/** Small tile with a count badge, for the dashboard's compliance panel. */
export function IssueTile({ label, value, tone, href }: { label: string; value: number; tone: Tone; href?: string }) {
    const body = <>
        <span className="text-sm text-gray-700 dark:text-slate-200">{label}</span>
        <UiStatusBadge tone={value > 0 ? tone : 'neutral'} className="tabular-nums">{value.toLocaleString()}</UiStatusBadge>
    </>;
    const cls = 'flex min-h-11 items-center justify-between gap-2 rounded-card border border-gray-200 px-4 py-3 dark:border-slate-700';
    return href ? <Link href={href} className={`${cls} hover:bg-gray-50 dark:hover:bg-slate-800/50`}>{body}</Link> : <div className={cls}>{body}</div>;
}

/** Horizontal coverage bars (lowest first), as on the design canvas. */
export function CoverageBars({ rows }: { rows: Array<{ id: string; label: string; value: string | null; href?: string }> }) {
    return (
        <ul className="space-y-2.5">
            {rows.map((row) => {
                const width = row.value === null ? 0 : Math.max(0, Math.min(100, Number(row.value)));
                return (
                    <li key={row.id} className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_3.5rem] items-center gap-3 text-sm">
                        {row.href ? <Link href={row.href} className="truncate text-gray-700 hover:underline dark:text-slate-200">{row.label}</Link> : <span className="truncate text-gray-700 dark:text-slate-200">{row.label}</span>}
                        <div className="h-2.5 rounded bg-gray-100 dark:bg-slate-800" role="presentation"><div className="h-2.5 rounded bg-[color:var(--color-primary)]" style={{ width: `${width}%` }} /></div>
                        <span className="text-right tabular-nums">{pct(row.value)}</span>
                    </li>
                );
            })}
        </ul>
    );
}

export function CoverageBar({ value }: { value: string | null }) {
    const width = value === null ? 0 : Math.max(0, Math.min(100, Number(value)));
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-20 rounded-full bg-gray-100 dark:bg-slate-800" role="presentation"><div className="h-1.5 rounded-full bg-[color:var(--color-primary)]" style={{ width: `${width}%` }} /></div>
            <span className="tabular-nums">{pct(value)}</span>
        </div>
    );
}

/** Gender breakdown: eligible, assessed, unassessed, coverage; suppressed small groups show as such. */
export function GenderTable({ gender }: { gender: Metrics['gender'] }) {
    const { t } = useLocale();
    if (!gender) return null;
    const rows = GENDERS.filter((g) => g !== 'other' || (gender.other.eligible ?? 0) > 0 || gender.other.suppressed);
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="text-xs text-gray-500 dark:text-slate-400"><tr>
                    <th className="py-2 pe-4 font-semibold">{t('assessmentOversight.gender')}</th>
                    <th className="py-2 pe-4 font-semibold">{t('assessmentOversight.eligible')}</th>
                    <th className="py-2 pe-4 font-semibold">{t('assessmentOversight.assessed')}</th>
                    <th className="py-2 pe-4 font-semibold">{t('assessmentOversight.unassessed')}</th>
                    <th className="py-2 font-semibold">{t('assessmentOversight.coverage')}</th>
                </tr></thead>
                <tbody className="[&>tr]:border-t [&>tr]:border-gray-100 dark:[&>tr]:border-slate-800">
                    {rows.map((g) => {
                        const cell = gender[g];
                        return (
                            <tr key={g}>
                                <td className="py-2 pe-4">{t(`assessmentOversight.genders.${g}`)}</td>
                                {cell.suppressed
                                    ? <td colSpan={4} className="py-2 text-xs text-gray-500">{t('assessmentOversight.suppressed')}</td>
                                    : <>
                                        <td className="py-2 pe-4 tabular-nums">{cell.eligible}</td>
                                        <td className="py-2 pe-4 tabular-nums">{cell.assessed}</td>
                                        <td className="py-2 pe-4 tabular-nums">{cell.unassessed}</td>
                                        <td className="py-2 tabular-nums">{pct(cell.coverage_percent)}</td>
                                    </>}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/** Data-quality severity on the shared status badge (same tones as every admin table). */
export function SeverityBadge({ severity }: { severity: string }) {
    const { t } = useLocale();
    const tone: Tone = severity === 'blocking' ? 'danger' : severity === 'warning' ? 'warning' : 'info';
    return <UiStatusBadge tone={tone} className="gap-1">{severity === 'blocking' && <AlertTriangle className="h-3 w-3" aria-hidden />}{t(`assessmentOversight.severity.${severity}`)}</UiStatusBadge>;
}

/** Status tones shared by institutions, outcomes, submissions and deadlines. */
const STATUS_TONES: Record<string, Tone> = {
    finalized: 'success', verified: 'success', assessed: 'success', met: 'success',
    ready_for_submission: 'info', submitted: 'info', awaiting_review: 'info', in_progress: 'info', approved_exception: 'info', reported_unassessed: 'info',
    returned: 'warning', outdated: 'warning', due_soon: 'warning',
    rejected: 'danger', overdue: 'danger', invalid_result: 'danger',
};

export function StatusBadge({ group, value }: { group: 'institutionStatuses' | 'outcomes' | 'submissionStatuses' | 'deadline'; value: string | null | undefined }) {
    const { t } = useLocale();
    if (!value) return <span className="text-gray-400">—</span>;
    return <UiStatusBadge tone={STATUS_TONES[value] ?? 'neutral'}>{t(`assessmentOversight.${group}.${value}`)}</UiStatusBadge>;
}

/** GET filter form that always keeps the selected cycle. */
export function Filters({ routeName, cycle, children, keep = {} }: { routeName: string; cycle: Cycle | null; children: ReactNode; keep?: Record<string, string | undefined> }) {
    const { t } = useLocale();
    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const params = { ...keep, ...Object.fromEntries(new FormData(e.currentTarget)) } as Record<string, string>;
        Object.keys(params).forEach((k) => { if (!params[k]) delete params[k]; });
        router.get(route(routeName), { ...params, cycle: cycle?.id ?? '' }, { preserveState: true });
    }
    return (
        <form className="flex flex-wrap items-center gap-3" onSubmit={submit}>
            {children}
            <button type="submit" className="inline-flex items-center rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">{t('common.filter')}</button>
            <Link href={oversightHref(routeName, cycle, keep)} className={secondaryBtn}>{t('assessmentOversight.clearFilters')}</Link>
        </form>
    );
}

export function ExportButtons({ report, cycle, filters = {}, formats = ['csv', 'xlsx', 'pdf'] }: { report: string; cycle: Cycle; filters?: Record<string, unknown>; formats?: Array<'csv' | 'xlsx' | 'pdf'> }) {
    const { t } = useLocale();
    return (
        <div className="flex flex-wrap gap-2">
            {formats.map((format) => <a key={format} href={exportHref(report, format, cycle, filters)} className={secondaryBtn}>{t(`assessmentOversight.export.${format}`)}</a>)}
        </div>
    );
}

export function Reconciliation({ checks }: { checks: Array<{ check: string; ok: boolean; expected: number; actual: number }> }) {
    const { t } = useLocale();
    const failing = checks.filter((c) => !c.ok);
    return (
        <Section title={t('assessmentOversight.reconciliation.title')} description={t('assessmentOversight.reconciliation.help')}>
            {failing.length === 0
                ? <p className="text-sm text-emerald-700 dark:text-emerald-300">{t('assessmentOversight.reconciliation.ok')}</p>
                : <ul className="space-y-1 text-sm text-red-700 dark:text-red-300">{failing.map((c) => <li key={c.check}>{t(`assessmentOversight.reconciliation.${c.check}`)}: {c.expected} ≠ {c.actual}</li>)}</ul>}
        </Section>
    );
}
