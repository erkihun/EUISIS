import { type ReactNode, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';

/*
 * The sign-in stage, either side of the form:
 *
 *   left   NetworkFlow     City Administration
 *                                 │
 *                          institutions (4)
 *                                 │
 *                          employees (12)          records travel down as waves of light
 *
 *   right  IdentityOrbit   the employee ID card, scanned and verified, with the services it opens
 *                          (cafeteria, transport, performance, grievances, daily activity, reports)
 *                          orbiting it in depth
 *
 * Each is a fixed design canvas scaled to its column and centred. The network, the waves and the
 * card effects are CSS keyframes on one shared 4 s clock (resources/css/app.css, "Sign-in flow");
 * the orbit is one requestAnimationFrame loop that moves six chips and stops when motion is off.
 * The reduced-motion preference and the animations setting leave a still, complete picture.
 */

export type FlowStep = { key: string; title: string; text: string };
export type FlowService = { key: string; label: string };

/** Motion is allowed unless the OS asks for less or the admin switched animations off. */
export function useMotionAllowed(): boolean {
    const [allowed, setAllowed] = useState(false);
    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setAllowed(!query.matches && document.documentElement.dataset.animations !== 'false');
        update();
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, []);
    return allowed;
}

type Pt = { x: number; y: number };
const H = 560;
const MID = 280;

/** Fit a canvas of design height H into a box: height sets the scale, never above 1.25. */
function useFit(minWidth: number, stretch: boolean) {
    const ref = useRef<HTMLDivElement>(null);
    const [fit, setFit] = useState({ scale: 1, width: minWidth, top: 0, left: 0 });
    useLayoutEffect(() => {
        const box = ref.current;
        if (!box) return;
        const update = () => {
            const cw = box.clientWidth;
            const ch = box.clientHeight;
            let scale = Math.max(0.35, Math.min(ch / H, 1.25));
            let width = stretch ? cw / scale : minWidth;
            if (cw / scale < minWidth) {
                scale = cw / minWidth;
                width = minWidth;
            }
            setFit({ scale, width: Math.round(width), top: (ch - H * scale) / 2, left: stretch ? 0 : (cw - minWidth * scale) / 2 });
        };
        update();
        const observer = new ResizeObserver(update);
        observer.observe(box);
        return () => observer.disconnect();
    }, [minWidth, stretch]);
    return { ref, fit };
}

function Caption({ x, y, width, align = 'left', step, tier }: { x: number; y: number; width: number; align?: 'left' | 'center'; step: FlowStep; tier: number }) {
    return (
        <div className={`lg-caption lg-tier-${tier} absolute ${align === 'center' ? 'text-center' : ''}`} style={{ left: x, top: y, width }}>
            <p className="lg-caption-title">{step.title}</p>
            <p className="lg-caption-text">{step.text}</p>
        </div>
    );
}

const findStep = (steps: FlowStep[], key: string) => steps.find((s) => s.key === key) ?? { key, title: key, text: '' };

// ── Left: the organisation network, top to bottom ─────────────────────────────
// A 580 × 560 canvas: tier captions in a gutter on the left, the tree on the right.

const NET_W = 580;
const NET_ROOT: Pt = { x: 378, y: 72 };
const NET_INSTITUTION_Y = 236;
const NET_INSTITUTION_X = [214, 323, 432, 541];
const NET_EMPLOYEE_Y = 404;
const NET_EMPLOYEE_SPREAD = [-34, 0, 34];
/** The one employee whose record continues to the ID card: the last of the last institution, nearest the card. */
const NET_SELECTED: Pt = { x: NET_INSTITUTION_X[NET_INSTITUTION_X.length - 1] + NET_EMPLOYEE_SPREAD[NET_EMPLOYEE_SPREAD.length - 1], y: NET_EMPLOYEE_Y };

/** Vertical S-curve: leaves and arrives upright, the way an org chart reads. */
function drop(a: Pt, b: Pt): string {
    const mid = (a.y + b.y) / 2;
    return `M${a.x} ${a.y} C${a.x} ${mid} ${b.x} ${mid} ${b.x} ${b.y}`;
}

