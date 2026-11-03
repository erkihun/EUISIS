import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button, Card, StatusBadge, cx } from '@euisis/ui';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { AlertTriangle, Building2, CalendarIcon, CheckCircle } from '@/Components/Icons';
import { ChevronRight } from '@/Components/NavIcons';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import CategoryIcon from './CategoryIcon';
import { formatKpi } from './Panels';
import { SECTION_ACCENT, tinted } from './sectionAccent';
import { PERIOD_PRESETS } from './SituationFilters';
import { useSituationLabels } from './useSituationLabels';
import type { SrBriefing, SrCategory, SrColumn, SrKpiItem, SrLabel, SrPanel, SrQuery, SrScope, SrFilters } from '@/types/situationRoom';

type Issue = { key: string; text: string; href: string; tone: 'warning' | 'info' };

export function Skeleton({ className }: { className: string }) {
    return <div aria-hidden="true" className={cx('animate-pulse rounded-card bg-(--app-surface-muted)', className)} />;
}

export type JumpTarget = { id: string; label: string };

/**
 * The page's sections one click away, the one in view marked. It stays at the
 * top while the briefing scrolls, so a reader is never more than a tap from
 * the trends or the areas to explore.
 */
export function JumpNav({ targets }: { targets: JumpTarget[] }) {
    const { sr } = useSituationLabels();
    const [active, setActive] = useState(targets[0]?.id ?? '');
    const ids = targets.map((target) => target.id).join('|');

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') return undefined;
        const sections = ids.split('|').map((id) => document.getElementById(id)).filter((el): el is HTMLElement => el !== null);
        if (sections.length === 0) return undefined;
        const visible = new Map<string, number>();
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) visible.set(entry.target.id, entry.boundingClientRect.top);
                else visible.delete(entry.target.id);
            });
            const first = [...visible.entries()].sort((a, b) => a[1] - b[1])[0];
            if (first) setActive(first[0]);
        }, { rootMargin: '-120px 0px -55% 0px', threshold: 0 });
        sections.forEach((section) => observer.observe(section));
        return () => observer.disconnect();
    }, [ids]);

    return (
        <nav aria-label={sr('ui.jump_to')} className="sticky top-0 z-20 -mx-1 overflow-x-auto bg-(--app-background)/95 px-1 py-2 backdrop-blur-sm">
            <ul className="flex min-w-max items-center gap-1.5 text-xs">
                <li className="mr-1 text-[11px] font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{sr('ui.jump_to')}</li>
                {targets.map((target) => (
                    <li key={target.id}>
                        <a
                            href={`#${target.id}`}
                            aria-current={active === target.id ? 'location' : undefined}
                            className={cx(
                                'block rounded-full border px-3 py-1.5 font-semibold transition-colors focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary',
                                active === target.id ? 'border-primary-light bg-primary-light text-primary' : 'border-(--app-border) bg-(--app-surface) text-(--app-muted-foreground) hover:text-(--app-foreground)',
                            )}
                        >
                            {target.label}
                        </a>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/** A section's title and the one-line hint on how to read it; the anchor the jump links land on. */
export function SectionHeading({ id, title, hint, children }: { id: string; title: string; hint?: string; children?: ReactNode }) {
    return (
        <div id={id} className="flex scroll-mt-16 flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pt-1">
            <h2 className="text-base font-bold tracking-tight text-(--app-foreground)">{title}</h2>
            {hint && <p className="text-xs text-(--app-muted-foreground)">{hint}</p>}
            {children}
        </div>
    );
}

/** The period, one click away; a custom range asks for its dates. */
export function PeriodSwitch({ url, query, filters }: { url: string; query: SrQuery; filters: SrFilters }) {
    const { sr } = useSituationLabels();
    const active = query.period ?? filters.period.preset;
    const [custom, setCustom] = useState(active === 'custom');
    const [from, setFrom] = useState(query.from ?? filters.period.from);
    const [to, setTo] = useState(query.to ?? filters.period.to);
    const go = (next: SrQuery) => router.get(url, next, { preserveScroll: true });
    const base = { ...query };
    delete base.from;
    delete base.to;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div role="group" aria-label={sr('ui.period')} className="flex max-w-full overflow-x-auto rounded-control border border-(--app-border) bg-(--app-surface-muted) p-0.5">
                {PERIOD_PRESETS.map((preset) => {
                    const selected = preset === 'custom' ? custom : !custom && active === preset;
                    return (
                        <button
                            key={preset}
                            type="button"
                            aria-pressed={selected}
                            onClick={() => {
                                setCustom(preset === 'custom');
                                if (preset !== 'custom') go({ ...base, period: preset });
                            }}
                            className={cx(
                                'shrink-0 rounded-sm px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary',
                                selected ? 'bg-(--app-surface) text-(--app-foreground) shadow-xs' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)',
                            )}
                        >
                            {sr(`ui.periods.${preset}`)}
                        </button>
                    );
                })}
            </div>
            {custom && (
                <div className="flex flex-wrap items-center gap-2">
                    <div className="w-40"><LocalizedDatePicker id="sr-from" aria-label={sr('ui.from')} value={from} onChange={setFrom} max={to} /></div>
                    <div className="w-40"><LocalizedDatePicker id="sr-to" aria-label={sr('ui.to')} value={to} onChange={setTo} min={from} /></div>
                    <Button size="sm" onClick={() => go({ ...base, period: 'custom', from, to })}>{sr('ui.apply')}</Button>
                </div>
            )}
        </div>
    );
}

/** The issues the figures point at, each linked to where it can be dealt with. Nothing here is a judgement of a person. */
export function useIssues({ items, briefing, query, settingsHref, categories }: { items: SrKpiItem[]; briefing?: SrBriefing; query: SrQuery; settingsHref: string | null; categories: SrCategory[] }): Issue[] {
    const { sr } = useSituationLabels();
    const href = (key: string) => categories.find((c) => c.key === key)?.href ?? null;
    const link = (key: string) => {
        const target = href(key);
        return target ? `${target}${Object.keys(query).length > 0 ? `?${new URLSearchParams(query).toString()}` : ''}` : route('situation-room.index');
    };
    const issues: Issue[] = [];
    const figure = (key: string) => items.find((item) => item.key === key)?.value ?? null;

    const alerts = figure('attention_items');
    if (alerts !== null && alerts > 0) issues.push({ key: 'alerts', tone: 'warning', text: sr('briefing.issue_alerts', { count: alerts }), href: link('critical_attention') });

    if (briefing) {
        const { placement, attention, quality } = briefing;
        if (placement && placement.total > placement.placed) {
            issues.push({ key: 'unplaced', tone: 'warning', text: sr('briefing.issue_unplaced', { count: placement.total - placement.placed, of: placement.total }), href: link('workforce') });
        }
        const review = quality.filter((check) => check.count > 0).length;
        if (review > 0) issues.push({ key: 'quality', tone: 'warning', text: sr('briefing.issue_quality', { count: review, of: quality.length }), href: link('data_quality') });
        const unset = attention.filter((rule) => rule.state === 'not_configured').length;
        if (unset > 0) issues.push({ key: 'thresholds', tone: 'warning', text: sr('briefing.issue_thresholds', { count: unset }), href: settingsHref ?? link('critical_attention') });
    }

    const headcount = figure('headcount');
    if (figure('reporting_rate') === 0 && headcount !== null && headcount > 0) {
        issues.push({ key: 'reporting', tone: 'warning', text: sr('briefing.issue_reporting'), href: link('daily_operations') });
    }
    const actions = figure('actions_open');
    if (actions !== null && actions > 0) issues.push({ key: 'actions', tone: 'info', text: sr('briefing.issue_actions', { count: actions }), href: route('situation-room.actions.index') });

    return issues;
}

/** The first thing on the page: where the view is looking, what the figures say, and what needs someone's eye. */
export function BriefingHero({ scope, filters, items, briefing, issues, loading, freshness }: { scope: SrScope; filters: SrFilters; items: SrKpiItem[]; briefing?: SrBriefing; issues: Issue[]; loading: boolean; freshness?: ReactNode }) {
    const { sr, label, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const scopeName = scope.breadcrumb.length > 0 ? label(scope.breadcrumb[scope.breadcrumb.length - 1].label) : sr(scope.unrestricted ? 'ui.scope_all' : 'ui.scope_mine');
    const value = (key: string) => items.find((item) => item.key === key) ?? null;
    const organizations = briefing?.structure.find((item) => item.key === 'organizations') ?? null;
    const headcount = value('headcount');
    const complaints = value('complaints_received');
    const tips = value('tip_offs_received');
    const rating = value('average_rating');
    const warnings = issues.filter((issue) => issue.tone === 'warning').length;
    const stats = [organizations, headcount, complaints, value('actions_open')].filter((item): item is SrKpiItem => item !== null);
    const lines = [
        organizations && headcount && sr('briefing.line_structure', { organizations: Number(organizations.value).toLocaleString(), headcount: Number(headcount.value).toLocaleString() }),
        complaints && tips && sr('briefing.line_voice', { complaints: Number(complaints.value).toLocaleString(), tips: Number(tips.value).toLocaleString() }),
        rating && rating.value !== null && sr('briefing.line_rating', { rating: rating.value }),
    ].filter(Boolean);
    const tone = loading ? null : warnings > 0 ? 'attention' : 'clear';
    const chip = 'inline-flex max-w-full items-center gap-1.5 rounded-full bg-white/12 px-2.5 py-1 text-xs font-medium text-white/90';

    return (
        <section
            id="sr-status"
            aria-labelledby="sr-status-heading"
            className={cx('grid scroll-mt-16 overflow-hidden rounded-panel border-t-4 text-white lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]', tone === 'attention' ? 'border-amber-400' : tone === 'clear' ? 'border-emerald-400' : 'border-white/20')}
            style={{ background: 'linear-gradient(135deg, var(--color-primary-900), var(--color-primary-700) 70%, var(--color-primary-600))' }}
        >
            <div className="flex flex-col gap-5 p-5 sm:p-7">
                <div className="flex flex-wrap gap-2">
                    <span className={chip}><Building2 className="h-3.5 w-3.5 shrink-0 text-white/70" aria-hidden="true" /><span className="truncate">{scopeName}</span></span>
                    <span className={chip}>
                        <CalendarIcon className="h-3.5 w-3.5 shrink-0 text-white/70" aria-hidden="true" />
                        <span className="truncate">{sr(`ui.periods.${filters.period.preset}`)} · {formatDateDisplay(filters.period.from, system, locale)} – {formatDateDisplay(filters.period.to, system, locale)}</span>
                    </span>
                </div>
                <div className="flex items-start gap-4">
                    <span aria-hidden="true" className={cx('flex h-12 w-12 shrink-0 items-center justify-center rounded-card', tone === 'attention' ? 'bg-amber-400/25 text-amber-200' : tone === 'clear' ? 'bg-emerald-400/25 text-emerald-200' : 'bg-white/10 text-white/60')}>
                        {tone === 'clear' ? <CheckCircle className="h-7 w-7" /> : <AlertTriangle className="h-7 w-7" />}
                    </span>
                    <div className="min-w-0 flex-1">
                        {loading
                            ? <div aria-hidden="true" className="mt-1 h-8 w-2/3 animate-pulse rounded-card bg-white/10" />
                            : <h2 id="sr-status-heading" className="text-2xl font-bold tracking-tight sm:text-3xl">{warnings > 0 ? sr('briefing.status_attention', { count: warnings }) : sr('briefing.status_clear')}</h2>}
                        {lines.length > 0 && <p className="mt-2 max-w-2xl text-sm leading-relaxed text-white/80">{lines.join(' ')}</p>}
                    </div>
                </div>
                <dl className="mt-auto grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {stats.map((item) => (
                        <div key={item.key} className="min-w-0 rounded-card border border-white/15 bg-white/10 px-3.5 py-3">
                            <dd className="text-3xl font-bold leading-none tabular-nums">{formatKpi(item, sr)}</dd>
                            <dt className="mt-1.5 text-xs leading-snug text-white/70">{sr(item.label)}</dt>
                        </div>
                    ))}
                </dl>
            </div>
            <div className="flex flex-col border-t border-white/10 bg-black/15 p-5 sm:p-6 lg:border-l lg:border-t-0">
                <h3 className="text-sm font-semibold">{sr('briefing.attention_title')}</h3>
                {loading && <div className="mt-3 space-y-2">{[0, 1, 2].map((i) => <div key={i} aria-hidden="true" className="h-9 animate-pulse rounded-control bg-white/10" />)}</div>}
                {!loading && issues.length === 0 && <p className="mt-3 text-sm text-white/75">{sr('briefing.nothing_outstanding')}</p>}
                {!loading && issues.length > 0 && (
                    <ol className="mt-1 divide-y divide-white/10">
                        {issues.map((issue, i) => (
                            <li key={issue.key}>
                                <Link href={issue.href} className="group flex items-start gap-3 py-2.5 text-sm focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-white">
                                    <span aria-hidden="true" className={cx('mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold', issue.tone === 'warning' ? 'bg-amber-400/30 text-amber-100' : 'bg-sky-300/25 text-sky-100')}>{i + 1}</span>
                                    <span className="min-w-0 flex-1 leading-snug text-white/90 group-hover:text-white">{issue.text}</span>
                                    <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-white/50 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                </Link>
                            </li>
                        ))}
                    </ol>
                )}
                {freshness && <div className="mt-auto border-t border-white/10 pt-3">{freshness}</div>}
            </div>
        </section>
    );
}

