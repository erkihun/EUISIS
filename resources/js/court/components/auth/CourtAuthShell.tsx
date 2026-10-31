import type { ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { ArrowLeftIcon } from '@/Components/Icons';
import AppToaster from '@/Components/ui/AppToaster';
import LocalizedUiProvider from '@/Components/ui/LocalizedUiProvider';
import { useBranding } from '@/hooks/useBranding';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { usePublicText } from '@/court/i18n/usePublicText';

interface Props {
    title: string;
    subtitle?: string;
    /** Icon in the card's badge. */
    icon: ReactNode;
    /** Show the "Back to login" link under the card (the Blade auth layout always did). */
    backToLogin?: boolean;
    /** Show flash messages as toasts; a page that shows them inline turns this off. */
    flashToasts?: boolean;
    /** Content under the form, inside the card (secondary links). */
    footer?: ReactNode;
    /** A wider card, for a form with several fields side by side (the lawyers' application). */
    wide?: boolean;
    children: ReactNode;
}

/**
 * The staff account pages' frame (forgot password, reset code, new password,
 * registration), modelled on the EUISIS Auth/ForgotPassword page: the brand
 * panel on the left on wide screens, the card on the right, the language and
 * theme switches above it. The panel tells the court's story in the sign-in
 * page's own words (`auth.stage.*`).
 */
export default function CourtAuthShell({ title, subtitle, icon, backToLogin = true, flashToasts = true, footer, wide = false, children }: Props) {
    const { t, text } = usePublicText();
    const { brandName, officialName } = useBranding();
    const { getString } = useSystemSettings();
    const organization = getString('general.organization_name') || officialName;

    return (
        <LocalizedUiProvider>
            <Head title={title} />
            <AppToaster flashToasts={flashToasts} />

            <div className="flex min-h-screen">
                <div className="relative hidden w-[46%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                    <div className="absolute inset-0 bg-linear-to-br from-blue-950 via-[#0c1228] to-slate-950" />
                    <div
                        className="pointer-events-none absolute inset-0 opacity-[0.035]"
                        style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,0.8) 1px, transparent 1px)', backgroundSize: '28px 28px' }}
                    />
                    <div className="pointer-events-none absolute -top-48 -left-48 h-[600px] w-[600px] rounded-full bg-primary/25 blur-[130px]" />
                    <div className="pointer-events-none absolute -bottom-32 right-0 h-[400px] w-[400px] rounded-full bg-orange-600/15 blur-[100px]" />
                    <div className="pointer-events-none absolute -right-28 top-1/2 -translate-y-1/2">
                        <div className="h-[520px] w-[520px] rounded-full border border-white/6" />
                        <div className="absolute inset-[52px] rounded-full border border-white/5" />
                        <div className="absolute inset-[104px] rounded-full border border-white/4" />
                    </div>

                    <div className="relative z-10 flex h-full flex-col items-center justify-between px-10 py-10 text-center">
                        <div className="flex flex-col items-center gap-3">
                            <ApplicationLogo className="h-24 w-24 fill-white drop-shadow-lg" />
                            <div>
                                <p className="text-lg font-bold tracking-wide text-white">{brandName}</p>
                                {officialName && <p className="max-w-xs text-[11px] leading-snug text-orange-300/70">{officialName}</p>}
                            </div>
                        </div>

                        <div className="flex max-w-sm flex-col items-center gap-6">
                            <div className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-4 py-1.5">
                                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-orange-400" />
                                <span className="text-[11px] font-medium text-orange-300">{text('secure_access')}</span>
                            </div>
                            <p className="text-[2.2rem] font-bold leading-[1.2] text-white">
                                {t('auth.stage.headline')}
                                <br />
                                <span className="text-orange-400">{t('auth.stage.headlineAccent')}</span>
                            </p>
                        </div>

                        <div className="flex w-full items-center gap-3">
                            <div className="h-px flex-1 bg-white/6" />
                            <p className="text-[11px] text-slate-500">{organization}</p>
                            <div className="h-px flex-1 bg-white/6" />
                        </div>
                    </div>
                </div>

                <div className="flex flex-1 flex-col bg-gray-50 dark:bg-[#0d0f14]">
                    <div className="flex items-center justify-between gap-3 px-6 py-5">
                        <div className="flex min-w-0 items-center gap-2.5 lg:invisible">
                            <ApplicationLogo className="h-8 w-8 shrink-0 fill-slate-800 dark:fill-white" />
                            <span className="truncate text-sm font-bold text-slate-900 dark:text-white">{brandName}</span>
                        </div>
                        <div className="flex shrink-0 items-center gap-1.5">
                            <LanguageSwitcher />
                            <ThemeToggle />
                        </div>
                    </div>

                    <div className="flex flex-1 items-center justify-center px-6 pb-10">
                        <div className={`w-full ${wide ? 'max-w-[640px]' : 'max-w-[420px]'}`}>
                            <div className="rounded-panel border border-gray-200 bg-white px-8 py-8 dark:border-slate-800 dark:bg-slate-900">
                                <div className="mb-7">
                                    <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-panel bg-primary text-white shadow-md shadow-blue-600/30" aria-hidden="true">
                                        {icon}
                                    </div>
                                    <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{title}</h1>
                                    {subtitle && <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">{subtitle}</p>}
                                </div>

                                {children}

                                {footer}

                                {backToLogin && (
                                    <div className="mt-6 text-center">
                                        <Link
                                            href={route('login')}
                                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-500 transition-colors hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                                        >
                                            <ArrowLeftIcon className="h-4 w-4" aria-hidden="true" />
                                            {t('cm.auth.back_to_login')}
                                        </Link>
                                    </div>
                                )}
                            </div>

                            <p className="mt-5 text-center text-[11px] text-gray-400 dark:text-slate-600">
                                © {new Date().getFullYear()} {organization}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </LocalizedUiProvider>
    );
}

/** The card's input style, as the EUISIS account pages draw it. */
export function courtAuthInputClass(invalid: boolean): string {
    return [
        'w-full rounded-card border px-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 transition-colors',
        'focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-0 dark:text-slate-100 dark:placeholder:text-slate-500',
        invalid
            ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
            : 'border-gray-300 bg-white focus:border-primary dark:border-slate-700 dark:bg-slate-800',
    ].join(' ');
}

export const courtAuthLabelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300';

export const courtAuthButtonClass =
    'flex w-full items-center justify-center gap-2 rounded-card bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition-all hover:bg-primary-hover focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900';

/** The page-level message box (success, info, error), as the Blade `auth-alert`. */
export function CourtAuthAlert({ tone, children }: { tone: 'success' | 'info' | 'error'; children: ReactNode }) {
    const tones = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-900/20 dark:text-emerald-400',
        info: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800/50 dark:bg-blue-900/20 dark:text-blue-300',
        error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-800/50 dark:bg-red-900/20 dark:text-red-400',
    } as const;

    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`mb-5 rounded-card border px-4 py-3 text-sm ${tones[tone]}`}>
            {children}
        </div>
    );
}
