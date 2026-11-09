import { Head, router } from '@inertiajs/react';
import { Component, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode, type RefObject } from 'react';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import { CourtEmblem } from '@/court/components/auth/CourtAuthLayout';
import publicSite from '@/court/i18n/publicSite';
import { CourtMotes, CourtSeal } from '@/court/public/motion';
import type { PublicSignageProps, SignageDay, SignageHearing } from '@/court/types/public';
import { useBranding } from '@/hooks/useBranding';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { gregorianIsoToEthiopian } from '@/lib/calendar/ethiopianCalendar';
import '../../../../css/court-public.css';

/** The two stages: the door screen stands portrait (the board's own shape), a wall screen lies landscape. */
type Orientation = 'portrait' | 'landscape';
const STAGES: Record<Orientation, { width: number; height: number }> = {
    portrait: { width: 1080, height: 1920 },
    landscape: { width: 1920, height: 1080 },
};
/**
 * One sitting on the cause list (stage pixels): a page holds as many rows of
 * the least height as fit; a single short page lets its rows grow, up to the
 * most, so the list fills the column instead of ending halfway down it.
 */
const ROW_MIN: Record<Orientation, number> = { portrait: 66, landscape: 64 };
const ROW_MAX = 120;
/** The seconds each page and each notice stays. */
const PAGE_SECONDS = 12;
const NOTICE_SECONDS = 14;
/** After this long without a cursor movement the cursor and the screen tools hide. */
const IDLE_MS = 4000;
/** Every few minutes the whole stage moves a few pixels, so nothing burns into the panel. */
const SHIFT_MINUTES = 4;
const SHIFTS = [[0, 0], [3, 2], [-2, 3], [2, -3], [-3, -2]] as const;
/** Each bench keeps one colour, in the benches' fixed order. */
const BENCH_TONES = ['#d4ad57', '#7fb2ff', '#8fd3a8', '#e7a0c8', '#b79cff', '#f0b37e'];
/** The caseload bar's colour for each case status. */
const STATUS_TONES: Record<string, string> = {
    pending: '#d4ad57',
    active: '#7fb2ff',
    adjourned: '#b79cff',
    closed: '#8b98ab',
    dismissed: '#ee8b8b',
};

const ETH_MONTHS_AM = ['', 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
const WEEKDAYS_AM = ['እሑድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ'];
/** The Ethiopian clock counts from dawn: 07:00 is 1 in the morning. The part of the day, by the international hour. */
const ETH_DAY_PARTS: Array<[number, string]> = [[0, 'ከሌሊቱ'], [6, 'ከጠዋቱ'], [12, 'ከቀኑ'], [18, 'ከምሽቱ']];

const ethiopianDayPart = (hour: number): string => [...ETH_DAY_PARTS].reverse().find(([from]) => hour >= from)?.[1] ?? '';
const ethiopianHour = (hour: number): number => ((hour + 6) % 12) || 12;

/** A stored "HH:MM" (international, as hearings are set) on the Ethiopian clock: 14:00 is ከቀኑ 8:00. */
function ethiopianClock(time: string | null): { part: string; time: string } | null {
    if (!time || !/^\d{2}:\d{2}/.test(time)) return null;
    const hour = Number(time.slice(0, 2));
    return { part: ethiopianDayPart(hour), time: `${ethiopianHour(hour)}:${time.slice(3, 5)}` };
}

/** A Gregorian YYYY-MM-DD as the Ethiopian weekday and date: ሐሙስ, ጥቅምት 19 ቀን 2019 ዓ.ም. */
function ethiopianDay(iso: string): { weekday: string; date: string; short: string } {
    const [year, month, day] = iso.slice(0, 10).split('-').map(Number);
    const eth = gregorianIsoToEthiopian(iso.slice(0, 10));
    const weekday = WEEKDAYS_AM[new Date(Date.UTC(year, month - 1, day)).getUTCDay()] ?? '';
    return {
        weekday,
        date: eth ? `${ETH_MONTHS_AM[eth.month]} ${eth.day} ቀን ${eth.year} ዓ.ም.` : '',
        short: eth ? `${weekday} ${ETH_MONTHS_AM[eth.month]} ${eth.day}` : weekday,
    };
}

type SignageText = typeof publicSite.am.signage;
type TextKey = Exclude<keyof SignageText, 'status'>;

const fill = (template: string, values: Record<string, string | number>): string =>
    Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template);

/** The board's text: Amharic throughout, whatever language the browser was left in. */
const say = (key: TextKey, values?: Record<string, string | number>): string => {
    const value = publicSite.am.signage[key] as string;
    return values ? fill(value, values) : value;
};