function delta(item: SrKpiItem, comparison: SrBriefing['comparison'] | undefined) {
    const row = comparison?.find((r) => typeof r.metric === 'object' && r.metric !== null && 'key' in r.metric && r.metric.key === `situationRoom.kpi.${item.key}`);

    return row ? { change: row.current - row.previous, previous: row.previous } : null;
}

const GROUPS: { section: SrCategory['section']; icon: string; keys: string[] }[] = [
    { section: 'people', icon: 'Users', keys: ['headcount', 'vacancy_rate'] },
    { section: 'public_voice', icon: 'MessageSquareIcon', keys: ['complaints_received', 'tip_offs_received', 'average_rating'] },
    { section: 'operations', icon: 'ClipboardListIcon', keys: ['reporting_rate', 'in_field_now'] },
    { section: 'command', icon: 'AlertTriangle', keys: ['attention_items', 'actions_open'] },
];

/** The headline figures, grouped by the area they belong to rather than in one undifferentiated row. */
export function KpiGroups({ items, comparison }: { items: SrKpiItem[]; comparison?: SrBriefing['comparison'] }) {
    const { sr } = useSituationLabels();
    const groups = GROUPS.map((group) => ({ ...group, items: group.keys.flatMap((key) => items.filter((item) => item.key === key)) })).filter((group) => group.items.length > 0);

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {groups.map((group) => {
                const accent = SECTION_ACCENT[group.section];
                return (
                    <Card key={group.section} className="flex flex-col border-t-[3px] p-4" style={{ borderTopColor: accent }}>
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                            <span className="flex h-7 w-7 items-center justify-center rounded-control" style={tinted(accent)}><CategoryIcon name={group.icon} className="h-4 w-4" /></span>
                            {sr(`sections.${group.section}`)}
                        </h3>
                        <ul className="mt-3 flex-1 divide-y divide-(--app-border)">
                            {group.items.map((item) => <MetricRow key={item.key} item={item} change={delta(item, comparison)} />)}
                        </ul>
                    </Card>
                );
            })}
        </div>
    );
}