export function NetworkFlow({ steps }: { steps: FlowStep[] }) {
    const { ref, fit } = useFit(NET_W, false);
    const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '');
    const linkGradient = `lg-link-${uid}`;
    const rootGlow = `lg-root-${uid}`;

    const net = useMemo(() => {
        const links: { d: string; tier: 0 | 1 | 2 }[] = [];
        const institutions = NET_INSTITUTION_X.map((x) => ({ x, y: NET_INSTITUTION_Y }));
        const employees: Pt[] = [];
        institutions.forEach((institution) => {
            links.push({ d: drop(NET_ROOT, institution), tier: 0 });
            NET_EMPLOYEE_SPREAD.forEach((dx, j) => {
                const employee = { x: institution.x + dx, y: NET_EMPLOYEE_Y + (j === 1 ? 26 : 0) };
                employees.push(employee);
                links.push({ d: drop(institution, employee), tier: 1 });
            });
        });
        return { links, institutions, employees };
    }, []);
    const root = NET_ROOT;

    return (
        <div ref={ref} className="relative h-full w-full" aria-hidden="true">
            <div className="lg-org absolute left-0 top-0" style={{ width: NET_W, height: H, transform: `translate(${fit.left}px, ${fit.top}px) scale(${fit.scale})`, transformOrigin: '0 0' }}>
                <svg viewBox={`0 0 ${NET_W} ${H}`} width={NET_W} height={H} className="absolute inset-0 overflow-visible">
                    <defs>
                        <linearGradient id={linkGradient} x1="0" y1="0" x2="0" y2={H} gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stopColor="#ffb08a" stopOpacity="0.55" />
                            <stop offset="45%" stopColor="#93c5fd" stopOpacity="0.4" />
                            <stop offset="100%" stopColor="#93c5fd" stopOpacity="0.15" />
                        </linearGradient>
                        <radialGradient id={rootGlow}>
                            <stop offset="0%" stopColor="#ffb08a" stopOpacity="0.9" />
                            <stop offset="100%" stopColor="#f76b3c" stopOpacity="0" />
                        </radialGradient>
                    </defs>

                    {/* Guides from each caption to its tier. */}
                    {[root.y, NET_INSTITUTION_Y, NET_EMPLOYEE_Y + 13].map((y) => (
                        <line key={y} x1={158} y1={y} x2={176} y2={y} className="lg-guide" />
                    ))}

                    {/* Links draw in once, then carry a wave of records down, one tier per second. */}
                    {net.links.map((l, i) => (
                        <g key={i}>
                            <path d={l.d} pathLength={100} className={`lg-link lg-link-${l.tier}`} stroke={`url(#${linkGradient})`} />
                            <path d={l.d} pathLength={100} className={`lg-wave lg-wave-${l.tier}`} />
                        </g>
                    ))}

                    {net.employees.map((e, i) => (
                        <g key={`e${i}`} className="lg-node" style={{ animationDelay: `${0.55 + i * 0.03}s` }}>
                            <circle cx={e.x} cy={e.y} r={11} className="lg-hit lg-hit-1b" />
                            <circle cx={e.x} cy={e.y} r={5} fill="#e0ecff" />
                        </g>
                    ))}

                    {net.institutions.map((p, i) => (
                        <g key={`i${i}`} className="lg-node" style={{ animationDelay: `${0.35 + i * 0.06}s` }}>
                            <circle cx={p.x} cy={p.y} r={22} className="lg-hit lg-hit-1" />
                            <rect x={p.x - 13} y={p.y - 13} width={26} height={26} rx={7} fill="#1d3aa6" stroke="#93c5fd" strokeWidth={1.4} />
                            <path d={`M${p.x - 6} ${p.y + 6}v-8m6 8v-8m6 8v-8m-14-2 8-4 8 4`} stroke="#e0ecff" strokeWidth={1.4} fill="none" strokeLinecap="round" strokeLinejoin="round" />
                        </g>
                    ))}

                    <g className="lg-node">
                        <circle cx={root.x} cy={root.y} r={58} fill={`url(#${rootGlow})`} className="lg-root-glow" />
                        <circle cx={root.x} cy={root.y} r={32} className="lg-hit lg-hit-0" />
                        <circle cx={root.x} cy={root.y} r={24} fill="#f76b3c" />
                        <path d={`M${root.x - 10} ${root.y + 9}h20M${root.x - 7} ${root.y + 9}v-10m7 10v-10m7 10v-10M${root.x - 11} ${root.y - 3}l11-8 11 8`} stroke="#fff" strokeWidth={1.9} fill="none" strokeLinecap="round" strokeLinejoin="round" />
                    </g>
                </svg>

                {/* What each tier is, in words, level with it. */}
                <Caption x={0} y={root.y - 26} width={150} step={findStep(steps, 'city')} tier={0} />
                <Caption x={0} y={NET_INSTITUTION_Y - 26} width={150} step={findStep(steps, 'institutions')} tier={1} />
                <Caption x={0} y={NET_EMPLOYEE_Y - 13} width={150} step={findStep(steps, 'employees')} tier={2} />
                {/* The selected employee, marked, and the static anchor FlowBridge starts from. */}
                <div data-flow-out className="absolute h-px w-px" style={{ left: NET_SELECTED.x, top: NET_SELECTED.y }} />
                <svg className="absolute inset-0 overflow-visible" width={NET_W} height={H}>
                    <circle cx={NET_SELECTED.x} cy={NET_SELECTED.y} r={16} className="lg-plug-ring lg-selected-ring" />
                    <circle cx={NET_SELECTED.x} cy={NET_SELECTED.y} r={7} className="lg-plug-core" />
                </svg>
            </div>
        </div>
    );
}

