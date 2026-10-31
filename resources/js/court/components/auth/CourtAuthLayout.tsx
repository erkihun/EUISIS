import type { CSSProperties, ReactNode } from 'react';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';

/**
 * The court's sign-in look: a court-ink panel with the scales of justice, the
 * tribunal's name and its case path from filing to appeal, beside a parchment
 * work surface. Its colours are local to these pages (court ink, brass,
 * parchment), so the shell's appearance settings stay those of the system.
 * The panel moves (court.css, "Court sign-in"): the seal turns and its scales
 * settle into balance, a case runs down the path and the gavel falls on the
 * decision, motes rise through the light and the colonnade rises at the foot.
 */
const COURT_COLOURS = {
    '--court-ink': '#0c1a31',
    '--court-navy': '#16305a',
    '--court-navy-hover': '#1d3c70',
    '--court-brass': '#c8a14a',
    '--court-brass-soft': '#e8d6a2',
    '--court-parchment': '#f6f1e6',
} as CSSProperties;

const STEPS = ['filing', 'review', 'hearing', 'decision', 'appeal'] as const;

/** Brass motes rising through the panel: where each starts, its size, pace and drift. */
const MOTES = [
    { left: '6%', size: 3, duration: 17, delay: 0.4, drift: 22 },
    { left: '14%', size: 2, duration: 21, delay: 6, drift: -14 },
    { left: '23%', size: 2, duration: 15, delay: 2.5, drift: 30 },
    { left: '34%', size: 3, duration: 19, delay: 9, drift: -26 },
    { left: '45%', size: 2, duration: 23, delay: 4, drift: 18 },
    { left: '57%', size: 3, duration: 16, delay: 11, drift: -20 },
    { left: '66%', size: 2, duration: 20, delay: 1.5, drift: 28 },
    { left: '77%', size: 2, duration: 18, delay: 7.5, drift: -16 },
    { left: '88%', size: 3, duration: 22, delay: 3.2, drift: 12 },
];

/**
 * The scales of justice, drawn in the court's brass. With `motion` (the
 * sign-in panel) they draw themselves in, swing, settle level and then sway
 * a little: the beam turns on its pivot while the pans hang straight.
 */
export function CourtEmblem({ className = 'h-12 w-12', motion = false }: { className?: string; motion?: boolean }) {
    const animate = (name: string) => (motion ? name : undefined);

    return (
        <svg viewBox="0 0 64 64" fill="none" aria-hidden="true" className={className}>
            <circle cx="32" cy="32" r="30" stroke="currentColor" strokeWidth="1.5" opacity="0.55" pathLength={100} className={animate('court-draw')} />
            <circle cx="32" cy="32" r="26" stroke="currentColor" strokeWidth="0.75" opacity="0.35" pathLength={100} className={animate('court-draw court-delay-1')} />
            <path d="M32 14v34M22 50h20M26 50l6-4 6 4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" pathLength={100} className={animate('court-draw court-delay-2')} />
            <circle cx="32" cy="13" r="2" fill="currentColor" className={animate('court-fade court-delay-3')} />
            <g className={animate('court-beam')}>
                <path d="M17 21h30" stroke="currentColor" strokeWidth="2" strokeLinecap="round" pathLength={100} className={animate('court-draw court-delay-3')} />
                <g className={animate('court-pan court-pan-left')}>
                    <path d="M19 21l-5 11M19 21l5 11" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" pathLength={100} className={animate('court-draw court-delay-4')} />
                    <path d="M12.5 32a6.5 3.2 0 0 0 13 0z" fill="currentColor" opacity="0.9" className={animate('court-fade court-delay-5')} />
                </g>
                <g className={animate('court-pan court-pan-right')}>
                    <path d="M45 21l-5 11M45 21l5 11" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" pathLength={100} className={animate('court-draw court-delay-4')} />
                    <path d="M38.5 32a6.5 3.2 0 0 0 13 0z" fill="currentColor" opacity="0.9" className={animate('court-fade court-delay-5')} />
                </g>
            </g>
        </svg>
    );
}