/** The time of day in the court's zone, on the server's clock (read at each refresh), ticking each second. */
function useCourtClock(serverNow: string, timezone: string) {
    const skew = useMemo(() => {
        const server = new Date(serverNow).getTime();
        return Number.isNaN(server) ? 0 : server - Date.now();
    }, [serverNow]);
    const [tick, setTick] = useState(() => Date.now());

    useEffect(() => {
        let timer = 0;
        const next = () => {
            setTick(Date.now());
            timer = window.setTimeout(next, 1000 - ((Date.now() + skew) % 1000));
        };
        timer = window.setTimeout(next, 1000 - ((Date.now() + skew) % 1000));
        return () => window.clearTimeout(timer);
    }, [skew]);

    const format = useMemo(() => {
        const options: Intl.DateTimeFormatOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' };
        try {
            return new Intl.DateTimeFormat('en-GB', { ...options, timeZone: timezone });
        } catch {
            return new Intl.DateTimeFormat('en-GB', options);
        }
    }, [timezone]);

    const parts = Object.fromEntries(format.formatToParts(new Date(tick + skew)).map((part) => [part.type, part.value]));
    const hour = Number(parts.hour);
    return {
        tick,
        minutesOfDay: hour * 60 + Number(parts.minute),
        minutes: parts.minute,
        seconds: parts.second,
        ethiopianPart: ethiopianDayPart(hour),
        ethiopianHour: ethiopianHour(hour),
        format,
    };
}

/**
 * The stage and its scale. The board is portrait (the court's door screen):
 * on any other screen it shows framed in the middle, as the door screen
 * shows it. `?orientation=landscape` lays it out for a wall screen instead;
 * `?rotate=90` (or 270) turns the portrait board for a panel stood on its
 * side while its computer still drives it as landscape.
 */
function useStage(): { orientation: Orientation; scale: number; rotate: number } {
    const read = (): { orientation: Orientation; scale: number; rotate: number } => {
        const query = new URLSearchParams(window.location.search);
        const orientation: Orientation = query.get('orientation') === 'landscape' ? 'landscape' : 'portrait';
        const rotate = orientation === 'portrait' && ['90', '270'].includes(query.get('rotate') ?? '') ? Number(query.get('rotate')) : 0;
        const stage = STAGES[orientation];
        const [width, height] = rotate ? [stage.height, stage.width] : [stage.width, stage.height];
        return { orientation, rotate, scale: Math.min(window.innerWidth / width, window.innerHeight / height) };
    };
    const [state, setState] = useState(read);
    useEffect(() => {
        const fit = () => setState(read());
        window.addEventListener('resize', fit);
        return () => window.removeEventListener('resize', fit);
    }, []);
    return state;
}