// ── Right: the employee ID card, with its services in orbit ─────────────────

const SERVICE_ICONS: Record<string, string> = {
    cafeteria: 'M6 3v6a2 2 0 0 0 4 0V3M8 3v18M16 3c-1.7 0-3 2.2-3 5s1.3 4 3 4v9m0-18v18',
    transport: 'M6 17h12M6 17a2 2 0 1 1-4 0V6a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v11a2 2 0 1 1-4 0M2 11h20M7 7h10',
    performance: 'M3 17l6-6 4 4 8-8M15 7h6v6',
    grievance: 'M8 10h8m-8 4h5m-7 6 3-3h9a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v7a3 3 0 0 0 3 3',
    activity: 'M8 3v3m8-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm4 9 2 2 4-4',
    reports: 'M4 20V10m6 10V4m6 16v-7m6 7H2',
};

/** A decorative QR block, the same pattern on every render. */
function QrGlyph() {
    const cells = useMemo(() => {
        const out: Pt[] = [];
        let seed = 7;
        const inFinder = (x: number, y: number) => [[0, 0], [14, 0], [0, 14]].some(([ox, oy]) => x >= ox && x < ox + 7 && y >= oy && y < oy + 7);
        for (let y = 0; y < 21; y++) {
            for (let x = 0; x < 21; x++) {
                if (inFinder(x, y)) continue;
                seed = (seed * 9301 + 49297) % 233280;
                if (seed / 233280 > 0.52) out.push({ x, y });
            }
        }
        return out;
    }, []);
    return (
        <svg viewBox="-0.5 -0.5 22 22" className="h-full w-full text-[#0a1230]" shapeRendering="crispEdges" aria-hidden="true">
            {cells.map((c) => <rect key={`${c.x}-${c.y}`} x={c.x} y={c.y} width={1} height={1} fill="currentColor" />)}
            {[[0, 0], [14, 0], [0, 14]].map(([ox, oy]) => (
                <g key={`${ox}-${oy}`}>
                    <rect x={ox} y={oy} width={7} height={7} fill="none" stroke="currentColor" strokeWidth={1} />
                    <rect x={ox + 2} y={oy + 2} width={3} height={3} fill="currentColor" />
                </g>
            ))}
        </svg>
    );
}

const ORBIT_W_DESIGN = 480;
const ORBIT_W = ORBIT_W_DESIGN;
/** The card face is laid out at 280 × 176 and scaled up as a whole, so its contents stay in proportion. */
const CARD_FACE = { w: 280, h: 176 };
const CARD_SCALE = 420 / CARD_FACE.w;
const CARD = { w: Math.round(CARD_FACE.w * CARD_SCALE), h: Math.round(CARD_FACE.h * CARD_SCALE) };
const CARD_POS = { x: (ORBIT_W_DESIGN - CARD.w) / 2, y: MID - CARD.h / 2 };
const CARD_CENTER: Pt = { x: CARD_POS.x + CARD.w / 2, y: MID };
const ORBIT = { rx: 232, ry: 165 };

