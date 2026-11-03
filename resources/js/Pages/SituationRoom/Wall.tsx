import { useCallback, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { buttonClassName, cx } from '@euisis/ui';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { AlertTriangle, Building2, CalendarIcon, CheckCircle } from '@/Components/Icons';
import CategoryIcon from '@/Components/SituationRoom/CategoryIcon';
import PanelSkeleton from '@/Components/SituationRoom/PanelSkeleton';
import { WallPanels, formatKpi } from '@/Components/SituationRoom/Panels';
import { SECTION_ACCENT } from '@/Components/SituationRoom/sectionAccent';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { formatDateDisplay, formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import type { SrCategory, SrFilters, SrKpiItem, SrLabels, SrPanel, SrResult, SrScope } from '@/types/situationRoom';

type Screen = { category: string; section: SrCategory['section']; icon: string; panels: SrPanel[] };

type Props = {
    labels: SrLabels;
    scope: SrScope;
    filters: SrFilters;
    refresh_seconds: number;
    rotation_seconds: number;
    privacy: { small_cell: number };
    result?: SrResult<{ screens: Screen[]; has_errors: boolean }>;
};

const CONTROLS_IDLE_MS = 4000;

const kpisOf = (screen: Screen): SrKpiItem[] => screen.panels.flatMap((panel) => (panel.type === 'kpis' ? panel.data.items : []));

/**
 * Wall Mode: a full-screen display for an operations room. No sidebar, no
 * names — the server already leaves out every table and every panel that
 * names a person. Screens rotate on their own; the figures reload on the
 * configured interval without a page reload. Space pauses, the arrow keys
 * step, and nothing here stores anything in the browser.
 */
export default function SituationRoomWall(props: Props) {
    return (
        <LocalizedUiProvider>
            <WallBody {...props} />
        </LocalizedUiProvider>
    );
}

function WallBody({ scope, filters, refresh_seconds, rotation_seconds, privacy, result }: Props) {
    const { sr, label, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const screens = result?.payload.screens ?? [];
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const position = screens.length > 0 ? index % screens.length : 0;
    const current = screens[position] ?? null;
    const step = useCallback((by: number) => setIndex((i) => (screens.length === 0 ? 0 : (i + by + screens.length) % screens.length)), [screens.length]);
    const { fullscreen, idle } = useFullscreenIdle();

    // Restarted by every step, so a screen always gets its full time and the progress bar stays true.
    useEffect(() => {
        if (paused || screens.length < 2) return undefined;
        const timer = window.setTimeout(() => step(1), rotation_seconds * 1000);
        return () => window.clearTimeout(timer);
    }, [paused, position, rotation_seconds, screens.length, step]);

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['result'] }), refresh_seconds * 1000);
        return () => window.clearInterval(timer);
    }, [refresh_seconds]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.target instanceof HTMLElement && ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'A'].includes(event.target.tagName)) return;
            if (event.key === ' ') { event.preventDefault(); setPaused((p) => !p); }
            if (event.key === 'ArrowRight') step(1);
            if (event.key === 'ArrowLeft') step(-1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [step]);

    const scopeName = scope.breadcrumb.length > 0 ? label(scope.breadcrumb[scope.breadcrumb.length - 1].label) : sr(scope.unrestricted ? 'ui.scope_all' : 'ui.scope_mine');
    const accent = current ? SECTION_ACCENT[current.section] ?? SECTION_ACCENT.command : SECTION_ACCENT.command;
    const kpis = current ? kpisOf(current) : [];
    const charts = current ? current.panels.filter((panel) => panel.type !== 'kpis') : [];
    const screenLabel = sr('wall.screen', { current: position + 1, total: screens.length });

    return (
        <div
            className={cx('relative isolate flex min-h-dvh flex-col overflow-hidden bg-(--app-background) text-(--app-foreground) lg:h-dvh', idle && 'cursor-none')}
            style={{ '--wall-accent': accent } as CSSProperties}
        >
            <Head title={sr('wall.title')} />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 -z-10"
                style={{ background: 'radial-gradient(70rem 32rem at 12% -12%, color-mix(in srgb, var(--wall-accent) 22%, transparent), transparent 70%), radial-gradient(50rem 26rem at 100% 110%, color-mix(in srgb, var(--wall-accent) 10%, transparent), transparent 70%)' }}
            />

            <header className="flex flex-wrap items-center gap-x-5 gap-y-3 px-4 pt-4 sm:px-6">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-card text-(--wall-accent)" style={{ background: 'color-mix(in srgb, var(--wall-accent) 16%, transparent)' }}>
                    <CategoryIcon name={current?.icon ?? 'LayoutDashboard'} className="h-7 w-7" />
                </span>
                <div className="min-w-0 flex-1 basis-56">
                    <h1 className="truncate text-2xl font-bold tracking-tight sm:text-3xl">{current ? sr(`categories.${current.category}.title`) : sr('title')}</h1>
                    <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-(--app-muted-foreground)">
                        <span className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-(--app-surface-muted) px-2.5 py-1"><Building2 className="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span className="truncate">{scopeName}</span></span>
                        <span className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-(--app-surface-muted) px-2.5 py-1">
                            <CalendarIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span className="truncate">{sr(`ui.periods.${filters.period.preset}`)} · {formatDateDisplay(filters.period.from, system, locale)} – {formatDateDisplay(filters.period.to, system, locale)}</span>
                        </span>
                    </div>
                </div>
                {result && <WallStatus screens={screens} />}
                <Clock />
                <div className={cx('flex flex-wrap items-center gap-1.5 transition-opacity duration-300 focus-within:opacity-100', idle && 'opacity-0')}>
                    {screens.length > 1 && (
                        <div className="flex items-center gap-1.5" role="group" aria-label={screenLabel}>
                            <WallButton label={sr('wall.previous')} onClick={() => step(-1)}><Glyph d="M15 6l-6 6 6 6" /></WallButton>
                            <WallButton label={sr(paused ? 'wall.resume' : 'wall.pause')} onClick={() => setPaused((p) => !p)} pressed={paused}>
                                {paused ? <Glyph d="M8 5v14l11-7z" /> : <Glyph d="M9 5v14M15 5v14" />}
                            </WallButton>
                            <WallButton label={sr('wall.next')} onClick={() => step(1)}><Glyph d="M9 6l6 6-6 6" /></WallButton>
                        </div>
                    )}
                    <WallButton label={sr(fullscreen ? 'wall.exit_fullscreen' : 'wall.fullscreen')} onClick={toggleFullscreen} pressed={fullscreen}>
                        <Glyph d={fullscreen ? 'M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5' : 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5'} />
                    </WallButton>
                    <LanguageSwitcher variant="toolbar" />
                    <ThemeToggle variant="toolbar" />
                    <Link href={route('situation-room.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{sr('wall.exit')}</Link>
                </div>
            </header>

            <main id="main-content" className="flex min-h-0 flex-1 flex-col gap-4 px-4 py-4 sm:px-6 lg:overflow-y-auto">
                <Deferred data="result" fallback={<PanelSkeleton label={sr('ui.loading')} />}>
                    {current
                        ? (
                            <div key={current.category} className="sr-wall-rise flex min-h-0 flex-1 flex-col gap-4">
                                {kpis.length > 0 && (
                                    <div className="flex shrink-0 flex-wrap gap-3">
                                        {kpis.map((item, i) => <WallKpi key={item.key} item={item} hero={i === 0} />)}
                                    </div>
                                )}
                                {charts.length > 0 && (
                                    <div className="lg:min-h-80 lg:flex-1">
                                        <WallPanels panels={charts} options={{ smallCell: privacy.small_cell, wall: true, accent }} />
                                    </div>
                                )}
                            </div>
                        )
                        : <p className="text-lg text-(--app-muted-foreground)">{sr('wall.nothing')}</p>}
                </Deferred>
            </main>

            {screens.length > 0 && (
                <nav aria-label={screenLabel} className="flex shrink-0 gap-1.5 overflow-x-auto px-4 sm:px-6">
                    {screens.map((screen, i) => (
                        <button
                            key={screen.category}
                            type="button"
                            onClick={() => setIndex(i)}
                            aria-current={i === position ? 'true' : undefined}
                            className={cx(
                                'relative flex min-w-36 flex-1 items-center gap-2 overflow-hidden rounded-control border px-3 py-2 text-left text-xs transition-colors focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary',
                                i === position ? 'border-(--wall-accent) bg-(--app-surface) font-semibold text-(--app-foreground)' : 'border-(--app-border) text-(--app-muted-foreground) hover:text-(--app-foreground)',
                            )}
                        >
                            <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: SECTION_ACCENT[screen.section] ?? SECTION_ACCENT.command }} />
                            <CategoryIcon name={screen.icon ?? 'LayoutDashboard'} className="h-4 w-4 shrink-0" />
                            <span className="truncate">{sr(`categories.${screen.category}.title`)}</span>
                            {i === position && screens.length > 1 && (
                                <span
                                    key={`${position}:${paused}`}
                                    aria-hidden="true"
                                    className={cx('absolute inset-x-0 bottom-0 h-0.5 origin-left bg-(--wall-accent)', !paused && 'sr-wall-progress')}
                                    style={{ animationDuration: `${rotation_seconds}s` }}
                                />
                            )}
                        </button>
                    ))}
                </nav>
            )}

            {result && (
                <footer className="flex shrink-0 flex-wrap items-center justify-between gap-x-6 gap-y-1 px-4 py-3 text-xs text-(--app-muted-foreground) sm:px-6">
                    <AttentionRail screens={screens} />
                    <span>
                        {sr('ui.computed_at', { time: formatDateTimeDisplay(result.generated_at, system, locale) })} · {sr('wall.refreshes', { minutes: Math.max(1, Math.round(refresh_seconds / 60)) })} · {sr('wall.aggregates_only')}
                    </span>
                </footer>
            )}
        </div>
    );
}

