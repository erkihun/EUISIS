import { useCallback, useEffect, useState } from 'react';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Button, buttonClassName } from '@euisis/ui';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import PanelSkeleton from '@/Components/SituationRoom/PanelSkeleton';
import { PanelGrid } from '@/Components/SituationRoom/Panels';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import type { SrFilters, SrLabels, SrPanel, SrResult, SrScope } from '@/types/situationRoom';

type Screen = { category: string; panels: SrPanel[] };

type Props = {
    labels: SrLabels;
    scope: SrScope;
    filters: SrFilters;
    refresh_seconds: number;
    rotation_seconds: number;
    privacy: { small_cell: number };
    result?: SrResult<{ screens: Screen[]; has_errors: boolean }>;
};

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

function WallBody({ scope, refresh_seconds, rotation_seconds, privacy, result }: Props) {
    const { sr, label, locale } = useSituationLabels();
    const system = useCalendarSystem();
    const screens = result?.payload.screens ?? [];
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const current = screens.length > 0 ? screens[index % screens.length] : null;
    const step = useCallback((by: number) => setIndex((i) => (screens.length === 0 ? 0 : (i + by + screens.length) % screens.length)), [screens.length]);

    useEffect(() => {
        if (paused || screens.length < 2) return undefined;
        const timer = window.setInterval(() => step(1), rotation_seconds * 1000);
        return () => window.clearInterval(timer);
    }, [paused, rotation_seconds, screens.length, step]);

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

    return (
        <div className="flex min-h-dvh flex-col bg-(--app-background) text-(--app-foreground)">
            <Head title={sr('wall.title')} />

            <header className="flex flex-wrap items-center gap-3 border-b border-(--app-border) bg-(--app-surface) px-4 py-3 sm:px-6">
                <div className="min-w-0 flex-1">
                    <h1 className="truncate text-xl font-semibold sm:text-2xl">{current ? sr(`categories.${current.category}.title`) : sr('title')}</h1>
                    <p className="truncate text-sm text-(--app-muted-foreground)">{scopeName} · {sr('wall.aggregates_only')}</p>
                </div>
                {screens.length > 1 && (
                    <div className="flex items-center gap-2" role="group" aria-label={sr('wall.screen', { current: (index % screens.length) + 1, total: screens.length })}>
                        <Button variant="outline" size="sm" onClick={() => step(-1)} aria-label={sr('wall.previous')}>‹</Button>
                        <span className="text-sm tabular-nums" aria-live="polite">{sr('wall.screen', { current: (index % screens.length) + 1, total: screens.length })}</span>
                        <Button variant="outline" size="sm" onClick={() => step(1)} aria-label={sr('wall.next')}>›</Button>
                        <Button variant="ghost" size="sm" onClick={() => setPaused((p) => !p)} aria-pressed={paused}>{sr(paused ? 'wall.resume' : 'wall.pause')}</Button>
                    </div>
                )}
                <Button variant="ghost" size="sm" onClick={() => void document.documentElement.requestFullscreen?.()}>{sr('wall.fullscreen')}</Button>
                <LanguageSwitcher variant="toolbar" />
                <ThemeToggle variant="toolbar" />
                <Link href={route('situation-room.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{sr('wall.exit')}</Link>
            </header>

            <main id="main-content" className="flex-1 p-4 sm:p-6">
                <Deferred data="result" fallback={<PanelSkeleton label={sr('ui.loading')} />}>
                    {current
                        ? <PanelGrid panels={current.panels} options={{ smallCell: privacy.small_cell, wall: true }} />
                        : <p className="text-lg text-(--app-muted-foreground)">{sr('wall.nothing')}</p>}
                </Deferred>
            </main>

            {result && (
                <footer className="border-t border-(--app-border) px-4 py-2 text-sm text-(--app-muted-foreground) sm:px-6">
                    {sr('ui.computed_at', { time: formatDateTimeDisplay(result.generated_at, system, locale) })} · {sr('wall.refreshes', { minutes: Math.max(1, Math.round(refresh_seconds / 60)) })}
                </footer>
            )}
        </div>
    );
}