function MetricRow({ item, change }: { item: SrKpiItem; change: { change: number; previous: number } | null }) {
    const { sr } = useSituationLabels();
    const attention = item.state === 'attention';
    const body = (
        <>
            <span className="min-w-0">
                <span className="block text-xs text-(--app-muted-foreground)">{sr(item.label)}</span>
                {change && (
                    <span className="mt-1 inline-block rounded-full bg-(--app-surface-muted) px-1.5 py-px text-[11px] font-medium text-(--app-muted-foreground)" title={sr('ui.previous', { value: change.previous.toLocaleString() })}>
                        {change.change === 0
                            ? sr('briefing.no_change')
                            : sr('briefing.change_vs_previous', { change: `${change.change > 0 ? '▲ +' : '▼ '}${change.change.toLocaleString()}` })}
                    </span>
                )}
            </span>
            {item.state === 'not_configured'
                ? <span className="shrink-0 text-xs font-medium text-(--app-muted-foreground)">{sr('ui.not_configured')}</span>
                : (
                    <span className="flex shrink-0 items-center gap-2">
                        {attention && <StatusBadge tone="warning">{sr('ui.attention')}</StatusBadge>}
                        <span className={cx('text-2xl font-bold tabular-nums', attention ? 'text-amber-600 dark:text-amber-400' : 'text-(--app-foreground)')}>{formatKpi(item, sr)}</span>
                    </span>
                )}
        </>
    );
    const row = 'flex items-center justify-between gap-3 py-2.5';

    return (
        <li title={item.definition ? sr(item.definition) : undefined}>
            {item.href ? <Link href={item.href} className={cx(row, 'rounded-sm transition-colors hover:text-primary focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary')}>{body}</Link> : <div className={row}>{body}</div>}
        </li>
    );
}