/** The court's seal, large and faint behind the panel's heading: rings turning about the scales. */
function CourtSeal() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute -right-28 top-4 hidden h-[30rem] w-[30rem] text-(--court-brass) lg:block">
            <div className="court-glow absolute left-[15%] top-[15%] h-[70%] w-[70%] rounded-full" />
            <svg viewBox="0 0 200 200" fill="none" className="court-orbit absolute inset-0 h-full w-full opacity-30">
                <circle cx="100" cy="100" r="97" stroke="currentColor" strokeWidth="1.2" strokeDasharray="0.5 6" strokeLinecap="round" />
                <circle cx="100" cy="100" r="91" stroke="currentColor" strokeWidth="0.4" />
            </svg>
            <svg viewBox="0 0 200 200" fill="none" className="court-orbit court-orbit-reverse absolute left-[8%] top-[8%] h-[84%] w-[84%] opacity-25">
                <circle cx="100" cy="100" r="96" stroke="currentColor" strokeWidth="5" strokeDasharray="0.6 9.45" />
                <circle cx="100" cy="100" r="88" stroke="currentColor" strokeWidth="0.4" strokeDasharray="14 6" />
            </svg>
            <CourtEmblem motion className="absolute left-[19%] top-[19%] h-[62%] w-[62%] opacity-30" />
        </div>
    );
}

/** The gavel by the decision: it falls when the case reaches that stage, and the block rings. */
function CourtGavel() {
    return (
        <span aria-hidden="true" className="relative ml-2 inline-block h-4 w-5 align-[-3px] text-(--court-brass)">
            <svg viewBox="0 0 20 16" fill="none" className="absolute inset-0 h-full w-full overflow-visible">
                <g className="court-gavel">
                    <path d="M7 8.5 17 2.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                    <rect x="4" y="3.5" width="4" height="9" rx="1" transform="rotate(-28.6 6 8)" fill="currentColor" />
                </g>
                <path d="M2 14.5h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            </svg>
            <span className="court-ripple absolute bottom-0 left-[18%] h-1.5 w-3 rounded-full border border-(--court-brass-soft)" />
        </span>
    );
}

/** A colonnade, faint, across the foot of the court panel; its columns rise in turn. */
function Colonnade() {
    return (
        <svg viewBox="0 0 480 160" preserveAspectRatio="xMidYMax slice" aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-0 h-56 w-full text-(--court-brass) opacity-[0.09]">
            <g className="court-pediment">
                <path d="M20 40 L240 4 L460 40 Z" fill="currentColor" />
                <rect x="24" y="44" width="432" height="8" fill="currentColor" />
            </g>
            {Array.from({ length: 8 }, (_, i) => 44 + i * 56).map((x, i) => (
                <g key={x} fill="currentColor" className="court-column" style={{ '--i': i } as CSSProperties}>
                    <rect x={x - 4} y="58" width="32" height="6" />
                    <rect x={x} y="64" width="24" height="80" />
                    <rect x={x - 4} y="144" width="32" height="6" />
                </g>
            ))}
            <rect x="12" y="150" width="456" height="10" fill="currentColor" />
        </svg>
    );
}