/** A box's height in stage pixels (the scale does not matter), followed as it changes. */
function useBoxHeight(box: RefObject<HTMLElement | null>, fallback: number, watch: unknown): number {
    const [height, setHeight] = useState(fallback);
    useEffect(() => {
        const element = box.current;
        if (!element || typeof ResizeObserver === 'undefined') return undefined;
        const measure = () => setHeight(element.clientHeight);
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [box, watch]);
    return height;
}

/** Cycles 0..count-1 every `seconds`; restarts when `reset` changes. */
function useRotation(count: number, seconds: number, reset?: unknown): number {
    const [index, setIndex] = useState(0);
    useEffect(() => setIndex(0), [reset]);
    useEffect(() => {
        if (count < 2) return undefined;
        const timer = window.setInterval(() => setIndex((current) => (current + 1) % count), seconds * 1000);
        return () => window.clearInterval(timer);
    }, [count, seconds]);
    return count > 0 ? index % count : 0;
}

/** Keeps the screen awake while the board shows, where the browser allows it. */
function useWakeLock() {
    useEffect(() => {
        let lock: WakeLockSentinel | null = null;
        const acquire = async () => {
            if (document.visibilityState !== 'visible' || !('wakeLock' in navigator)) return;
            try {
                lock = await navigator.wakeLock.request('screen');
            } catch {
                // Not allowed here (no user gesture, a policy): the screen's own power settings apply.
            }
        };
        void acquire();
        document.addEventListener('visibilitychange', acquire);
        return () => {
            document.removeEventListener('visibilitychange', acquire);
            void lock?.release().catch(() => undefined);
        };
    }, []);
}

/** The court's mark: the logo set in System Settings as it is drawn, else the scales in brass. */
function Medallion({ logoUrl }: { logoUrl: string | null }) {
    return (
        <span className="sg-medallion" aria-hidden="true">
            {logoUrl
                ? <img src={logoUrl} alt="" className="sg-medallion-logo" />
                : <CourtEmblem className="sg-medallion-mark" />}
        </span>
    );
}

function Progress({ count, active, seconds }: { count: number; active: number; seconds: number }) {
    if (count < 2) return null;
    return (
        <span aria-hidden="true" className="sg-progress">
            {Array.from({ length: Math.min(count, 12) }).map((_, index) => (
                <span key={index} data-done={index < active ? '' : undefined}>
                    {index === active && <span key={`${active}-${count}`} className="sg-progress-fill" style={{ '--page': `${seconds}s` } as CSSProperties} />}
                </span>
            ))}
        </span>
    );
}

/** The court's small brass marks, one per section, drawn as line icons. */
const ICONS = {
    // The courthouse: pediment, columns and steps.
    courthouse: 'M3 9.5 12 4l9 5.5M4.5 9.5h15M6 9.5v8M10 9.5v8M14 9.5v8M18 9.5v8M4 17.5h16M3 20.5h18',
    // A megaphone, for the registry's notices.
    notices: 'M4 10v4a1 1 0 0 0 1 1h2l6 4V5L7 9H5a1 1 0 0 0-1 1ZM16.5 9a4 4 0 0 1 0 6M19 6.5a7.5 7.5 0 0 1 0 11',
    // The scales of justice.
    scales: 'M12 4v16M7 20h10M5 7h14M7 7l-3 6a3 3 0 0 0 6 0L7 7ZM17 7l-3 6a3 3 0 0 0 6 0l-3-6ZM12 4l-1.5 1.5M12 4l1.5 1.5',
    // A week on the calendar.
    calendar: 'M4 6.5h16v14H4zM4 10.5h16M8.5 4v5M15.5 4v5M8 14h2M11 14h2M14 14h2M8 17h2M11 17h2',
    // A decision: a document under the court's seal.
    decision: 'M6 3h8l4 4v14H6zM14 3v4h4M9 11h6M9 14h4M14.5 18.5a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6Z',
    // A phone, for following a case by the code.
    phone: 'M8 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM11 18h2',
} as const;

function Icon({ name }: { name: keyof typeof ICONS }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className="sg-icon" fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round">
            {/* pathLength lets the stroke draw itself in, whatever the path's real length. */}
            <path d={ICONS[name]} pathLength={100} />
        </svg>
    );
}

/** The courthouse behind the letterhead: steps, columns rising one after another, then the pediment. */
function Courthouse() {
    const columns = Array.from({ length: 9 }, (_, index) => 62 + index * 60);
    return (
        <svg viewBox="0 0 600 170" aria-hidden="true" className="sg-courthouse">
            <path className="sg-courthouse-pediment" d="M30 64 L300 10 L570 64 Z" />
            <rect className="sg-courthouse-beam" x="24" y="64" width="552" height="12" rx="2" />
            {columns.map((x, index) => (
                <rect key={x} className="sg-courthouse-column" x={x - 12} y="80" width="24" height="64" rx="3" style={{ '--i': index } as CSSProperties} />
            ))}
            <rect className="sg-courthouse-step" x="16" y="146" width="568" height="8" rx="2" />
            <rect className="sg-courthouse-step" x="4" y="157" width="592" height="9" rx="2" />
        </svg>
    );
}

/**
 * A figure that counts up from zero when the board loads. Unlike the public
 * site's, it follows the board's own motion switch only: the screen's
 * computer may be set to reduce motion, but the lobby is not the one at it.
 */
function BoardCount({ value, format }: { value: number; format: (value: number) => string }) {
    const ref = useRef<HTMLSpanElement>(null);
    const [shown, setShown] = useState(value);
    useEffect(() => {
        const element = ref.current;
        if (!element || element.closest('.court-signage')?.getAttribute('data-motion') !== 'on' || value <= 0) {
            setShown(value);
            return undefined;
        }
        let frame = 0;
        const start = performance.now();
        const tick = (now: number) => {
            const progress = Math.min(1, (now - start) / 1600);
            setShown(Math.round(value * (1 - (1 - progress) ** 3)));
            if (progress < 1) frame = window.requestAnimationFrame(tick);
        };
        setShown(0);
        frame = window.requestAnimationFrame(tick);
        return () => window.cancelAnimationFrame(frame);
    }, [value]);
    return (
        <span ref={ref}>
            <span aria-hidden="true">{format(shown)}</span>
            <span className="sr-only">{format(value)}</span>
        </span>
    );
}