/** A titled card with one way to look deeper; every block below the headlines is one of these. */
export function Block({ title, description, href, linkLabel, accent, className, children }: { title: string; description?: string; href?: string | null; linkLabel?: string; accent?: string; className?: string; children: ReactNode }) {
    const { sr } = useSituationLabels();

    return (
        <Card className={cx('flex h-full min-w-0 flex-col p-4', className)}>
            <header className="mb-3 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                        {accent && <span aria-hidden="true" className="h-3.5 w-1 shrink-0 rounded-full" style={{ background: accent }} />}
                        {title}
                    </h3>
                    {description && <p className={cx('mt-0.5 text-xs text-(--app-muted-foreground)', accent && 'pl-3')}>{description}</p>}
                </div>
                {href && (
                    <Link href={href} className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-primary-light px-2.5 py-1 text-xs font-semibold text-primary transition-colors hover:bg-primary-200 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-primary-600">
                        {linkLabel ?? sr('briefing.view_all')}<span aria-hidden="true">›</span>
                    </Link>
                )}
            </header>
            <div className="min-h-0 flex-1">{children}</div>
        </Card>
    );
}

/** This period against the one before: two bars on one scale per row, so the change can be seen, not worked out. */
export function ComparisonBlock({ rows, href }: { rows: SrBriefing['comparison']; href: string | null }) {
    const { sr, label } = useSituationLabels();

    return (
        <Block title={sr('panels.trends_comparison.title')} description={sr('briefing.comparison_hint')} href={href} accent={SECTION_ACCENT.command}>
            <ul className="space-y-3">
                {rows.map((row, i) => {
                    const max = Math.max(row.current, row.previous, 1);
                    return (
                        <li key={i}>
                            <div className="flex items-baseline justify-between gap-2 text-xs">
                                <span className="min-w-0 truncate text-(--app-foreground)">{label(row.metric)}</span>
                                <span className="shrink-0 tabular-nums text-(--app-muted-foreground)"><strong className="font-semibold text-(--app-foreground)">{row.current.toLocaleString()}</strong> / {row.previous.toLocaleString()}</span>
                            </div>
                            <div aria-hidden="true" className="mt-1 space-y-0.5">
                                <div className="h-1.5 rounded-full bg-primary" style={{ width: `${Math.max(1, (row.current / max) * 100)}%` }} />
                                <div className="h-1.5 rounded-full bg-(--app-border-strong)" style={{ width: `${Math.max(1, (row.previous / max) * 100)}%` }} />
                            </div>
                        </li>
                    );
                })}
            </ul>
            <p className="mt-3 flex flex-wrap gap-3 text-[11px] text-(--app-muted-foreground)">
                <span className="flex items-center gap-1"><span aria-hidden="true" className="h-2 w-2 rounded-full bg-primary" />{sr('briefing.this_period')}</span>
                <span className="flex items-center gap-1"><span aria-hidden="true" className="h-2 w-2 rounded-full bg-(--app-border-strong)" />{sr('briefing.previous_period')}</span>
            </p>
        </Block>
    );
}