export default function CourtAuthLayout({ children }: { children: ReactNode }) {
    const { t, locale } = useLocale();
    const { brandNameEn, brandNameAm, officialNameEn, officialNameAm, logoUrl } = useBranding();
    const court = locale === 'am' ? (officialNameAm || brandNameAm) : (officialNameEn || brandNameEn);
    const text = (key: string) => t(`court.signIn.${key}`);

    return (
        <div style={COURT_COLOURS} className="court-stage flex min-h-screen flex-col bg-(--court-parchment) text-slate-900 lg:flex-row dark:bg-[#0a1324] dark:text-slate-100">
            {/* The court: name, emblem and the path of a case. On a phone, a band above the form. */}
            <aside className="relative overflow-hidden bg-(--court-ink) text-white lg:flex lg:w-[44%] lg:max-w-[640px] lg:flex-col lg:justify-between">
                <div aria-hidden="true" className="absolute inset-0 bg-[radial-gradient(120%_80%_at_0%_0%,rgba(200,161,74,0.16),transparent_55%),radial-gradient(90%_70%_at_100%_100%,rgba(22,48,90,0.9),transparent_60%)]" />
                <div aria-hidden="true" className="court-sweep pointer-events-none absolute -inset-y-1/4 left-0 hidden w-1/3 bg-linear-to-r from-transparent via-(--court-brass-soft)/10 to-transparent lg:block" />
                <div aria-hidden="true" className="pointer-events-none absolute inset-0 hidden overflow-hidden lg:block">
                    {MOTES.map((mote) => (
                        <span
                            key={mote.left}
                            className="court-mote absolute -bottom-2 rounded-full bg-(--court-brass-soft)"
                            style={{ left: mote.left, width: mote.size, height: mote.size, animationDuration: `${mote.duration}s`, animationDelay: `${mote.delay}s`, '--court-drift': `${mote.drift}px` } as CSSProperties}
                        />
                    ))}
                </div>
                <CourtSeal />
                <div aria-hidden="true" className="absolute inset-y-0 right-0 w-px bg-gradient-to-b from-transparent via-(--court-brass)/50 to-transparent" />

                <div className="relative flex items-center gap-4 px-6 py-5 sm:px-10 lg:pt-12">
                    {/* The system logo (System Settings); the scales without one. */}
                    {logoUrl ? (
                        <img src={logoUrl} alt="" decoding="async" className="login-rise h-10 w-auto max-w-[7.5rem] shrink-0 object-contain lg:h-12 lg:max-w-[9rem]" />
                    ) : (
                        <span className="text-(--court-brass)"><CourtEmblem motion className="h-12 w-12 lg:h-14 lg:w-14" /></span>
                    )}
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-(--court-brass-soft)">{text('eyebrow')}</p>
                        <p className="truncate text-base font-semibold leading-snug sm:text-lg">{court}</p>
                    </div>
                </div>

                <div className="relative hidden px-10 pb-10 lg:block">
                    <div className="mb-8 h-px w-16 bg-(--court-brass)" />
                    <h2 className="max-w-md text-3xl font-semibold leading-tight tracking-tight">{text('panelHeading')}</h2>
                    <p className="mt-3 max-w-md text-sm leading-relaxed text-slate-300">{text('panelText')}</p>

                    <ol className="relative mt-10 max-w-md space-y-5">
                        <span aria-hidden="true" className="absolute left-[15px] top-2 bottom-2 w-px bg-(--court-brass)/35" />
                        {/* A case on its way: a light runs down the path and each stage lights as it passes. */}
                        <span aria-hidden="true" className="absolute left-[14px] top-2 bottom-2 w-[3px] overflow-hidden">
                            <span className="court-trail absolute left-px top-0 h-full w-px bg-(--court-brass)" />
                            <span className="court-trace absolute inset-0">
                                <span className="absolute inset-x-0 bottom-0 h-14 rounded-full bg-linear-to-b from-transparent to-(--court-brass-soft)" />
                            </span>
                        </span>
                        {STEPS.map((step, index) => (
                            <li key={step} className="relative flex gap-4">
                                <span style={{ '--i': index } as CSSProperties} className="court-step relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-(--court-brass)/70 bg-(--court-ink) text-xs font-semibold text-(--court-brass)">{index + 1}</span>
                                <span className="pt-0.5">
                                    <span className="block text-sm font-semibold">{text(`steps.${step}.title`)}{step === 'decision' && <CourtGavel />}</span>
                                    <span className="block text-xs leading-relaxed text-slate-400">{text(`steps.${step}.text`)}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>

                <div className="relative hidden items-center gap-2 px-10 pb-10 text-xs text-slate-400 lg:flex">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" className="h-4 w-4 text-(--court-brass)"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12v9H6z" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" /></svg>
                    {text('securityNote')}
                </div>
                <Colonnade />
            </aside>

            {/* The work surface. */}
            <main className="relative flex flex-1 flex-col">
                <div className="flex justify-end gap-2 px-4 py-4 sm:px-8">
                    <LanguageSwitcher />
                    <ThemeToggle />
                </div>
                <div className="flex flex-1 items-start justify-center px-4 pb-12 pt-2 sm:items-center sm:px-8">
                    <div className="w-full max-w-[420px]">{children}</div>
                </div>
            </main>
        </div>
    );
}