/** The gavel: it strikes the block now and then, and a brass ring spreads from where it lands. */
function Gavel() {
    return (
        <svg viewBox="0 0 64 64" aria-hidden="true" className="sg-gavel">
            {/* The sound block, and the ring that spreads from it when the head lands. */}
            <ellipse className="sg-gavel-ring" cx="17" cy="53" rx="14" ry="3.4" />
            <rect className="sg-gavel-block" x="3" y="53" width="28" height="5" rx="1.5" />
            <rect className="sg-gavel-block" x="6" y="57" width="22" height="4" rx="1.5" />
            {/* The gavel at the moment it lands: the handle out to the right, the head upright across its end,
                the head's flat face on the block. It lifts by turning about the handle's far end. */}
            <g className="sg-gavel-arm">
                <path className="sg-gavel-handle" d="M24 41 L58 35" />
                <rect className="sg-gavel-head" x="10" y="29" width="14" height="23" rx="3" />
                <rect className="sg-gavel-band" x="9" y="32" width="16" height="3" rx="1" />
                <rect className="sg-gavel-band" x="9" y="46" width="16" height="3" rx="1" />
            </g>
        </svg>
    );
}

/** A sitting's time on the Ethiopian clock: the part of the day small above the hour. */
function SittingTime({ time, className = '' }: { time: string | null; className?: string }) {
    const at = ethiopianClock(time);
    return <span role="cell" className={className}>{at ? <><small>{at.part}</small>{at.time}</> : '—'}</span>;
}

/** The bench a case sits before, in its own colour; on the next sitting's card, the room under it. */
function Bench({ hearing, tone, large = false }: { hearing: SignageHearing; tone: string | null; large?: boolean }) {
    return (
        <span className={large ? 'sg-where sg-where-large' : 'sg-where'}>
            {hearing.bench
                ? <span className="sg-bench" style={{ '--bench': tone ?? '#94a3b8' } as CSSProperties}>{hearing.bench}</span>
                : <span className="sg-bench sg-bench-none">{say('noBench')}</span>}
            {large && hearing.location && <span className="sg-room">{hearing.location}</span>}
        </span>
    );
}

/**
 * The court's door display (PublicSignageController): the portrait screen at
 * the court's entrance (1080x1920), for the parties arriving. It answers, in
 * order, what they come to learn: whether their case sits today, when, before
 * which bench and in which room (the next sitting large, then the cause list
 * a page at a time, each bench in its own colour); which cases have a
 * decision released; and how to follow a case (a code to read with a phone)
 * and reach the registry; then the notices. Once today's sittings are past it
 * shows the next sitting day.
 *
 * It is in Amharic throughout, and every hour and date is Ethiopian (a
 * sitting set for 14:00 reads ከቀኑ 8:00). It is a screen, not a document: the
 * stage is scaled to the display, shifts a few pixels now and then against
 * burn-in, hides the cursor while the mouse is still and keeps the screen
 * awake. It re-reads its data every minute; a failed read leaves the board up
 * and says how old it is.
 */
/** How long a board that has failed waits before it starts itself again. */
const RECOVER_SECONDS = 15;

/**
 * The board's safety net. A door screen has nobody at it to press reload, so
 * should the board ever fail to draw (data of a shape it does not know, say,
 * between a deploy and its next read), it shows the court's name and starts
 * itself again after a few seconds, instead of standing blank all day.
 */
class BoardGuard extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    componentDidCatch(): void {
        window.setTimeout(() => window.location.reload(), RECOVER_SECONDS * 1000);
    }

    render(): ReactNode {
        if (!this.state.failed) return this.props.children;
        return (
            <div className="court-site court-signage" data-theme="light" lang="am">
                <div className="sg-recover" role="status">
                    <p className="sg-eyebrow">{say('crestOffice')}</p>
                    <p className="sg-court">{say('crestCourt')}</p>
                    <p className="sg-recover-note">{say('restarting')}</p>
                </div>
            </div>
        );
    }
}

export default function PublicSignage(props: PublicSignageProps) {
    return (
        <BoardGuard>
            <Board {...props} />
        </BoardGuard>
    );
}

