import { AuthTopBar, SystemLogo, usePortalBranding } from '@/Components/ProviderPortal/AuthChrome';
import { PortalAccentProvider } from '@/Components/ProviderPortal/AuthFields';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import type { CSSProperties, PropsWithChildren } from 'react';

/** The striped café awning: orange and cream bands with a scalloped edge. */
const AWNING_STRIPES: CSSProperties = {
    backgroundImage: 'repeating-linear-gradient(90deg, #ea580c 0 28px, #fff7ed 28px 56px)',
};
const AWNING_SCALLOPS: CSSProperties = {
    backgroundImage: 'radial-gradient(circle at 14px 0, #ea580c 13px, transparent 14px), radial-gradient(circle at 42px 0, #fff7ed 13px, transparent 14px)',
    backgroundSize: '56px 14px',
    backgroundRepeat: 'repeat-x',
};

/** Faint cups and forks across the espresso panel, like a café's wallpaper. */
const WALLPAPER: CSSProperties = {
    backgroundImage:
        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='72' height='72' fill='none' stroke='%23fde68a' stroke-width='1.4' stroke-linecap='round'%3E%3Cpath d='M10 22h14v5a7 7 0 0 1-7 7 7 7 0 0 1-7-7v-5Zm14 2h2a3 3 0 0 1 0 6h-2'/%3E%3Cpath d='M52 44v18M48 44v6a4 4 0 0 0 8 0v-6'/%3E%3C/svg%3E\")",
    backgroundSize: '72px 72px',
};

const MENU = [
    { item: 'providerPortal.cafeteriaMenuScan', note: 'providerPortal.cafeteriaMenuScanNote' },
    { item: 'providerPortal.cafeteriaMenuMenus', note: 'providerPortal.cafeteriaMenuMenusNote' },
    { item: 'providerPortal.cafeteriaMenuOrders', note: 'providerPortal.cafeteriaMenuOrdersNote' },
    { item: 'providerPortal.cafeteriaMenuLedger', note: 'providerPortal.cafeteriaMenuLedgerNote' },
];

function Awning({ className = '' }: { className?: string }) {
    return (
        <div aria-hidden="true" className={className}>
            <div className="h-5" style={AWNING_STRIPES} />
            <div className="h-3.5" style={AWNING_SCALLOPS} />
        </div>
    );
}

/** A cup with three wisps of steam; the steam breathes unless the visitor prefers less motion. */
function SteamingCup({ className = '' }: { className?: string }) {
    return (
        <svg viewBox="0 0 96 96" fill="none" aria-hidden="true" className={className}>
            <g stroke="#fde68a" strokeWidth="3" strokeLinecap="round" className="motion-safe:animate-pulse">
                <path d="M34 30c-4-5 4-8 0-14" />
                <path d="M48 28c-4-5 4-8 0-14" />
                <path d="M62 30c-4-5 4-8 0-14" />
            </g>
            <path d="M18 40h52v14a22 22 0 0 1-22 22h-8a22 22 0 0 1-22-22V40Z" fill="#fff7ed" />
            <path d="M70 46h4a9 9 0 0 1 0 18h-5" stroke="#fff7ed" strokeWidth="5" strokeLinecap="round" />
            <path d="M18 40h52v6H18z" fill="#fdba74" />
            <ellipse cx="44" cy="82" rx="34" ry="5" fill="#ea580c" opacity=".55" />
        </svg>
    );
}