function toggleFullscreen() {
    if (document.fullscreenElement) void document.exitFullscreen?.();
    else void document.documentElement.requestFullscreen?.();
}

/** In full screen the controls and the pointer step aside until someone moves the mouse or presses a key. */
function useFullscreenIdle() {
    const [fullscreen, setFullscreen] = useState(false);
    const [idle, setIdle] = useState(false);

    useEffect(() => {
        const onChange = () => setFullscreen(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    useEffect(() => {
        if (!fullscreen) {
            setIdle(false);
            return undefined;
        }
        let timer = window.setTimeout(() => setIdle(true), CONTROLS_IDLE_MS);
        const wake = () => {
            setIdle(false);
            window.clearTimeout(timer);
            timer = window.setTimeout(() => setIdle(true), CONTROLS_IDLE_MS);
        };
        const events = ['mousemove', 'keydown', 'touchstart'] as const;
        events.forEach((name) => window.addEventListener(name, wake));
        return () => {
            window.clearTimeout(timer);
            events.forEach((name) => window.removeEventListener(name, wake));
        };
    }, [fullscreen]);

    return { fullscreen, idle };
}

function Clock() {
    const { locale } = useSituationLabels();
    const system = useCalendarSystem();
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 15000);
        return () => window.clearInterval(timer);
    }, []);

    return <p className="shrink-0 text-base font-semibold tabular-nums sm:text-lg">{formatDateTimeDisplay(now.toISOString(), system, locale)}</p>;
}