export function IdentityOrbit({ services, brandName, logo, cardTitle, verifiedLabel, motion, photos = [] }: {
    steps: FlowStep[];
    services: FlowService[];
    brandName: string;
    logo: ReactNode;
    cardTitle: string;
    verifiedLabel: string;
    motion: boolean;
    /** Sample photos an administrator uploaded for this card (System Settings), never registry photos. */
    photos?: string[];
}) {
    const { ref, fit } = useFit(ORBIT_W, false);
    const chipRefs = useRef<(HTMLDivElement | null)[]>([]);

    // With several photos, the next one crossfades in just before each second scan, so every scan
    // verifies a new card. The first swap waits for the first scan (clock start 1.6 s + 3 s).
    const [photoIndex, setPhotoIndex] = useState(0);
    useEffect(() => {
        if (!motion || photos.length < 2) return;
        let interval = 0;
        const first = window.setTimeout(() => {
            setPhotoIndex((i) => (i + 1) % photos.length);
            interval = window.setInterval(() => setPhotoIndex((i) => (i + 1) % photos.length), 8000);
        }, 8400);
        return () => { window.clearTimeout(first); window.clearInterval(interval); };
    }, [motion, photos.length]);

    // Services orbit the card in depth: behind it at the top of the ellipse, in front at the bottom.
    useEffect(() => {
        const place = (time: number) => {
            chipRefs.current.forEach((chip, i) => {
                if (!chip) return;
                const angle = (i / services.length) * Math.PI * 2 + time * 0.00018;
                const depth = (Math.sin(angle) + 1) / 2; // 0 back … 1 front
                const x = CARD_CENTER.x + Math.cos(angle) * ORBIT.rx;
                const y = CARD_CENTER.y + Math.sin(angle) * ORBIT.ry;
                chip.style.transform = `translate3d(${x.toFixed(1)}px, ${y.toFixed(1)}px, 0) translate(-50%, -50%) scale(${(0.8 + depth * 0.28).toFixed(3)})`;
                chip.style.opacity = (0.4 + depth * 0.6).toFixed(3);
                chip.style.zIndex = depth > 0.5 ? '30' : '5';
            });
        };
        if (!motion) {
            place(0);
            return;
        }
        let frame = 0;
        const tick = (time: number) => {
            place(time);
            frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [motion, services.length]);

    // The card answers the cursor. Anywhere on the stage it leans gently toward it; over the card it
    // tilts fully under the pointer, lifts, the glare follows the pointer and the photo and QR shift
    // in depth. Eased each frame, and restyled only while something is still moving.
    const cardRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const card = cardRef.current;
        if (!motion || !card) return;
        let targetX = 0, targetY = 0, targetLift = 0, tiltX = 0, tiltY = 0, lift = 0, frame = 0;
        const stage = card.closest('[data-login-stage]') ?? document.body;
        const onMove = (event: Event) => {
            const e = event as PointerEvent;
            const rect = card.getBoundingClientRect();
            const cx = rect.left + rect.width / 2;
            const cy = rect.top + rect.height / 2;
            const over = e.clientX >= rect.left - 12 && e.clientX <= rect.right + 12 && e.clientY >= rect.top - 12 && e.clientY <= rect.bottom + 12;
            if (over) {
                targetX = Math.max(-1, Math.min(1, (e.clientX - cx) / (rect.width / 2)));
                targetY = Math.max(-1, Math.min(1, (e.clientY - cy) / (rect.height / 2)));
                targetLift = 1;
            } else {
                const reach = Math.max(window.innerWidth, 1) / 2;
                targetX = Math.max(-0.35, Math.min(0.35, (e.clientX - cx) / reach));
                targetY = Math.max(-0.35, Math.min(0.35, (e.clientY - cy) / (reach * 0.7)));
                targetLift = 0;
            }
        };
        const onLeave = () => { targetX = 0; targetY = 0; targetLift = 0; };
        const tick = () => {
            if (Math.abs(targetX - tiltX) > 0.001 || Math.abs(targetY - tiltY) > 0.001 || Math.abs(targetLift - lift) > 0.001) {
                tiltX += (targetX - tiltX) * 0.1;
                tiltY += (targetY - tiltY) * 0.1;
                lift += (targetLift - lift) * 0.12;
                card.style.setProperty('--lg-tilt-x', `${(-tiltY * 16).toFixed(2)}deg`);
                card.style.setProperty('--lg-tilt-y', `${(tiltX * 20).toFixed(2)}deg`);
                card.style.setProperty('--lg-glare-x', `${(50 + tiltX * 50).toFixed(1)}%`);
                card.style.setProperty('--lg-glare-y', `${(50 + tiltY * 50).toFixed(1)}%`);
                card.style.setProperty('--lg-lift', (1 + lift * 0.06).toFixed(4));
                card.style.setProperty('--lg-glare-o', (0.55 + lift * 0.45).toFixed(3));
                card.style.setProperty('--lg-px', tiltX.toFixed(3));
                card.style.setProperty('--lg-py', tiltY.toFixed(3));
            }
            frame = requestAnimationFrame(tick);
        };
        stage.addEventListener('pointermove', onMove);
        stage.addEventListener('pointerleave', onLeave);
        frame = requestAnimationFrame(tick);
        return () => {
            cancelAnimationFrame(frame);
            stage.removeEventListener('pointermove', onMove);
            stage.removeEventListener('pointerleave', onLeave);
        };
    }, [motion]);

    return (
        <div ref={ref} className="relative h-full w-full" aria-hidden="true">
            <div className="lg-org absolute left-0 top-0" style={{ width: ORBIT_W, height: H, transform: `translate(${fit.left}px, ${fit.top}px) scale(${fit.scale})`, transformOrigin: '0 0' }}>
                <svg viewBox={`0 0 ${ORBIT_W} ${H}`} width={ORBIT_W} height={H} className="absolute inset-0 overflow-visible">
                    <ellipse cx={CARD_CENTER.x} cy={CARD_CENTER.y} rx={ORBIT.rx} ry={ORBIT.ry} className="lg-orbit-track" />
                </svg>

                {/* Static anchor for FlowBridge: where the single line from the employees arrives. */}
                <div data-flow-in className="absolute h-px w-px" style={{ left: CARD_POS.x, top: MID }} />

                {/* The employee's ID card: scanned, then verified; tilts toward the pointer. */}
                <div className="lg-idc-float absolute z-20" style={{ left: CARD_POS.x, top: CARD_POS.y, width: CARD.w, height: CARD.h }}>
                    <div style={{ width: CARD_FACE.w, height: CARD_FACE.h, transform: `scale(${CARD_SCALE})`, transformOrigin: '0 0', perspective: '900px' }}>
                    <div ref={cardRef} className="lg-idc relative h-full w-full overflow-hidden rounded-[16px] p-4 text-white">
                        <div className="lg-idc-holo pointer-events-none absolute inset-0" />
                        <div className="lg-idc-glare pointer-events-none absolute inset-0" />
                        <div className="relative flex items-start justify-between">
                            <div className="flex items-center gap-2">
                                <div className="flex h-9 w-9 items-center justify-center">{logo}</div>
                                <div className="leading-tight">
                                    <p className="text-[14px] font-bold">{brandName}</p>
                                    <p className="text-[9px] uppercase tracking-[0.14em] text-white/65">{cardTitle}</p>
                                </div>
                            </div>
                            <div className="lg-idc-gold h-6 w-8 rounded-md" />
                        </div>
                        <div className="relative mt-3 flex gap-3">
                            <div className="lg-idc-px-1 relative flex h-[80px] w-[64px] shrink-0 items-end justify-center overflow-hidden rounded-lg bg-linear-to-b from-white/25 to-white/5 ring-1 ring-white/25">
                                {photos.length > 0 ? photos.map((src, i) => (
                                    <img
                                        key={src}
                                        src={src}
                                        alt=""
                                        width={64}
                                        height={80}
                                        decoding="async"
                                        loading={i === 0 ? 'eager' : 'lazy'}
                                        className="lg-idc-photo absolute inset-0 h-full w-full object-cover"
                                        style={{ opacity: i === photoIndex ? 1 : 0 }}
                                    />
                                )) : (
                                    <svg viewBox="0 0 40 48" className="h-[58px] w-[48px] text-white/70" fill="currentColor" aria-hidden="true">
                                        <circle cx="20" cy="16" r="9" />
                                        <path d="M2 48c1.5-11 9-17 18-17s16.5 6 18 17Z" />
                                    </svg>
                                )}
                            </div>
                            <div className="min-w-0 flex-1 space-y-1.5 pt-1">
                                <div className="lg-idc-line h-2 w-[86%] rounded-full" />
                                <div className="lg-idc-line h-1.5 w-[62%] rounded-full" />
                                <div className="lg-idc-line h-1.5 w-[72%] rounded-full" />
                                <div className="lg-idc-line h-1.5 w-[44%] rounded-full" />
                            </div>
                            <div className="lg-idc-px-2 h-[66px] w-[66px] shrink-0 self-end rounded-md bg-white p-1">
                                <QrGlyph />
                            </div>
                        </div>
                        <div className="absolute inset-x-0 bottom-0 h-1.5 bg-[linear-gradient(90deg,#f76b3c,#fbbf24,#93c5fd)]" />
                        <div className="lg-idc-scan pointer-events-none absolute inset-x-0 top-0 h-12" />
                        <div className="lg-idc-verified absolute bottom-4 left-[80px] flex items-center gap-1 rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-semibold text-white shadow-[0_6px_18px_-6px_rgba(16,185,129,0.9)]">
                            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3} aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" d="m5 12.5 4.5 4.5L19 7.5" />
                            </svg>
                            {verifiedLabel}
                        </div>
                    </div>
                    </div>
                </div>

                {services.map((service, i) => (
                    <div
                        key={service.key}
                        ref={(el) => { chipRefs.current[i] = el; }}
                        className="lg-orbit-chip absolute left-0 top-0 flex items-center gap-2 whitespace-nowrap rounded-full border border-white/15 bg-[#111c4d] px-3.5 py-2 text-[13px] font-medium text-white/90 shadow-[0_10px_26px_-10px_rgba(0,0,0,0.7)]"
                    >
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-white/10">
                            <svg className="h-3.5 w-3.5 text-orange-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d={SERVICE_ICONS[service.key] ?? SERVICE_ICONS.reports} />
                            </svg>
                        </span>
                        {service.label}
                    </div>
                ))}

            </div>
        </div>
    );
}

