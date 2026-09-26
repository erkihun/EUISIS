import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { Head } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

const FEATURES = [
    { key: 'providerPortal.featureScan', icon: 'M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5ZM6.75 6.75h.75v.75h-.75v-.75ZM6.75 16.5h.75v.75h-.75v-.75ZM16.5 6.75h.75v.75h-.75v-.75ZM13.5 13.5h.75v.75h-.75v-.75ZM13.5 19.5h.75v.75h-.75v-.75ZM19.5 13.5h.75v.75h-.75v-.75ZM19.5 19.5h.75v.75h-.75v-.75ZM16.5 16.5h.75v.75h-.75v-.75Z' },
    { key: 'providerPortal.featureCafeteria', icon: 'M12 8.25v-1.5m0 1.5c-1.355 0-2.697.056-4.024.166C6.845 8.51 6 9.473 6 10.608v2.513m6-4.871c1.355 0 2.697.056 4.024.166C17.155 8.51 18 9.473 18 10.608v2.513M15 8.25v-1.5m-6 1.5v-1.5m12 9.75-1.5.75a3.354 3.354 0 0 1-3 0 3.354 3.354 0 0 0-3 0 3.354 3.354 0 0 1-3 0 3.354 3.354 0 0 0-3 0 3.354 3.354 0 0 1-3 0L3 16.5m15-3.379a48.474 48.474 0 0 0-6-.371c-2.032 0-4.034.126-6 .371m12 0c.39.049.777.102 1.163.16 1.07.16 1.837 1.094 1.837 2.175v5.169c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 0 1 3 20.625v-5.17c0-1.08.768-2.014 1.837-2.174A47.78 47.78 0 0 1 6 13.12' },
    { key: 'providerPortal.featureTransport', icon: 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12' },
    { key: 'providerPortal.featureReports', icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z' },
];

/** The provider portal's signed-out pages: branding panel on the left, the page's card on the right. */
export default function ProviderAuthLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { t, locale } = useLocale();
    const { getString } = useSystemSettings();

    const systemName = getString('app.short_name', 'EUISIS');
    const systemLogoUrl = getString('general.identity_system_logo_url', '');
    const orgName = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));

    return (
        <div className="flex min-h-screen">
            <Head title={title} />

            {/* ── Left branding panel ───────────────────────────────────── */}
            <div className="relative hidden w-[52%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                <div className="absolute inset-0 bg-gradient-to-br from-[#1a1a2e] via-[#16213e] to-[#0f3460]" />
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.035]"
                    style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,0.8) 1px, transparent 1px)', backgroundSize: '28px 28px' }}
                />
                <div className="pointer-events-none absolute -top-40 -left-40 h-[520px] w-[520px] rounded-full bg-orange-500/20 blur-[130px]" />
                <div className="pointer-events-none absolute -bottom-32 right-0 h-[380px] w-[380px] rounded-full bg-sky-500/10 blur-[100px]" />

                <div className="relative z-10 flex h-full flex-col items-center justify-between px-10 py-10 text-center">
                    <div className="flex flex-col items-center gap-3">
                        {systemLogoUrl ? (
                            <img src={systemLogoUrl} alt={systemName} className="h-16 w-auto max-w-[180px] object-contain drop-shadow-lg" />
                        ) : (
                            <ApplicationLogo className="h-16 w-auto max-w-[180px] fill-white drop-shadow-lg" />
                        )}
                        <p className="text-lg font-bold tracking-wide text-white">{systemName}</p>
                    </div>

                    <div className="flex max-w-md flex-col items-center gap-6">
                        <div className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-4 py-1.5">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-orange-400" />
                            <span className="text-[11px] font-medium text-orange-300">{t('providerPortal.portalName')}</span>
                        </div>

                        <div className="space-y-3">
                            <h1 className="text-[2rem] font-bold leading-[1.2] text-white">{t('providerPortal.loginHeadline')}</h1>
                            <p className="text-sm leading-relaxed text-slate-400">{t('providerPortal.loginDescription')}</p>
                        </div>

                        <div className="grid w-full grid-cols-2 gap-2">
                            {FEATURES.map(({ key, icon }, i) => (
                                <div key={key} className="flex items-center gap-2 rounded-lg border border-white/[0.07] bg-white/[0.04] px-3 py-2 text-left">
                                    <svg className={`h-4 w-4 shrink-0 ${i % 2 === 0 ? 'text-orange-400' : 'text-sky-400'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                                    </svg>
                                    <span className="text-[12px] text-slate-300">{t(key)}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex w-full items-center gap-3">
                        <div className="h-px flex-1 bg-white/[0.06]" />
                        <p className="text-[11px] text-slate-500">{t('auth.footerGov')} · {orgName}</p>
                        <div className="h-px flex-1 bg-white/[0.06]" />
                    </div>
                </div>
            </div>

            {/* ── Right form panel ──────────────────────────────────────── */}
            <div className="flex flex-1 flex-col bg-gray-50 dark:bg-[#0d0f14]">
                <div className="flex items-center justify-between gap-3 px-6 py-5">
                    <div className="flex items-center gap-2.5 lg:invisible">
                        {systemLogoUrl ? (
                            <img src={systemLogoUrl} alt={systemName} className="h-8 w-auto max-w-[80px] object-contain" />
                        ) : (
                            <ApplicationLogo className="h-8 w-auto max-w-[80px] fill-slate-800 dark:fill-white" />
                        )}
                        <span className="text-sm font-bold text-slate-900 dark:text-white">{systemName}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <LanguageSwitcher />
                        <ThemeToggle />
                    </div>
                </div>

                <div className="flex flex-1 items-center justify-center px-6 pb-10">
                    <div className="w-full max-w-[400px]">
                        <div className="rounded-panel border border-gray-200 bg-white px-8 py-8 dark:border-slate-800 dark:bg-slate-900">
                            {children}
                        </div>

                        <p className="mt-5 text-center text-[11px] text-gray-400 dark:text-slate-500">
                            {t('providerPortal.portalName')} · {t('providerPortal.authorizedOnly')}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