/** The institutions with something recorded, in name order, and how many have nothing at all. */
export function InstitutionsBlock({ data, href }: { data: NonNullable<SrBriefing['institutions']>; href: string | null }) {
    const { sr, label } = useSituationLabels();
    const quiet = data.total - data.active;

    return (
        <Block title={sr('briefing.institutions_title')} description={sr('briefing.institutions_hint')} href={href} accent={SECTION_ACCENT.people}>
            {data.rows.length === 0
                ? <p className="rounded-card bg-(--app-surface-muted) p-3 text-xs text-(--app-muted-foreground)">{sr('briefing.institutions_empty')}</p>
                : (
                    <ul className="divide-y divide-(--app-border)">
                        {data.rows.map((row, i) => (
                            <li key={i} className="py-2">
                                <p className="text-sm text-(--app-foreground)">{label(row.organization as SrLabel)}</p>
                                <p className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-(--app-muted-foreground)">
                                    {data.columns.filter((column: SrColumn) => Number(row[column.key] ?? 0) > 0).map((column: SrColumn) => (
                                        <span key={column.key}>{label(column.label)} <strong className="font-semibold tabular-nums text-(--app-foreground)">{Number(row[column.key]).toLocaleString()}</strong></span>
                                    ))}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            {quiet > 0 && <p className="mt-3 text-xs text-(--app-muted-foreground)">{sr('briefing.institutions_quiet', { count: quiet, total: data.total })}</p>}
        </Block>
    );
}

const PRIORITY_DOT: Record<string, string> = { critical: 'bg-red-500', high: 'bg-amber-500', medium: 'bg-sky-500', low: 'bg-slate-400' };

/** Open management actions as a work list; a table has no room in a third of the page. */
export function ActionsBlock({ panel, href }: { panel: SrPanel | undefined; href: string }) {
    const { sr, label, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const rows = panel?.type === 'table' ? panel.data.rows : [];

    return (
        <Block title={sr('panels.overview_actions.title')} href={href} linkLabel={sr('ui.actions')} accent={SECTION_ACCENT.command}>
            {rows.length === 0
                ? (
                    <div className="flex h-full min-h-24 flex-col items-start justify-center gap-1 rounded-card bg-(--app-surface-muted) p-3">
                        <p className="text-sm font-medium text-(--app-foreground)">{sr('briefing.actions_empty')}</p>
                        <p className="text-xs text-(--app-muted-foreground)">{sr('briefing.actions_empty_hint')}</p>
                    </div>
                )
                : (
                    <ul className="divide-y divide-(--app-border)">
                        {rows.map((row, i) => {
                            const priority = row.priority as { key?: string } | null;
                            const level = priority?.key?.split('.').pop() ?? '';
                            return (
                                <li key={i} className="flex items-start gap-2.5 py-2">
                                    <span title={label(row.priority as SrLabel)} aria-hidden="true" className={cx('mt-1.5 h-2 w-2 shrink-0 rounded-full', PRIORITY_DOT[level] ?? 'bg-slate-400')} />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm text-(--app-foreground)">{String(row.title ?? '')}</p>
                                        <p className="truncate text-xs text-(--app-muted-foreground)">
                                            {label(row.organization as SrLabel)}
                                            {row.due_date ? ` · ${formatDateDisplay(String(row.due_date), system, locale)}` : ''}
                                        </p>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
        </Block>
    );
}

/** What exists in scope, and how much of the workforce the published structure can account for. */
export function StructureBlock({ items, placement, href }: { items: SrKpiItem[]; placement: SrBriefing['placement']; href: string | null }) {
    const { sr } = useSituationLabels();
    const share = placement && placement.total > 0 ? Math.round((placement.placed / placement.total) * 100) : null;

    return (
        <Block title={sr('briefing.structure_title')} description={sr('briefing.structure_hint')} href={href} accent={SECTION_ACCENT.people}>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                {items.map((item) => (
                    <div key={item.key} className="rounded-card bg-(--app-surface-muted) px-3 py-2.5">
                        <dd className="text-xl font-bold tabular-nums text-(--app-foreground)">{formatKpi(item, sr)}</dd>
                        <dt className="mt-0.5 text-[11px] leading-snug text-(--app-muted-foreground)">{sr(item.label)}</dt>
                    </div>
                ))}
            </dl>
            {placement && share !== null && (
                <div className="mt-4">
                    <div className="flex items-baseline justify-between gap-2 text-xs">
                        <span className="text-(--app-foreground)">{sr('briefing.placement')}</span>
                        <span className="tabular-nums text-(--app-muted-foreground)">{sr('briefing.placed_of', { placed: placement.placed.toLocaleString(), total: placement.total.toLocaleString() })}</span>
                    </div>
                    <div role="progressbar" aria-valuenow={share} aria-valuemin={0} aria-valuemax={100} aria-label={sr('briefing.placement')} className="mt-1.5 h-2 overflow-hidden rounded-full bg-(--app-surface-muted)">
                        <div className={cx('h-full rounded-full', share === 100 ? 'bg-emerald-500' : 'bg-amber-500')} style={{ width: `${share}%` }} />
                    </div>
                </div>
            )}
        </Block>
    );
}

/** The data quality checks with something to fix, worst first: how far these figures can be trusted. */
export function QualityBlock({ checks, href }: { checks: SrBriefing['quality']; href: string | null }) {
    const { sr, label } = useSituationLabels();
    const failing = checks.filter((check) => check.count > 0).sort((a, b) => (b.share ?? 0) - (a.share ?? 0));

    return (
        <Block title={sr('briefing.quality_title')} description={sr('briefing.quality_passed', { passed: checks.length - failing.length, total: checks.length })} href={href} accent={SECTION_ACCENT.governance}>
            {failing.length === 0
                ? <p className="rounded-card bg-(--app-surface-muted) p-3 text-xs text-(--app-muted-foreground)">{sr('briefing.quality_clean')}</p>
                : (
                    <ul className="space-y-2.5">
                        {failing.slice(0, 5).map((check, i) => (
                            <li key={i}>
                                <div className="flex items-baseline justify-between gap-2 text-xs">
                                    <span className="min-w-0 truncate text-(--app-foreground)" title={label(check.fix_in)}>{label(check.check)}</span>
                                    <span className="shrink-0 tabular-nums text-(--app-muted-foreground)">{check.count.toLocaleString()} / {check.of.toLocaleString()}</span>
                                </div>
                                <div aria-hidden="true" className="mt-1 h-1.5 overflow-hidden rounded-full bg-(--app-surface-muted)">
                                    <div className="h-full rounded-full bg-amber-500" style={{ width: `${Math.min(100, check.share ?? 0)}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
        </Block>
    );
}

export type ExploreItem = { key: string; section: SrCategory['section']; icon: string; href: string; title: string; description: string; data?: SrQuery };

/** Every area the viewer may open, with its headline figure so the next click is an informed one. */
export function ExploreCard({ item, headlines }: { item: ExploreItem; headlines: SrBriefing['headlines'][string] | undefined }) {
    const { sr } = useSituationLabels();
    const accent = SECTION_ACCENT[item.section];
    const lead = headlines?.[0];

    return (
        <Link href={item.href} data={item.data} className="group block h-full focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <Card className="flex h-full flex-col gap-3 p-4 transition-all group-hover:-translate-y-0.5 group-hover:border-(--sr-accent) group-hover:shadow-md motion-reduce:transform-none" style={{ '--sr-accent': accent } as CSSProperties}>
                <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control" style={tinted(accent)}><CategoryIcon name={item.icon} className="h-5 w-5" /></span>
                    <span className="min-w-0 flex-1 text-sm font-semibold leading-snug text-(--app-foreground)">{item.title}</span>
                    <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-(--app-muted-foreground) transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </div>
                {lead
                    ? (
                        <p className="mt-auto flex items-baseline gap-2">
                            <span className={cx('text-2xl font-bold tabular-nums', lead.value === 0 ? 'text-(--app-muted-foreground)' : 'text-(--app-foreground)')}>{formatKpi({ value: lead.value, unit: lead.unit ?? undefined }, sr)}</span>
                            <span className="min-w-0 truncate text-xs text-(--app-muted-foreground)">{sr(lead.label)}</span>
                        </p>
                    )
                    : <p className="mt-auto line-clamp-2 text-xs text-(--app-muted-foreground)">{item.description}</p>}
            </Card>
        </Link>
    );
}
