import LocalizedEmptyState from '@/Components/EmptyState';
import { Inbox } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { StatusBadge as UiStatusBadge, type Tone } from '@euisis/ui';
import ListPagination from '@/Components/ui/ListPagination';
import { cloneElement, isValidElement, useId, type ReactNode } from 'react';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName } from '@/Components/ui';
export { fill } from '@/Components/dailyActivity/helpers';
export { AppFilterBar, FilterField, filterInputCls } from '@/Components/ui';

/*
 * EPMS page kit. Every class here matches the admin pages (Employees,
 * Positions, NFC, Grievances): full-width content, rounded-panel cards with a
 * small heading, tables flush in their panel with a gray header row and
 * text-link row actions, the shared status badge, EmptyState and pagination.
 */

// ── Controls ──────────────────────────────────────────────────────────────────

export const inputCls = cx(inputClassName, 'w-full');
export const compactInputCls = inputClassName;
export const labelCls = 'mb-1 block text-sm font-medium text-[color:var(--app-foreground)]';

export const primaryBtn = buttonClassName({ variant: 'primary' });
export const secondaryBtn = buttonClassName({ variant: 'outline' });
export const dangerBtn = buttonClassName({ variant: 'destructiveOutline' });
/** Section-level secondary action (e.g. "Add KPI"). */
export const smallBtn = buttonClassName({ variant: 'outline', size: 'sm' });
/** Save button of an inline (in-table or in-card) form. */
export const smallPrimaryBtn = buttonClassName({ variant: 'primary', size: 'sm' });
/** Row actions, as on the admin tables: plain text links. */
export const linkBtn = 'text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] disabled:opacity-50';
export const dangerLinkBtn = 'text-xs font-medium text-red-600 hover:text-red-800 disabled:opacity-50 dark:text-red-400 dark:hover:text-red-300';

export const panelCls = 'rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]';
/** The one content wrapper for EPMS pages (full width, like every admin page). */
export const pageCls = 'min-w-0 space-y-4';

// ── Data helpers ──────────────────────────────────────────────────────────────

/** Laravel LengthAwarePaginator as Inertia serializes it (flat). */
export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    path: string;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Bilingual = { name_en?: string | null; name_am?: string | null } | null | undefined;
export type BilingualTitle = { title_en?: string | null; title_am?: string | null } | null | undefined;

/** Scores and decimals arrive as strings (Decimal math on the server); display to 2 dp without re-rounding logic. */
export function formatScore(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : String(value);
}

export function nameOf(value: Bilingual, locale: string): string {
    if (!value) return '';
    return (locale === 'am' && value.name_am) || value.name_en || '';
}

export function titleOf(value: BilingualTitle, locale: string): string {
    if (!value) return '';
    return (locale === 'am' && value.title_am) || value.title_en || '';
}

/** Employee display name: Amharic full name in AM, English name in EN, whichever exists otherwise. */
export function employeeName(e: { name?: string | null; name_en?: string | null } | null | undefined, locale: string): string {
    if (!e) return '—';
    return (locale === 'am' ? e.name : e.name_en) || e.name || e.name_en || '—';
}

/** Enum label helper: `label('agreement', 'ACTIVE')`. */
export function useEnumLabel() {
    const { t } = useLocale();
    return (group: string, value: string | null | undefined): string => {
        if (!value) return '—';
        const key = `performance.enums.${group}.${value}`;
        const text = t(key);
        return text === key ? value : text;
    };
}

// ── Status ────────────────────────────────────────────────────────────────────

const TONES: Record<string, Tone> = {
    DRAFT: 'neutral', PLANNING: 'info', CASCADED: 'info', AGREEMENT: 'info', ACTIVE: 'success', MID_YEAR_REVIEW: 'info', YEAR_END_REVIEW: 'info',
    CALIBRATION: 'warning', FINALIZED: 'success', CLOSED: 'neutral', CANCELLED: 'neutral',
    UNDER_REVIEW: 'warning', APPROVED: 'info', PUBLISHED: 'success', SUPERSEDED: 'neutral',
    PENDING_EMPLOYEE_REVIEW: 'warning', PENDING_MANAGER_APPROVAL: 'warning', RETURNED: 'danger', AGREED: 'info',
    EMPLOYEE_SUBMITTED: 'warning', MANAGER_REVIEW: 'warning', COMPLETED: 'success',
    CALCULATED: 'info', PENDING_CALIBRATION: 'warning', PENDING_RELEASE: 'warning', RELEASED: 'success',
    ON_TRACK: 'success', AT_RISK: 'warning', OFF_TRACK: 'danger', NOT_REPORTED: 'neutral',
    SUBMITTED: 'warning', DECIDED: 'success', WITHDRAWN: 'neutral', PENDING: 'warning', REJECTED: 'danger',
    UPHELD: 'success', PARTIALLY_UPHELD: 'info', IN_PROGRESS: 'info',
};