/** The cafeteria provider portal's signed-out pages: a café front on the left, the card on cream on the right. */
export default function CafeteriaAuthLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { t } = useLocale();
    const { systemName, orgName } = usePortalBranding();

    return (
        <PortalAccentProvider value="cafeteria">
            <div className="flex min-h-screen bg-[#fbf6ee] dark:bg-[#140f0b]">
                <Head title={title} />

                {/* ── Café front ─────────────────────────────────────────── */}
                <div className="relative hidden w-[52%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                    <div className="absolute inset-0 bg-linear-to-br from-[#3d2617] via-[#2a1a10] to-[#160d07]" />
                    <div className="pointer-events-none absolute inset-0 opacity-[0.07]" style={WALLPAPER} />
                    <div className="pointer-events-none absolute -top-24 left-1/4 h-[420px] w-[420px] rounded-full bg-amber-400/20 blur-[120px]" />
                    <div className="pointer-events-none absolute -bottom-28 -right-16 h-[360px] w-[360px] rounded-full bg-orange-600/25 blur-[110px]" />

                    <Awning className="relative z-10 drop-shadow-[0_6px_10px_rgba(0,0,0,0.35)]" />

                    <div className="relative z-10 flex flex-1 flex-col items-center justify-between px-10 pb-10 pt-8 text-center">
                        <div className="flex flex-col items-center gap-3">
                            <SystemLogo size="lg" onDark />
                            <p className="text-lg font-bold tracking-wide text-[#fff7ed]">{systemName}</p>
                        </div>

                        <div className="flex w-full max-w-md flex-col items-center gap-7">
                            <div className="inline-flex items-center gap-2 rounded-full border border-amber-300/30 bg-amber-300/10 px-4 py-1.5">
                                <span className="h-1.5 w-1.5 rounded-full bg-amber-300 motion-safe:animate-pulse" />
                                <span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-amber-200">{t('providerPortal.cafeteriaPortalName')}</span>
                            </div>

                            <div className="space-y-3">
                                <h1 className="font-serif text-[2.4rem] leading-[1.15] text-[#fff7ed]">{t('providerPortal.cafeteriaHeadline')}</h1>
                                <p className="text-sm leading-relaxed text-[#e9d8c4]/80">{t('providerPortal.cafeteriaDescription')}</p>
                            </div>

                            {/* The chalkboard menu: what the portal serves. */}
                            <div className="relative w-full rounded-2xl border-[6px] border-[#7a5434] bg-[#1f2b24] px-7 pb-6 pt-7 text-left shadow-2xl shadow-black/40">
                                <SteamingCup className="absolute -top-12 -right-6 h-24 w-24 drop-shadow-xl" />
                                <p className="mb-1 font-serif text-xl italic text-[#fdf3dc]">{t('providerPortal.cafeteriaMenuTitle')}</p>
                                <div className="mb-4 h-px w-16 bg-amber-300/50" aria-hidden="true" />
                                <ul className="space-y-3">
                                    {MENU.map(({ item, note }) => (
                                        <li key={item} className="flex items-baseline gap-2 text-[14px]">
                                            <span className="text-[#fdf3dc]">{t(item)}</span>
                                            <span aria-hidden="true" className="flex-1 -translate-y-1 border-b border-dotted border-[#fdf3dc]/30" />
                                            <span className="text-xs font-medium text-amber-300">{t(note)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>

                        <div className="flex w-full items-center gap-3">
                            <div className="h-px flex-1 bg-amber-100/10" />
                            <p className="text-[11px] text-[#e9d8c4]/60">{t('auth.footerGov')} · {orgName}</p>
                            <div className="h-px flex-1 bg-amber-100/10" />
                        </div>
                    </div>
                </div>

                {/* ── Counter: the sign-in card ──────────────────────────── */}
                <div className="flex flex-1 flex-col">
                    <Awning className="lg:hidden" />
                    <AuthTopBar />

                    <div className="flex flex-1 items-center justify-center px-6 pb-10">
                        <div className="w-full max-w-[400px]">
                            <div className="overflow-hidden rounded-panel border border-orange-200/80 bg-white shadow-xl shadow-orange-900/5 dark:border-[#3a2a1f] dark:bg-[#1c1510]">
                                <div className="h-1.5" style={AWNING_STRIPES} aria-hidden="true" />
                                <div className="px-8 py-8">{children}</div>
                            </div>

                            <p className="mt-5 text-center text-[11px] text-[#8a6f58] dark:text-[#b59c86]">
                                {t('providerPortal.cafeteriaPortalName')} · {t('providerPortal.authorizedOnly')}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </PortalAccentProvider>
    );
}
