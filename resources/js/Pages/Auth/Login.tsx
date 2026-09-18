import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const [showPassword, setShowPassword] = useState(false);
    const { t, locale } = useLocale();
    const { getString } = useSystemSettings();

    // Branding pulled from system settings
    const systemName  = getString('app.short_name', 'EUISIS');
    const orgName     = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));
    const bureauName  = locale === 'am'
        ? getString('id_cards.bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ')
        : getString('id_cards.bureau_name_en', 'Public Service & HRD Bureau');
    const customMsg   = locale === 'am'
        ? getString('general.login_page_message_am', '')
        : getString('general.login_page_message_en', '');
    const systemLogoUrl = getString('general.identity_system_logo_url', '');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    const features = [
        { icon: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z', label: t('auth.featureRoleAccess') },
        { icon: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z', label: t('auth.featureIdCard') },
        { icon: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z', label: t('auth.featureAudit') },
        { icon: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21', label: t('auth.featureMultiOrg') },
        { icon: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z', label: t('auth.featureRegistry') },
        { icon: 'M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605', label: t('auth.featureCodeGen') },
    ];

    return (
        <div className="flex min-h-screen" dir={locale === 'am' ? 'ltr' : 'ltr'}>
            <Head title={t('auth.signIn')} />

            {/* ── Left branding panel ───────────────────────────────────── */}
            <div className="relative hidden w-[58%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                {/* Base gradient */}
                <div className="absolute inset-0 bg-gradient-to-br from-blue-950 via-[#0c1228] to-slate-950" />

                {/* Dot grid */}
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.035]"
                    style={{
                        backgroundImage: 'radial-gradient(rgba(255,255,255,0.8) 1px, transparent 1px)',
                        backgroundSize: '28px 28px',
                    }}
                />

                {/* Glow orbs */}
                <div className="pointer-events-none absolute -top-48 -left-48 h-[600px] w-[600px] rounded-full bg-[color:var(--color-primary)]/25 blur-[130px]" />
                <div className="pointer-events-none absolute -bottom-32 right-0 h-[400px] w-[400px] rounded-full bg-orange-600/15 blur-[100px]" />
                <div className="pointer-events-none absolute top-1/2 left-1/4 h-64 w-64 -translate-y-1/2 rounded-full bg-blue-400/10 blur-[80px]" />

                {/* Decorative concentric rings */}
                <div className="pointer-events-none absolute -right-28 top-1/2 -translate-y-1/2">
                    <div className="h-[520px] w-[520px] rounded-full border border-white/[0.06]" />
                    <div className="absolute inset-[52px] rounded-full border border-white/[0.05]" />
                    <div className="absolute inset-[104px] rounded-full border border-white/[0.04]" />
                    <div className="absolute inset-[156px] rounded-full border border-white/[0.03]" />
                </div>

                {/* Content */}
                <div className="relative z-10 flex h-full flex-col items-center justify-between px-10 py-10 text-center">

                    {/* Logo */}
                    <div className="flex flex-col items-center gap-3">
                        {systemLogoUrl ? (
                            <img src={systemLogoUrl} alt={systemName} className="h-20 w-auto max-w-[200px] object-contain drop-shadow-lg" />
                        ) : (
                            <ApplicationLogo className="h-20 w-auto max-w-[200px] fill-white drop-shadow-lg" />
                        )}
                        <div>
                            <p className="text-lg font-bold tracking-wide text-white">{systemName}</p>
                            <p className="text-[11px] text-orange-300/60">{bureauName}</p>
                        </div>
                    </div>

                    {/* Main description block */}
                    <div className="flex max-w-lg flex-col items-center gap-6">

                        {/* Live badge */}
                        <div className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-4 py-1.5">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-orange-400" />
                            <span className="text-[11px] font-medium text-orange-300">{t('auth.securePortal')}</span>
                        </div>

                        {/* Headline */}
                        <div className="space-y-3">
                            <h1 className="text-[2.2rem] font-bold leading-[1.2] text-white">
                                {t('auth.systemHeadline1')}<br />
                                <span className="text-orange-400">{t('auth.systemHeadlineHighlight')}</span> {t('auth.systemHeadline2')}
                            </h1>
                            <p className="text-sm leading-relaxed text-slate-400">
                                {customMsg || t('auth.systemDesc1')}
                            </p>
                        </div>

                        {/* Divider */}
                        <div className="w-16 border-t border-white/10" />

                        {/* Description paragraphs */}
                        <div className="space-y-3 text-left">
                            <p className="text-[13px] leading-relaxed text-slate-400">
                                <span className="font-semibold text-slate-200">{systemName}</span> {t('auth.systemDesc1')}
                            </p>
                            <p className="text-[13px] leading-relaxed text-slate-400">
                                {t('auth.systemDesc2')}
                            </p>
                        </div>

                        {/* Feature pills */}
                        <div className="grid w-full grid-cols-2 gap-2">
                            {features.map(({ icon, label }, i) => (
                                <div key={label} className="flex items-center gap-2 rounded-lg border border-white/[0.07] bg-white/[0.04] px-3 py-2">
                                    <svg className={`h-3.5 w-3.5 shrink-0 ${i % 2 === 0 ? 'text-blue-400' : 'text-orange-400'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                                    </svg>
                                    <span className="text-[12px] text-slate-300">{label}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="flex w-full items-center gap-3">
                        <div className="h-px flex-1 bg-white/[0.06]" />
                        <p className="text-[11px] text-slate-600">
                            {t('auth.footerGov')} · {orgName}
                        </p>
                        <div className="h-px flex-1 bg-white/[0.06]" />
                    </div>
                </div>
            </div>

            {/* ── Right form panel ──────────────────────────────────────── */}
            <div className="flex flex-1 flex-col bg-gray-50 dark:bg-[#0d0f14]">

                {/* Mobile top bar */}
                <div className="flex items-center gap-2.5 px-6 py-5 lg:hidden">
                    {systemLogoUrl ? (
                        <img src={systemLogoUrl} alt={systemName} className="h-8 w-auto max-w-[80px] object-contain" />
                    ) : (
                        <ApplicationLogo className="h-8 w-auto max-w-[80px] fill-slate-800 dark:fill-white" />
                    )}
                    <span className="text-sm font-bold text-slate-900 dark:text-white">{systemName}</span>
                </div>

                {/* Centered content */}
                <div className="flex flex-1 items-center justify-center px-6 py-10">
                    <div className="w-full max-w-[400px]">

                        {/* Form card */}
                        <div className="rounded-panel border border-gray-200 bg-white px-8 py-8 dark:border-slate-800 dark:bg-slate-900">

                            {/* Heading */}
                            <div className="mb-7">
                                <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-panel bg-orange-600 shadow-md shadow-orange-600/30">
                                    <svg className="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                    </svg>
                                </div>
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white">{t('auth.welcomeBack')}</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                    {t('auth.signInSubtitle')}
                                </p>
                            </div>

                            {/* Status */}
                            {status && (
                                <div className="mb-5 flex items-center gap-2.5 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-900/20 dark:text-emerald-400">
                                    <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                    </svg>
                                    {status}
                                </div>
                            )}

                            <form onSubmit={submit} className="space-y-4">

                                {/* Email */}
                                <div>
                                    <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                                        {t('auth.emailAddress')}
                                    </label>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <svg className="h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                            </svg>
                                        </div>
                                        <input
                                            id="email"
                                            type="email"
                                            name="email"
                                            value={data.email}
                                            autoComplete="username"
                                            autoFocus
                                            onChange={(e) => setData('email', e.target.value)}
                                            placeholder="you@example.com"
                                            className={`w-full rounded-card border py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-0 dark:text-slate-100 dark:placeholder:text-slate-500 ${
                                                errors.email
                                                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                                    : 'border-gray-300 bg-white focus:border-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-800'
                                            }`}
                                        />
                                    </div>
                                    {errors.email && (
                                        <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
                                            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                            </svg>
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                {/* Password */}
                                <div>
                                    <div className="mb-1.5 flex items-center justify-between">
                                        <label htmlFor="password" className="text-sm font-medium text-gray-700 dark:text-slate-300">
                                            {t('auth.password')}
                                        </label>
                                        {canResetPassword && (
                                            <Link
                                                href={route('password.request')}
                                                className="text-xs font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300"
                                            >
                                                {t('auth.forgotPassword')}
                                            </Link>
                                        )}
                                    </div>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <svg className="h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                            </svg>
                                        </div>
                                        <input
                                            id="password"
                                            type={showPassword ? 'text' : 'password'}
                                            name="password"
                                            value={data.password}
                                            autoComplete="current-password"
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder="••••••••"
                                            className={`w-full rounded-card border py-2.5 pl-10 pr-11 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-0 dark:text-slate-100 dark:placeholder:text-slate-500 ${
                                                errors.password
                                                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                                    : 'border-gray-300 bg-white focus:border-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-800'
                                            }`}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((v) => !v)}
                                            tabIndex={-1}
                                            aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 transition-colors hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300"
                                        >
                                            {showPassword ? (
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                </svg>
                                            ) : (
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                                </svg>
                                            )}
                                        </button>
                                    </div>
                                    {errors.password && (
                                        <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
                                            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                            </svg>
                                            {errors.password}
                                        </p>
                                    )}
                                </div>

                                {/* Remember me */}
                                <label className="flex cursor-pointer items-center gap-2.5 py-0.5">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked as false)}
                                        className="h-4 w-4 rounded border-gray-300 text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800"
                                    />
                                    <span className="text-sm text-gray-600 dark:text-slate-400">{t('auth.keepSignedIn')}</span>
                                </label>

                                {/* Submit */}
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="flex w-full items-center justify-center gap-2 rounded-card bg-[color:var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition-all hover:bg-[color:var(--color-primary-hover)] hover:shadow-blue-600/30 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                                >
                                    {processing ? (
                                        <>
                                            <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                            </svg>
                                            {t('auth.signingIn')}
                                        </>
                                    ) : (
                                        <>
                                            {t('auth.signIn')}
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                            </svg>
                                        </>
                                    )}
                                </button>
                            </form>
                        </div>

                        {/* Footer */}
                        <p className="mt-5 text-center text-[11px] text-gray-400 dark:text-slate-600">
                            © {new Date().getFullYear()} {orgName} · {t('auth.footerGov')}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