/**
 * The single line from the selected employee (NetworkFlow) to the ID card (IdentityOrbit).
 * The two canvases sit in different columns and scale independently, so the line is drawn in the
 * row's own pixels between the two static anchors, re-measured whenever the row resizes. It carries
 * the same wave as the network: it leaves the employee as the record arrives there.
 */
export function FlowBridge() {
    const ref = useRef<SVGSVGElement>(null);
    const [d, setD] = useState('');
    useLayoutEffect(() => {
        const svg = ref.current;
        const row = svg?.parentElement;
        if (!svg || !row) return;
        const measure = () => {
            const from = row.querySelector('[data-flow-out]')?.getBoundingClientRect();
            const to = row.querySelector('[data-flow-in]')?.getBoundingClientRect();
            const origin = row.getBoundingClientRect();
            if (!from || !to || from.width === 0 && from.left === 0) { setD(''); return; }
            const a = { x: from.left - origin.left, y: from.top - origin.top };
            const b = { x: to.left - origin.left, y: to.top - origin.top };
            const bend = Math.max(60, (b.x - a.x) * 0.55);
            setD(`M${a.x.toFixed(1)} ${a.y.toFixed(1)} C${(a.x + bend).toFixed(1)} ${a.y.toFixed(1)} ${(b.x - bend).toFixed(1)} ${b.y.toFixed(1)} ${b.x.toFixed(1)} ${b.y.toFixed(1)}`);
        };
        // The canvases lay themselves out in a layout effect of their own; measure after them.
        const frame = requestAnimationFrame(measure);
        const observer = new ResizeObserver(() => requestAnimationFrame(measure));
        observer.observe(row);
        return () => { cancelAnimationFrame(frame); observer.disconnect(); };
    }, []);

    return (
        <svg ref={ref} className="lg-org pointer-events-none absolute inset-0 z-10 hidden h-full w-full overflow-visible lg:block" aria-hidden="true">
            {d && (
                <>
                    <path d={d} pathLength={100} className="lg-link lg-link-3 lg-bridge" />
                    <path d={d} pathLength={100} className="lg-wave lg-wave-bridge" />
                </>
            )}
        </svg>
    );
}

/** Phones and tablets: the same journey as a left-to-right stepper that runs into the form. */
export function FlowStepper({ steps }: { steps: FlowStep[] }) {
    return (
        <div className="lg-stepper" aria-hidden="true">
            <div className="mx-auto flex max-w-md items-start justify-between">
                {steps.map((s, i) => (
                    <div key={s.key} className="relative flex w-16 flex-col items-center text-center">
                        {i < steps.length - 1 && <span className="lg-stepper-line absolute left-[calc(50%+18px)] top-[18px] h-px w-[calc(100%-36px)]" style={{ animationDelay: `${i * 0.5}s` }} />}
                        <span className="lg-stepper-dot relative flex h-9 w-9 items-center justify-center rounded-full" style={{ animationDelay: `${i * 0.5}s` }}>{i + 1}</span>
                        <span className="mt-1.5 text-[10px] leading-tight text-white/70">{s.title}</span>
                    </div>
                ))}
            </div>
            <div className="lg-stepper-drop mx-auto mt-3 h-7 w-px" />
        </div>
    );
}