/** EPMS status: the shared status badge (same tones as every admin table), translated label. */
export function Pill({ group, value, tone }: { group: string; value: string | null | undefined; tone?: Tone }) {
    const label = useEnumLabel();
    return <UiStatusBadge tone={tone ?? TONES[value ?? ''] ?? 'neutral'} className="badge">{label(group, value)}</UiStatusBadge>;
}

// ── Layout blocks ─────────────────────────────────────────────────────────────

/**
 * A card with a heading, like the admin detail pages. `flush` removes the body
 * padding so a table sits edge to edge under the heading.
 */
export function Section({ title, description, actions, children, className = '', flush = false }: { title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; flush?: boolean }) {
    return (
        <section className={`${panelCls} ${flush ? 'overflow-hidden' : ''} ${className}`}>
            <div className={`flex flex-wrap items-start justify-between gap-3 px-5 pt-5 ${flush ? 'pb-4' : ''}`}>
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h3>
                    {description && <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            <div className={flush ? '' : 'px-5 pb-5 pt-4'}>{children}</div>
        </section>
    );
}

/** Label/value grid for record details (as on the admin show pages). */
export function Details({ items, columns = 4 }: { items: [label: string, value: ReactNode][]; columns?: 2 | 3 | 4 }) {
    const grid = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3', 4: 'sm:grid-cols-2 lg:grid-cols-4' }[columns];
    return (
        <dl className={`grid gap-4 ${grid}`}>
            {items.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{label}</dt>
                    <dd className="mt-0.5 break-words text-sm text-[color:var(--app-foreground)]">{value === null || value === undefined || value === '' ? '—' : value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function FieldError({ message }: { message?: string }) {
    return message ? <p className="mt-1 text-xs text-red-600 dark:text-red-400">{message}</p> : null;
}

/**
 * A labelled control. When the caller gives no `htmlFor`, the label is tied to a
 * single child control by a generated id, so every field is announced by name.
 */
export function Field({ label, htmlFor, error, help, children, className = '' }: { label: string; htmlFor?: string; error?: string; help?: string; children: ReactNode; className?: string }) {
    const generated = useId();
    const id = htmlFor ?? generated;
    const control = htmlFor === undefined && isValidElement<{ id?: string }>(children) && typeof children.type === 'string' && children.props.id === undefined
        ? cloneElement(children, { id })
        : children;
    return (
        <div className={className}>
            <label htmlFor={id} className={labelCls}>{label}</label>
            {control}
            {help && <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{help}</p>}
            <FieldError message={error} />
        </div>
    );
}

/** The shared EmptyState; `compact` for small side panels. */
export function Empty({ children, compact = false }: { children?: ReactNode; compact?: boolean }) {
    return <LocalizedEmptyState icon={<Inbox className={compact ? 'h-6 w-6' : 'h-8 w-8'} />} title={typeof children === 'string' ? children : undefined} className={compact ? 'py-6' : ''} />;
}

/** Server-computed problems that block the next step (validation before submit). */
export function Problems({ title, problems }: { title: string; problems: string[] }) {
    if (!problems.length) return null;
    return (
        <div role="alert" className="rounded-panel border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
            <p className="font-medium">{title}</p>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">{problems.map((p, i) => <li key={i}>{p}</li>)}</ul>
        </div>
    );
}

// ── Tables ────────────────────────────────────────────────────────────────────

export const tableCls = cx(tableClassName, 'min-w-full');
export const thCls = cx(tableHeaderCellClassName, 'text-left');
export const tdCls = cx(tableCellClassName, 'align-top');

/**
 * Admin table: gray header row, bordered rows, px-4 py-3 cells. Place it in a
 * `flush` Section or a TablePanel; `framed` draws its own border for tables
 * that sit inside a padded card.
 */
export function Table({ head, children, framed = false }: { head: ReactNode; children: ReactNode; framed?: boolean }) {
    return (
        <div className={`overflow-x-auto ${framed ? 'rounded-lg border border-[color:var(--app-border)]' : ''}`}>
            <table className={tableCls}>
                <thead className={tableHeadClassName}><tr>{head}</tr></thead>
                <tbody className={tableBodyClassName}>{children}</tbody>
            </table>
        </div>
    );
}

/** A list page's table card: the table flush, the pager in the footer, EmptyState when there are no rows. */
export function TablePanel<T>({ page, empty, children }: { page?: Paginator<T>; empty: string; children: ReactNode }) {
    const rows = page ? page.data.length : 1;
    return (
        <section className={`${panelCls} overflow-hidden`}>
            {rows === 0 ? <Empty>{empty}</Empty> : children}
            {page && <Pager page={page} />}
        </section>
    );
}

/** The design-system pager, driven by Laravel's paginator; keeps the current filters. */
export function Pager<T>({ page, bare = false }: { page: Paginator<T>; bare?: boolean }) {
    return <ListPagination meta={page} bare={bare} />;
}

// ── Figures ───────────────────────────────────────────────────────────────────

/** Summary figure, same card as the admin StatCard; "not calculated" when the server has no value. */
export function Stat({ label, value, suffix = '', hint }: { label: string; value: string | number | null | undefined; suffix?: string; hint?: ReactNode }) {
    const { t } = useLocale();
    const missing = value === null || value === undefined || value === '';
    return (
        <div className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <p className="truncate text-sm font-medium text-[color:var(--app-muted-foreground)]">{label}</p>
            <p className={`mt-2 ${missing ? 'text-sm text-[color:var(--app-muted-foreground)]' : 'text-2xl font-semibold tabular-nums text-[color:var(--app-foreground)]'}`}>
                {missing ? t('performance.notCalculated') : `${formatScore(value)}${suffix}`}
            </p>
            {hint && <div className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{hint}</div>}
        </div>
    );
}

/** Horizontal progress bar for an achievement % (0–100 filled; over-achievement noted in text). */
export function Bar({ value }: { value: string | number | null | undefined }) {
    const n = value === null || value === undefined || value === '' ? null : Number(value);
    const width = n === null ? 0 : Math.max(0, Math.min(100, n));
    const tone = n === null ? 'bg-gray-300 dark:bg-slate-700' : n >= 80 ? 'bg-emerald-500' : n >= 60 ? 'bg-amber-500' : 'bg-red-500';
    return (
        <div className="h-1.5 w-full rounded-full bg-[color:var(--app-surface-muted)]" role="presentation">
            <div className={`h-1.5 rounded-full ${tone}`} style={{ width: `${width}%` }} />
        </div>
    );
}

// ── Score trace ("How this score was calculated") ─────────────────────────

export type ItemTrace = {
    item_id: string;
    kpi_code: string;
    kpi_name_en: string;
    kpi_name_am?: string | null;
    direction: string;
    aggregation?: { method?: string; value?: string | null; formula?: string | null; count?: number } | null;
    data_source: string;
    actual_rows: number;
    target: string | null;
    actual: string | null;
    weight: string;
    achievement: string | null;
    achievement_status: string;
    achievement_formula?: string | null;
    raw_achievement?: string | null;
    cap?: string | null;
    capped?: boolean;
    weighted: string | null;
    amended?: boolean;
};

export type CompetencyTrace = { code: string | null; name_en: string | null; name_am: string | null; rating: number | null; scale_max: number | null; score: string | null; weight: string };

export type ScoreTrace = {
    formula?: Record<string, string>;
    items?: ItemTrace[];
    results_score?: string | null;
    competencies?: CompetencyTrace[];
    competency_score?: string | null;
    results_weight?: string;
    competency_weight?: string;
    results_contribution?: string | null;
    competency_contribution?: string | null;
    final_score?: string | null;
    rating?: { label_en?: string | null; label_am?: string | null } | null;
    complete?: boolean;
    calculated_at?: string;
    adjustments?: unknown;
};

/** Transparent breakdown of an employee score: every KPI row, the formulas and the weights. */
export function ScoreTraceView({ trace }: { trace: ScoreTrace | null | undefined }) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    if (!trace || !trace.items) return <Empty>{t('performance.notCalculated')}</Empty>;

    return (
        <div className="space-y-4">
            <Table framed head={<>
                <th className={thCls}>{t('performance.fields.kpi')}</th>
                <th className={thCls}>{t('performance.fields.target')}</th>
                <th className={thCls}>{t('performance.fields.actual')}</th>
                <th className={thCls}>{t('performance.fields.achievement')}</th>
                <th className={thCls}>{t('performance.fields.weight')}</th>
                <th className={thCls}>{t('performance.result.weighted')}</th>
            </>}>
                {trace.items.map((row) => (
                    <tr key={row.item_id}>
                        <td className={tdCls}>
                            <p className="font-medium">{row.kpi_code} — {(locale === 'am' && row.kpi_name_am) || row.kpi_name_en}</p>
                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{label('direction', row.direction)} · {label('source', row.data_source)}{row.aggregation?.method ? ` · ${label('aggregation', row.aggregation.method)}` : ''}</p>
                            {row.achievement_formula && <p className="mt-0.5 font-mono text-[11px] text-[color:var(--app-muted-foreground)]">{row.achievement_formula}</p>}
                        </td>
                        <td className={`${tdCls} tabular-nums`}>{formatScore(row.target)}</td>
                        <td className={`${tdCls} tabular-nums`}>{row.actual === null ? <span className="text-xs text-[color:var(--app-muted-foreground)]">{t('performance.notReported')}</span> : formatScore(row.actual)}</td>
                        <td className={`${tdCls} tabular-nums`}>
                            {row.achievement === null ? '—' : `${formatScore(row.achievement)}%`}
                            {row.capped && <span className="block text-[11px] text-[color:var(--app-muted-foreground)]">({formatScore(row.raw_achievement)}% → {formatScore(row.cap)}%)</span>}
                        </td>
                        <td className={`${tdCls} tabular-nums`}>{formatScore(row.weight)}%</td>
                        <td className={`${tdCls} tabular-nums`}>{formatScore(row.weighted)}</td>
                    </tr>
                ))}
            </Table>

            {!!trace.competencies?.length && (
                <Table framed head={<>
                    <th className={thCls}>{t('performance.fields.competency')}</th>
                    <th className={thCls}>{t('performance.fields.rating')}</th>
                    <th className={thCls}>{t('performance.fields.weight')}</th>
                    <th className={thCls}>{t('performance.fields.score')}</th>
                </>}>
                    {trace.competencies.map((c, i) => (
                        <tr key={`${c.code}-${i}`}>
                            <td className={tdCls}>{c.code} — {(locale === 'am' && c.name_am) || c.name_en}</td>
                            <td className={`${tdCls} tabular-nums`}>{c.rating ?? '—'}{c.scale_max ? ` / ${c.scale_max}` : ''}</td>
                            <td className={`${tdCls} tabular-nums`}>{formatScore(c.weight)}</td>
                            <td className={`${tdCls} tabular-nums`}>{formatScore(c.score)}</td>
                        </tr>
                    ))}
                </Table>
            )}

            <dl className="grid gap-2 text-sm sm:grid-cols-3">
                <div className="rounded-lg bg-[color:var(--app-surface-muted)] p-3">
                    <dt className="text-xs text-[color:var(--app-muted-foreground)]">{t('performance.result.results')} × {formatScore(trace.results_weight)}%</dt>
                    <dd className="tabular-nums">{formatScore(trace.results_score)} → {formatScore(trace.results_contribution)}</dd>
                </div>
                <div className="rounded-lg bg-[color:var(--app-surface-muted)] p-3">
                    <dt className="text-xs text-[color:var(--app-muted-foreground)]">{t('performance.result.competency')} × {formatScore(trace.competency_weight)}%</dt>
                    <dd className="tabular-nums">{formatScore(trace.competency_score)} → {formatScore(trace.competency_contribution)}</dd>
                </div>
                <div className="rounded-lg bg-[color:var(--app-surface-muted)] p-3">
                    <dt className="text-xs text-[color:var(--app-muted-foreground)]">{t('performance.result.final')}</dt>
                    <dd className="font-semibold tabular-nums">{formatScore(trace.final_score)} {trace.rating && <span className="font-normal">· {(locale === 'am' && trace.rating.label_am) || trace.rating.label_en}</span>}</dd>
                </div>
            </dl>

            {trace.formula && (
                <details className="text-xs text-[color:var(--app-muted-foreground)]">
                    <summary className="cursor-pointer font-medium">{t('performance.result.formula')}</summary>
                    <ul className="mt-1 space-y-0.5 font-mono">{Object.entries(trace.formula).map(([k, v]) => <li key={k}>{k}: {v}</li>)}</ul>
                </details>
            )}
        </div>
    );
}
