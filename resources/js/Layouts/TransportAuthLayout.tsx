import { AuthTopBar, SystemLogo, usePortalBranding } from '@/Components/ProviderPortal/AuthChrome';
import { PortalAccentProvider } from '@/Components/ProviderPortal/AuthFields';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { CSSProperties, PropsWithChildren } from 'react';

/** A faint street grid behind the route map. */
const MAP_GRID: CSSProperties = {
    backgroundImage: 'linear-gradient(rgba(125,211,252,0.6) 1px, transparent 1px), linear-gradient(90deg, rgba(125,211,252,0.6) 1px, transparent 1px)',
    backgroundSize: '44px 44px',
};

/** A dashed lane marking along the top of the card. */
const LANE: CSSProperties = {
    backgroundImage: 'repeating-linear-gradient(90deg, #0284c7 0 22px, transparent 22px 34px)',
};

const STOPS = [
    { label: 'providerPortal.transportStopScan', hint: 'providerPortal.transportStopScanHint' },
    { label: 'providerPortal.transportStopTrips', hint: 'providerPortal.transportStopTripsHint' },
    { label: 'providerPortal.transportStopManifests', hint: 'providerPortal.transportStopManifestsHint' },
    { label: 'providerPortal.transportStopSettlement', hint: 'providerPortal.transportStopSettlementHint' },
];

/** The transport provider portal's signed-out pages: a route map on the left, the card on the right. */
export default function TransportAuthLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { t } = useLocale();
    const { systemName, orgName } = usePortalBranding();

    return (
        <PortalAccentProvider value="sky">
            <div className="flex min-h-screen bg-slate-100 dark:bg-[#0a0f18]">
                <Head title={title} />

                {/* ── Route map ──────────────────────────────────────────── */}
                <div className="relative hidden w-[52%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                    <div className="absolute inset-0 bg-linear-to-br from-[#08162b] via-[#0b2240] to-[#073550]" />
                    <div className="pointer-events-none absolute inset-0 opacity-[0.06]" style={MAP_GRID} />
                    <div className="pointer-events-none absolute -top-32 -left-24 h-[460px] w-[460px] rounded-full bg-sky-500/20 blur-[120px]" />
                    <div className="pointer-events-none absolute -bottom-24 right-0 h-[340px] w-[340px] rounded-full bg-amber-400/10 blur-[100px]" />

                    <div className="relative z-10 flex h-full flex-col items-center justify-between px-10 py-10 text-center">
                        <div className="flex flex-col items-center gap-3">
                            <SystemLogo size="lg" onDark />
                            <p className="text-lg font-bold tracking-wide text-white">{systemName}</p>
                        </div>

                        <div className="flex w-full max-w-md flex-col items-center gap-7">
                            <div className="inline-flex items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-4 py-1.5">
                                <span className="h-1.5 w-1.5 rounded-full bg-sky-300 motion-safe:animate-pulse" />
                                <span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-sky-200">{t('providerPortal.transportPortalName')}</span>
                            </div>

                            <div className="space-y-3">
                                <h1 className="text-[2rem] font-bold leading-[1.2] text-white">{t('providerPortal.transportHeadline')}</h1>
                                <p className="text-sm leading-relaxed text-slate-300/80">{t('providerPortal.transportDescription')}</p>
                            </div>

                            {/* The portal's features as stops on one line. */}
                            <ol className="relative w-full rounded-2xl border border-white/10 bg-white/[0.04] px-6 py-6 text-left backdrop-blur-sm">
                                <span aria-hidden="true" className="absolute bottom-10 left-[2.15rem] top-10 w-1 rounded-full bg-linear-to-b from-sky-400 via-sky-400 to-amber-300" />
                                {STOPS.map(({ label, hint }, index) => (
                                    <li key={label} className="relative flex items-center gap-4 py-2.5">
                                        <span
                                            aria-hidden="true"
                                            className={`relative z-10 h-4 w-4 shrink-0 rounded-full border-[3px] bg-[#0b2240] ${index === STOPS.length - 1 ? 'border-amber-300' : 'border-sky-400'}`}
                                        />
                                        <span className="flex flex-col">
                                            <span className="text-[14px] font-semibold text-white">{t(label)}</span>
                                            <span className="text-xs text-slate-400">{t(hint)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </div>

                        <div className="flex w-full items-center gap-3">
                            <div className="h-px flex-1 bg-white/6" />
                            <p className="text-[11px] text-slate-400">{t('auth.footerGov')} · {orgName}</p>
                            <div className="h-px flex-1 bg-white/6" />
                        </div>
                    </div>
                </div>

                {/* ── Sign-in card ───────────────────────────────────────── */}
                <div className="flex flex-1 flex-col">
                    <AuthTopBar />

                    <div className="flex flex-1 items-center justify-center px-6 pb-10">
                        <div className="w-full max-w-[400px]">
                            <div className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface) shadow-xl shadow-sky-900/5">
                                <div className="h-1 bg-slate-800 dark:bg-slate-700" aria-hidden="true">
                                    <div className="h-full" style={LANE} />
                                </div>
                                <div className="px-8 py-8">{children}</div>
                            </div>

                            <p className="mt-5 text-center text-[11px] text-(--app-muted-foreground)">
                                {t('providerPortal.transportPortalName')} · {t('providerPortal.authorizedOnly')}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </PortalAccentProvider>
    );
}