function Board(props: PublicSignageProps) {
    const { getBoolean } = useSystemSettings();
    const branding = useBranding();
    const { now, timezone, benches, today, nextDay, decisions, stats, week, announcements, registry, portal, refreshSeconds } = props;
    const number = useMemo(() => new Intl.NumberFormat('am-ET'), []);
    const statusLabel = (label: string) => (publicSite.am.signage.status as Record<string, string>)[label.toLowerCase()] ?? label;

    // The letterhead names the court by its office, not by the system's name.
    const courtName = `${say('crestOffice')} ${say('crestCourt')}`;
    const benchTone = (id: number | null) => {
        const index = benches.findIndex((bench) => bench.id === id);
        return index >= 0 ? BENCH_TONES[index % BENCH_TONES.length] : null;
    };

    const clock = useCourtClock(now, timezone);
    const { orientation, scale, rotate } = useStage();
    const stage = STAGES[orientation];
    // Light by default, as a lobby by day is bright; `?theme=dark` gives the ink face for a dim hall.
    const theme = useMemo(() => (new URLSearchParams(window.location.search).get('theme') === 'dark' ? 'dark' : 'light'), []);
    // The board moves unless the court switches animations off (or one screen asks `?motion=off`). The
    // screen's computer may be set to reduce motion, but nobody sits at it: that setting is not the lobby's.
    const motion = getBoolean('appearance.enable_ui_animations', true)
        && new URLSearchParams(window.location.search).get('motion') !== 'off';
    useWakeLock();
    const todayLabel = useMemo(() => ethiopianDay(today.date), [today.date]);

    // Re-read every minute in place; a failed read keeps what is on screen.
    const [readAt, setReadAt] = useState(() => Date.now());
    useEffect(() => setReadAt(Date.now()), [now]);
    useEffect(() => {
        const timer = window.setInterval(() => router.reload({
            showProgress: false,
            onHttpException: () => false,
            onNetworkError: () => false,
        }), Math.max(30, refreshSeconds) * 1000);
        return () => window.clearInterval(timer);
    }, [refreshSeconds]);
    const stale = clock.tick - readAt > Math.max(30, refreshSeconds) * 2500;
    const read = ethiopianClock(clock.format.format(new Date(now)));
    const readTime = read ? `${read.part} ${read.time}` : '';

    // Nobody at the mouse: hide the cursor and the screen tools.
    const [idle, setIdle] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    useEffect(() => {
        let timer = window.setTimeout(() => setIdle(true), IDLE_MS);
        const wake = () => {
            setIdle(false);
            window.clearTimeout(timer);
            timer = window.setTimeout(() => setIdle(true), IDLE_MS);
        };
        const onFullscreen = () => setFullscreen(document.fullscreenElement !== null);
        window.addEventListener('pointermove', wake);
        window.addEventListener('keydown', wake);
        document.addEventListener('fullscreenchange', onFullscreen);
        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('pointermove', wake);
            window.removeEventListener('keydown', wake);
            document.removeEventListener('fullscreenchange', onFullscreen);
        };
    }, []);
    const toggleFullscreen = () => {
        if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined);
        else void document.documentElement.requestFullscreen?.().catch(() => undefined);
    };

    const shift = SHIFTS[Math.floor(clock.tick / (SHIFT_MINUTES * 60_000)) % SHIFTS.length];

    // Today until its last sitting's hour has come; after that (or on a day without sittings) the next sitting day.
    const minutesOf = (time: string | null) => (time ? Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5)) : -1);
    const upcomingToday = today.hearings.filter((hearing) => hearing.time !== null && minutesOf(hearing.time) >= clock.minutesOfDay);
    const onTomorrow = upcomingToday.length === 0 && nextDay !== null;
    const day: SignageDay = onTomorrow && nextDay ? nextDay : today;
    const dayLabel = useMemo(() => ethiopianDay(day.date), [day.date]);

    // The next sitting: today's next hour, or the first of the next sitting day.
    const nextTime = onTomorrow ? (day.hearings[0]?.time ?? null) : (upcomingToday[0]?.time ?? null);
    const nextHearings = nextTime ? day.hearings.filter((hearing) => hearing.time === nextTime) : [];
    const next = nextHearings[0] ?? null;
    const nextClock = next ? ethiopianClock(next.time) : null;
    const untilNext = next && !onTomorrow ? minutesOf(next.time) - clock.minutesOfDay : 0;
    const countdown = untilNext <= 0
        ? say('now')
        : untilNext < 60 ? say('inMinutes', { m: untilNext }) : say('inHours', { h: Math.floor(untilNext / 60), m: untilNext % 60 });

    // The cause list, a page at a time, as many rows as the column holds.
    const listBox = useRef<HTMLDivElement>(null);
    const listHeight = useBoxHeight(listBox, 8 * ROW_MIN[orientation], `${orientation}-${day.hearings.length > 0}-${decisions.length > 0}`);
    const rows = Math.max(1, Math.floor(listHeight / ROW_MIN[orientation]));
    const pages = useMemo(() => {
        const out: SignageHearing[][] = [];
        for (let index = 0; index < day.hearings.length; index += rows) out.push(day.hearings.slice(index, index + rows));
        return out;
    }, [day.hearings, rows]);
    const page = useRotation(pages.length, PAGE_SECONDS, `${day.date}-${pages.length}-${rows}`);
    const rowHeight = pages.length === 1
        ? Math.min(ROW_MAX, Math.max(ROW_MIN[orientation], Math.floor(listHeight / Math.max(1, pages[0].length))))
        : ROW_MIN[orientation];

    const notice = useRotation(announcements.length, NOTICE_SECONDS, announcements.length);
    const current = announcements[notice];
    const registryLines = [
        registry.office_hours && { label: say('officeHours'), value: registry.office_hours },
        registry.phone && { label: say('phone'), value: registry.phone },
        registry.address && { label: say('address'), value: registry.address },
    ].filter(Boolean) as Array<{ label: string; value: string }>;

    return (
        <LocalizedUiProvider>
            <Head title={`${say('title')} · ${courtName}`} />

            <div
                className="court-site court-signage"
                data-motion={motion ? 'on' : 'off'}
                data-orientation={orientation}
                data-theme={theme}
                data-idle={idle ? '' : undefined}
                data-decisions={decisions.length > 0 ? '' : undefined}
                lang="am"
            >
                <div
                    className="court-signage-stage"
                    style={{ width: stage.width, height: stage.height, '--scale': scale, '--rotate': `${rotate}deg`, '--sx': `${shift[0]}px`, '--sy': `${shift[1]}px` } as CSSProperties}
                >
                    {/* Brass specks rising slowly over the whole board, as dust in the light of a hall. */}
                    <CourtMotes count={26} />

                    {/* The court, and the Ethiopian hour and date. */}
                    <header className="sg-crest">
                        <Courthouse />
                        <span className="sg-sheen" aria-hidden="true" />
                        <Medallion logoUrl={branding.logoUrl} />
                        <div className="sg-crest-text">
                            <h1 aria-label={courtName}>
                                <span className="sg-eyebrow">{say('crestOffice')}</span>
                                <span className="sg-court">{say('crestCourt')}</span>
                            </h1>
                        </div>
                        <div className="sg-crest-time" aria-label={`${clock.ethiopianPart} ${clock.ethiopianHour}:${clock.minutes}`}>
                            <p className="sg-eth-time">
                                <span className="sg-eth-part">{clock.ethiopianPart}</span>
                                {clock.ethiopianHour}<span className="sg-colon">:</span>{clock.minutes}
                                {/* Each second turns over: the new one drops in. */}
                                <span className="sg-seconds"><span key={clock.seconds} className="sg-tick">{clock.seconds}</span></span>
                            </p>
                            <p className="sg-eth-weekday">{todayLabel.weekday}</p>
                            <p className="sg-eth-date">{todayLabel.date}</p>
                        </div>
                    </header>

                    {/* The next sitting: when, which case, before which bench and where. */}
                    <section className="sg-next" data-state={next ? 'next' : 'none'}>
                        {/* The scales of justice, faint at the card's end: its rings turn and its pans settle level. */}
                        <CourtSeal className="sg-next-seal" />
                        {/* A brass light running round the card's edge, lap after lap. */}
                        {next && <span className="sg-next-trail" aria-hidden="true" />}
                        <div className="sg-next-head">
                            <p className="sg-kicker">
                                <span className="sg-kicker-line">
                                    {next && <Gavel />}
                                    {onTomorrow ? say('nextDay') : say('nextSitting')}
                                </span>
                            </p>
                            {/* Today, how long until it; on the next sitting day, which day, in the same place. */}
                            {next && (
                                <span className="sg-countdown">
                                    <span className="sg-pulse" aria-hidden="true" />
                                    {onTomorrow ? `${dayLabel.short} · ${say('firstSitting')}` : countdown}
                                </span>
                            )}
                        </div>
                        {next ? (
                            <div key={`${day.date}-${next.id}`} className="sg-next-body court-signage-in">
                                <p className="sg-next-time">
                                    {nextClock && <span className="sg-eth-part">{nextClock.part}</span>}
                                    <span className="sg-next-hour">{nextClock?.time ?? '—'}</span>
                                </p>
                                <div className="min-w-0">
                                    <p className="sg-next-case">{next.case_number ?? '—'}</p>
                                    {/* The parties, as the court calls the case: plaintiff, then defendant. */}
                                    {next.parties && (next.parties.plaintiff || next.parties.defendant) && (
                                        <dl className="sg-parties">
                                            <div>
                                                <dt>{say('plaintiff')}</dt>
                                                <dd>
                                                    {next.parties.plaintiff ?? '—'}
                                                    {next.parties.plaintiff_others > 0 && <span>{say('andOthers', { n: next.parties.plaintiff_others })}</span>}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>{say('defendant')}</dt>
                                                <dd>{next.parties.defendant ?? '—'}</dd>
                                            </div>
                                        </dl>
                                    )}
                                    <p className="sg-next-matter">{[next.case_type, next.type].filter(Boolean).join(' · ') || '—'}</p>
                                    {nextHearings.length > 1 && <p className="sg-next-more">{say('alsoAt', { n: nextHearings.length - 1 })}</p>}
                                </div>
                                <Bench hearing={next} tone={benchTone(next.bench_id)} large />
                            </div>
                        ) : (
                            <p className="sg-next-empty">{today.hearings.length > 0 ? say('noMoreToday') : say('noneToday')}</p>
                        )}
                    </section>

                    {/* The day's cause list. */}
                    <section className="sg-list">
                        <div className="sg-section-head">
                            <h2>
                                <Icon name="courthouse" />
                                {onTomorrow ? say('causeListOn', { date: dayLabel.short }) : say('causeList')}
                                {day.hearings.length > 0 && <span className="sg-count">{say('sittings', { n: day.hearings.length })}</span>}
                            </h2>
                            {pages.length > 1 && (
                                <span className="sg-pages">
                                    {say('pageOf', { n: page + 1, total: pages.length })}
                                    <Progress count={pages.length} active={page} seconds={PAGE_SECONDS} />
                                </span>
                            )}
                        </div>
                        {day.hearings.length === 0 ? (
                            <div className="sg-list-empty">
                                <CourtEmblem className="h-24 w-24" />
                                <p>{say('noneToday')}</p>
                            </div>
                        ) : (
                            <div role="table" aria-label={say('causeList')} className="sg-table">
                                <div role="row" className="sg-row sg-row-head">
                                    <span aria-hidden="true" />
                                    <span role="columnheader">{say('time')}</span>
                                    <span role="columnheader">{say('case')}</span>
                                    <span role="columnheader">{say('matter')}</span>
                                    <span role="columnheader">{say('bench')}</span>
                                </div>
                                <div ref={listBox} className="sg-rows" style={{ '--row-h': `${rowHeight}px` } as CSSProperties}>
                                    <div key={`${day.date}-${page}-${rows}`} role="rowgroup">
                                        {(pages[page] ?? []).map((hearing, index) => {
                                            const past = !onTomorrow && hearing.time !== null && minutesOf(hearing.time) < clock.minutesOfDay;
                                            const isNext = hearing.time !== null && hearing.time === nextTime;
                                            return (
                                                <div
                                                    key={hearing.id}
                                                    role="row"
                                                    className="sg-row court-signage-in"
                                                    data-state={isNext ? 'next' : past ? 'past' : 'later'}
                                                    style={{ '--i': Math.min(index, 10) } as CSSProperties}
                                                >
                                                    <span className="sg-marker" aria-hidden="true" />
                                                    <SittingTime time={hearing.time} className="sg-cell-time" />
                                                    <span role="cell" className="sg-cell-case">{hearing.case_number ?? '—'}</span>
                                                    <span role="cell" className="sg-cell-matter">
                                                        <span>{hearing.case_type ?? '—'}</span>
                                                        {hearing.type && <span>{hearing.type}</span>}
                                                    </span>
                                                    <span role="cell" className="sg-cell-where">
                                                        <Bench hearing={hearing} tone={benchTone(hearing.bench_id)} />
                                                        {isNext && <span className="sg-tag">{say('next')}</span>}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                                <p className="sg-note">{say('note')}</p>
                            </div>
                        )}
                    </section>

                    {/* The notices the registry publishes, one after another, right under the cause list. */}
                    {current && (
                        <section className="sg-notices" aria-live="off">
                            <div className="sg-card sg-notice-card">
                                <div className="sg-card-head">
                                    <h2>
                                        <Icon name="notices" />
                                        {say('notices')}
                                        {announcements.length > 1 && <span className="sg-count">{notice + 1} / {announcements.length}</span>}
                                    </h2>
                                    <Progress count={announcements.length} active={notice} seconds={NOTICE_SECONDS} />
                                </div>
                                <article key={current.id} className="sg-notice court-signage-in">
                                    <p className="sg-notice-title">{current.title}</p>
                                    {current.summary && <p className="sg-notice-summary">{current.summary}</p>}
                                    {current.created_at && <p className="sg-notice-date">{ethiopianDay(current.created_at).date}</p>}
                                </article>
                            </div>
                        </section>
                    )}

                    {/* The court in figures, and this week's sitting days. */}
                    <section className="sg-overview">
                        <div className="sg-stats-card">
                            <h2><Icon name="scales" />{say('statsTitle')}</h2>
                            <dl className="sg-figures">
                                {([
                                    ['hearingsToday', stats.hearings_today],
                                    ['hearingsWeek', stats.hearings_week],
                                    ['filedToday', stats.filed_today],
                                    ['decisionsMonth', stats.decisions_month],
                                ] as const).map(([key, value]) => (
                                    <div key={key}>
                                        <dt>{say(key)}</dt>
                                        <dd><BoardCount value={value} format={(shown) => number.format(shown)} /></dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="sg-caseload">
                                <p className="sg-caseload-head">
                                    {say('totalCases')}
                                    <b><BoardCount value={stats.total_cases} format={(shown) => number.format(shown)} /></b>
                                </p>
                                <div className="sg-bar" aria-hidden="true">
                                    {stats.by_status.map((stat, index) => (
                                        <span key={stat.label} style={{ flexGrow: stat.total, background: STATUS_TONES[stat.label.toLowerCase()] ?? '#64748b', '--i': index } as CSSProperties} />
                                    ))}
                                </div>
                                <ul className="sg-legend">
                                    {stats.by_status.map((stat) => (
                                        <li key={stat.label}>
                                            <span aria-hidden="true" style={{ background: STATUS_TONES[stat.label.toLowerCase()] ?? '#64748b' }} />
                                            {statusLabel(stat.label)}
                                            <b>{number.format(stat.total)}</b>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                        <div className="sg-week-card">
                            <h2><Icon name="calendar" />{say('weekTitle')}</h2>
                            {week.length === 0 ? (
                                <p className="sg-week-none">{say('weekNone')}</p>
                            ) : (
                                <ul className="sg-week">
                                    {week.map((entry) => {
                                        const label = ethiopianDay(entry.date);
                                        const isToday = entry.date === today.date;
                                        return (
                                            <li key={entry.date} data-today={isToday ? '' : undefined}>
                                                <span className="sg-week-day">{label.weekday}</span>
                                                <span className="sg-week-date">{label.date.replace(/ ቀን .*$/, '')}</span>
                                                {isToday && <span className="sg-week-mark">{say('todayMark')}</span>}
                                                <b>{say('sittings', { n: number.format(entry.count) })}</b>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                    </section>

                    {/* Decisions released lately, by case number. */}
                    {decisions.length > 0 && (
                        <section className="sg-decisions">
                            <div className="sg-section-head">
                                <h2>
                                    <Icon name="decision" />
                                    {say('decisionsTitle')}
                                    <span className="sg-count">{say('decisionsWhen', { days: 14 })}</span>
                                </h2>
                            </div>
                            <ul className="sg-decision-list">
                                {decisions.map((decision) => <li key={decision.case_number}>{decision.case_number}</li>)}
                            </ul>
                        </section>
                    )}

                    {/* How to follow a case, and the registry. */}
                    <section className="sg-info" data-registry={registryLines.length > 0 ? '' : undefined}>
                        <div className="sg-follow">
                            {portal.qr && (
                                <span className="sg-qr-frame" aria-hidden="true">
                                    <span className="sg-qr" dangerouslySetInnerHTML={{ __html: portal.qr }} />
                                </span>
                            )}
                            <div className="min-w-0">
                                <h2><Icon name="phone" />{say('followTitle')}</h2>
                                <p>{say('followText')}</p>
                                {/* Without the registry's details set, the one line about it goes here. */}
                                {registryLines.length === 0 && <p className="sg-ask">{say('askRegistry')}</p>}
                            </div>
                        </div>
                        {registryLines.length > 0 && (
                            <div className="sg-registry">
                                <h2><Icon name="courthouse" />{say('registry')}</h2>
                                <dl>
                                    {registryLines.map((line) => (
                                        <div key={line.label}>
                                            <dt>{line.label}</dt>
                                            <dd>{line.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        )}
                    </section>

                    {/* How fresh the board is. */}
                    <footer className="sg-foot">
                        <p className={stale ? 'sg-fresh is-stale' : 'sg-fresh'} role="status">
                            {say(stale ? 'stale' : 'updated', { time: readTime })}
                        </p>
                    </footer>
                </div>

                <button type="button" onClick={toggleFullscreen} className="court-signage-tools sg-tool">
                    {fullscreen ? say('exitFullscreen') : say('fullscreen')}
                </button>
            </div>
        </LocalizedUiProvider>
    );
}