function Glyph({ d }: { d: string }) {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d={d} />
        </svg>
    );
}

function WallButton({ label, onClick, pressed, children }: { label: string; onClick: () => void; pressed?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            aria-pressed={pressed}
            title={label}
            className={cx(buttonClassName({ variant: 'outline', size: 'sm' }), 'px-2.5', pressed && 'border-(--wall-accent) text-(--wall-accent)')}
        >
            {children}
        </button>
    );
}

/** The figure rises to its value when a screen opens, and moves on its own when a refresh changes it. */
function useCountUp(value: number | null): number | null {
    const [shown, setShown] = useState<number | null>(value === null ? null : 0);
    const last = useRef(0);

    useEffect(() => {
        if (value === null || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setShown(value);
            return undefined;
        }
        const from = last.current;
        const started = performance.now();
        let frame = 0;
        const tick = (time: number) => {
            const progress = Math.min(1, (time - started) / 700);
            const eased = 1 - (1 - progress) ** 3;
            setShown(progress === 1 ? value : from + (value - from) * eased);
            if (progress < 1) frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        last.current = value;
        return () => cancelAnimationFrame(frame);
    }, [value]);

    return shown;
}

function WallKpi({ item, hero }: { item: SrKpiItem; hero: boolean }) {
    const { sr } = useSituationLabels();
    const shown = useCountUp(item.value);
    const attention = item.state === 'attention';
    const change = item.previous !== undefined && item.value !== null ? item.value - item.previous : 0;

    return (
        <div
            className={cx(
                'relative min-w-0 overflow-hidden rounded-card border border-t-[3px] bg-(--app-surface) p-4',
                hero ? 'flex-[2_1_16rem]' : 'flex-[1_1_9rem] lg:flex-[1_1_11rem]',
                attention ? 'border-amber-500' : 'border-(--app-border)',
            )}
            style={{
                ...(hero ? { background: 'linear-gradient(135deg, color-mix(in srgb, var(--wall-accent) 22%, var(--app-surface)), var(--app-surface))' } : {}),
                borderTopColor: attention ? undefined : 'var(--wall-accent)',
            }}
        >
            <p className="flex items-center justify-between gap-2 text-sm font-medium text-(--app-muted-foreground)">
                <span className="truncate">{sr(item.label)}</span>
                {attention && <span className="shrink-0 rounded-full bg-amber-500/15 px-2 py-px text-[11px] font-semibold text-amber-600 dark:text-amber-400">{sr('ui.attention')}</span>}
                {item.state === 'clear' && <span className="shrink-0 rounded-full bg-emerald-500/15 px-2 py-px text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">{sr('ui.clear')}</span>}
            </p>
            {item.state === 'not_configured'
                ? <p className="mt-2 text-base font-medium text-(--app-muted-foreground)">{sr('ui.not_configured')}</p>
                : (
                    <p className={cx('mt-1 font-bold leading-tight tabular-nums', hero ? 'text-[clamp(2.5rem,4.5vw,5rem)]' : 'text-[clamp(1.75rem,2.8vw,3.5rem)]', attention && 'text-amber-500')}>
                        {formatKpi({ value: shown, unit: item.unit }, sr)}
                    </p>
                )}
            {item.previous !== undefined && (
                <p className="mt-1.5">
                    <span className="inline-block rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs font-medium text-(--app-muted-foreground)" title={sr('ui.previous', { value: Number(item.previous).toLocaleString() })}>
                        {change === 0 ? sr('briefing.no_change') : sr('briefing.change_vs_previous', { change: `${change > 0 ? '▲ +' : '▼ '}${change.toLocaleString()}` })}
                    </span>
                </p>
            )}
        </div>
    );
}

/** The room's state in one pill, whichever screen is up: how many figures are past a threshold, or that none is. */
function WallStatus({ screens }: { screens: Screen[] }) {
    const { sr } = useSituationLabels();
    const stated = new Map(screens.flatMap(kpisOf).filter((item) => item.state === 'attention' || item.state === 'clear').map((item) => [item.key, item]));
    const attention = [...stated.values()].filter((item) => item.state === 'attention').length;

    if (stated.size === 0) return null;

    return (
        <p
            role="status"
            className={cx(
                'inline-flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold',
                attention > 0 ? 'border-amber-500/40 bg-amber-500/12 text-amber-600 dark:text-amber-400' : 'border-emerald-500/40 bg-emerald-500/12 text-emerald-600 dark:text-emerald-400',
            )}
        >
            {attention > 0 ? <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" /> : <CheckCircle className="h-4 w-4 shrink-0" aria-hidden="true" />}
            {attention > 0 ? sr('briefing.status_attention', { count: attention }) : sr('briefing.status_clear')}
        </p>
    );
}

/** What needs someone's eye anywhere in the room, whichever screen happens to be up. */
function AttentionRail({ screens }: { screens: Screen[] }) {
    const { sr } = useSituationLabels();
    const stated = new Map(screens.flatMap(kpisOf).filter((item) => item.state === 'attention' || item.state === 'clear').map((item) => [item.key, item]));
    const attention = [...stated.values()].filter((item) => item.state === 'attention');

    if (stated.size === 0) return null;
    if (attention.length === 0) {
        return (
            <p className="flex items-center gap-2 font-medium text-emerald-600 dark:text-emerald-400">
                <span aria-hidden="true" className="h-2 w-2 rounded-full bg-emerald-500" />
                {sr('ui.clear')}
            </p>
        );
    }

    return (
        <p className="flex min-w-0 items-center gap-2 font-medium text-amber-600 dark:text-amber-400">
            <span aria-hidden="true" className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-amber-500" />
            <span className="truncate">{sr('ui.attention')}: {attention.map((item) => `${sr(item.label)} ${formatKpi(item, sr)}`).join(' · ')}</span>
        </p>
    );
}
