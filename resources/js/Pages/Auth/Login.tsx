import ApplicationLogo from '@/Components/ApplicationLogo';
import { PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { useBranding } from '@/hooks/useBranding';

/**
 * Sign-in: a brand stage on the left (aurora, grid and an orbit that drift with CSS only, so
 * nothing is downloaded or computed) and the form on the right. Every animation is a transform or
 * opacity keyframe in resources/css/app.css and stops under the animations setting or the
 * reduced-motion preference, where the global rules zero its duration.
 */
export default function Login({
    status,
    canResetPassword,
    sessionNotice,
}: {
    status?: string;
    canResetPassword: boolean;
    /** Why the previous session ended; a code localized here. */
    sessionNotice?: 'idle_timeout' | 'password_changed' | 'page_expired' | null;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
    });

    const [showPassword, setShowPassword] = useState(false);
    const { t, locale } = useLocale();
    const { getString } = useSystemSettings();
    const { registration_enabled: registrationEnabled = false } = usePage<
        PageProps<{ registration_enabled?: boolean }>
    >().props;

    // Branding pulled from system settings
    const { brandName: systemName, officialName } = useBranding();
    const orgName = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));
    const bureauName = locale === 'am'
        ? getString('id_cards.bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሀብት ልማት ቢሮ')
        : getString('id_cards.bureau_name_en', 'Public Service & HRD Bureau');
    const customMsg = locale === 'am'
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

    const fieldClass = (invalid: boolean) =>
        `h-11 w-full rounded-control border bg-[color:var(--app-surface)] pl-10 pr-11 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] transition-[box-shadow,border-color] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] ${
            invalid ? 'border-red-400 dark:border-red-500' : 'border-[color:var(--app-border-strong)] focus:border-[color:var(--color-primary)]'
        }`;

    const brand = (
        <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-control bg-white/10 ring-1 ring-white/15 backdrop-blur">
                {systemLogoUrl ? (
                    <img src={systemLogoUrl} alt={systemName} width={32} height={32} decoding="async" className="h-8 w-8 object-contain" />
                ) : (
                    <ApplicationLogo className="h-7 w-7 fill-white" />
                )}
            </div>
            <div className="min-w-0">
                <p className="truncate text-base font-bold tracking-tight text-white">{systemName}</p>
                <p className="truncate text-[11px] text-white/60">{officialName}</p>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-[color:var(--app-background)] text-[color:var(--app-foreground)]">
            <Head title={t('auth.signIn')} />

            <div className="grid min-h-screen lg:grid-cols-[1.1fr_minmax(0,1fr)]">
                {/* ── Brand stage ─────────────────────────────────────────── */}
                <section className="relative isolate hidden min-w-0 overflow-hidden bg-[#0a1230] text-white lg:flex">
                    <div className="login-blob absolute -left-40 -top-44 h-[34rem] w-[34rem] rounded-full bg-[color:var(--color-primary)] opacity-70 blur-[110px]" />
                    <div className="login-blob absolute -bottom-40 right-[-12%] h-[32rem] w-[32rem] rounded-full bg-[color:var(--color-accent)] opacity-30 blur-[120px]" style={{ animationDelay: '-9s', animationDuration: '27s' }} />
                    <div className="login-blob absolute left-[30%] top-[38%] h-72 w-72 rounded-full bg-sky-400 opacity-20 blur-[90px]" style={{ animationDelay: '-16s', animationDuration: '19s' }} />
                    <div
                        className="pointer-events-none absolute inset-0 opacity-[0.07]"
                        style={{
                            backgroundImage: 'linear-gradient(rgba(255,255,255,0.6) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.6) 1px, transparent 1px)',
                            backgroundSize: '44px 44px',
                            maskImage: 'radial-gradient(ellipse at 50% 45%, black 25%, transparent 72%)',
                            WebkitMaskImage: 'radial-gradient(ellipse at 50% 45%, black 25%, transparent 72%)',
                        }}
                    />
                    <svg className="pointer-events-none absolute -right-28 top-1/2 h-[38rem] w-[38rem] -translate-y-1/2" viewBox="0 0 400 400" aria-hidden="true">
                        <circle cx="200" cy="200" r="190" fill="none" stroke="rgba(255,255,255,0.09)" />
                        <circle cx="200" cy="200" r="142" fill="none" stroke="rgba(255,255,255,0.08)" strokeDasharray="3 9" />
                        <circle cx="200" cy="200" r="94" fill="none" stroke="rgba(255,255,255,0.06)" />
                        <g className="login-orbit">
                            <circle cx="200" cy="10" r="12" fill="#f76b3c" opacity="0.25" />
                            <circle cx="200" cy="10" r="5" fill="#f76b3c" />
                        </g>
                        <g className="login-orbit" style={{ animationDirection: 'reverse', animationDuration: '42s' }}>
                            <circle cx="342" cy="200" r="4" fill="#93c5fd" />
                        </g>
                        <g className="login-orbit" style={{ animationDuration: '18s', animationDelay: '-6s' }}>
                            <circle cx="200" cy="106" r="3" fill="#ffffff" opacity="0.8" />
                        </g>
                    </svg>

                    <div className="relative z-10 flex w-full flex-col justify-between px-12 py-10 xl:px-16">
                        <div className="login-rise">{brand}</div>

                        <div className="max-w-xl">
                            <div className="login-rise inline-flex items-center gap-2 rounded-full border border-orange-400/30 bg-orange-400/10 px-3.5 py-1.5" style={{ animationDelay: '80ms' }}>
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-orange-400 opacity-70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-orange-400" />
                                </span>
                                <span className="text-[11px] font-semibold uppercase tracking-[0.12em] text-orange-200">{t('auth.securePortal')}</span>
                            </div>

                            <h1 className="login-rise mt-6 text-[2.6rem] font-bold leading-[1.1] tracking-tight xl:text-[3rem]" style={{ animationDelay: '160ms' }}>
                                {t('auth.systemHeadline1')}
                                <br />
                                <span className="bg-gradient-to-r from-orange-200 via-orange-400 to-amber-300 bg-clip-text text-transparent">{t('auth.systemHeadlineHighlight')}</span>{' '}
                                {t('auth.systemHeadline2')}
                            </h1>
                            <p className="login-rise mt-5 max-w-lg text-[15px] leading-relaxed text-white/70" style={{ animationDelay: '240ms' }}>
                                {customMsg || t('auth.systemDesc1')}
                            </p>

                            <ul className="mt-8 grid grid-cols-2 gap-2.5 xl:grid-cols-3">
                                {features.map(({ icon, label }, i) => (
                                    <li
                                        key={label}
                                        className="login-rise flex items-center gap-2.5 rounded-control border border-white/10 bg-white/[0.06] px-3 py-2.5 backdrop-blur-sm transition-colors hover:border-white/25 hover:bg-white/10"
                                        style={{ animationDelay: `${320 + i * 70}ms` }}
                                    >
                                        <svg className={`h-4 w-4 shrink-0 ${i % 2 === 0 ? 'text-sky-300' : 'text-orange-300'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                                        </svg>
                                        <span className="text-[12.5px] leading-tight text-white/85">{label}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="login-rise flex items-center gap-3 text-[11px] text-white/45" style={{ animationDelay: '760ms' }}>
                            <span className="h-px flex-1 bg-white/10" />
                            <span>{t('auth.footerGov')} · {orgName} · {bureauName}</span>
                            <span className="h-px flex-1 bg-white/10" />
                        </div>
                    </div>
                </section>

                {/* ── Form ───────────────────────────────────────────────── */}
                <section className="relative flex min-w-0 flex-col">
                    {/* Compact stage for phones and tablets */}
                    <div className="relative isolate overflow-hidden bg-[#0a1230] px-5 py-5 text-white lg:hidden">
                        <div className="login-blob absolute -right-16 -top-20 h-56 w-56 rounded-full bg-[color:var(--color-accent)] opacity-30 blur-[70px]" />
                        <div className="login-blob absolute -left-20 bottom-[-6rem] h-56 w-56 rounded-full bg-[color:var(--color-primary)] opacity-80 blur-[70px]" style={{ animationDelay: '-11s' }} />
                        <div className="relative z-10">{brand}</div>
                    </div>

                    <div className="flex flex-1 items-center justify-center px-4 py-10 sm:px-8">
                        <div className="login-rise w-full max-w-[420px]" style={{ animationDelay: '120ms' }}>
                            <div className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] shadow-[0_30px_70px_-30px_rgba(18,33,112,0.45)]">
                                <div className="login-sheen h-1 w-full bg-[linear-gradient(90deg,var(--color-primary),var(--color-accent),var(--color-primary))]" aria-hidden="true" />

                                <div className="px-6 py-8 sm:px-9">
                                    <div className="mb-7">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[color:var(--color-accent)]">{systemName}</p>
                                        <h2 className="mt-2 text-2xl font-bold tracking-tight text-[color:var(--app-foreground)]">{t('auth.welcomeBack')}</h2>
                                        <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('auth.signInSubtitle')}</p>
                                    </div>

                                    {sessionNotice && (
                                        <div role="status" className="mb-5 flex items-start gap-2.5 rounded-card border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300">
                                            <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            {t(`auth.session.${sessionNotice}`)}
                                        </div>
                                    )}

                                    {status && (
                                        <div role="status" className="mb-5 flex items-start gap-2.5 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-900/20 dark:text-emerald-400">
                                            <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                            </svg>
                                            {status}
                                        </div>
                                    )}

                                    <form onSubmit={submit} className="space-y-5" noValidate={false}>
                                        <div>
                                            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-[color:var(--app-foreground)]">
                                                {t('auth.emailAddress')}
                                            </label>
                                            <div className="relative">
                                                <svg className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                                </svg>
                                                <input
                                                    id="email"
                                                    type="email"
                                                    name="email"
                                                    value={data.email}
                                                    autoComplete="username"
                                                    autoFocus
                                                    required
                                                    aria-invalid={errors.email ? 'true' : undefined}
                                                    aria-describedby={errors.email ? 'email-error' : undefined}
                                                    onChange={(e) => setData('email', e.target.value)}
                                                    placeholder="you@example.com"
                                                    className={fieldClass(Boolean(errors.email))}
                                                />
                                            </div>
                                            {errors.email && (
                                                <p id="email-error" role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.email}</p>
                                            )}
                                        </div>

                                        <div>
                                            <div className="mb-1.5 flex items-center justify-between">
                                                <label htmlFor="password" className="text-sm font-medium text-[color:var(--app-foreground)]">
                                                    {t('auth.password')}
                                                </label>
                                                {canResetPassword && (
                                                    <Link
                                                        href={route('password.request')}
                                                        className="text-xs font-medium text-[color:var(--color-accent)] underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                                                    >
                                                        {t('auth.forgotPassword')}
                                                    </Link>
                                                )}
                                            </div>
                                            <div className="relative">
                                                <svg className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                                </svg>
                                                <input
                                                    id="password"
                                                    type={showPassword ? 'text' : 'password'}
                                                    name="password"
                                                    value={data.password}
                                                    autoComplete="current-password"
                                                    required
                                                    aria-invalid={errors.password ? 'true' : undefined}
                                                    aria-describedby={errors.password ? 'password-error' : undefined}
                                                    onChange={(e) => setData('password', e.target.value)}
                                                    placeholder="••••••••"
                                                    className={fieldClass(Boolean(errors.password))}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setShowPassword((v) => !v)}
                                                    aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')}
                                                    aria-pressed={showPassword}
                                                    className="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-control text-[color:var(--app-muted-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                                                >
                                                    {showPassword ? (
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                                        </svg>
                                                    )}
                                                </button>
                                            </div>
                                            {errors.password && (
                                                <p id="password-error" role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.password}</p>
                                            )}
                                        </div>

                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="group relative flex h-11 w-full items-center justify-center gap-2 overflow-hidden rounded-control bg-[color:var(--color-primary)] px-4 text-sm font-semibold text-[color:var(--color-on-primary)] shadow-lg shadow-[rgba(18,33,112,0.25)] transition-[transform,box-shadow,background-color] hover:-translate-y-px hover:bg-[color:var(--color-primary-hover)] hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[color:var(--app-surface)] active:translate-y-0 disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0"
                                        >
                                            <span className="pointer-events-none absolute inset-y-0 left-0 w-1/3 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 ease-out group-hover:translate-x-[320%]" aria-hidden="true" />
                                            {processing ? (
                                                <>
                                                    <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                    </svg>
                                                    {t('auth.signingIn')}
                                                </>
                                            ) : (
                                                <>
                                                    {t('auth.signIn')}
                                                    <svg className="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                                    </svg>
                                                </>
                                            )}
                                        </button>
                                    </form>

                                    {registrationEnabled && (
                                        <div className="mt-6 border-t border-[color:var(--app-border)] pt-6">
                                            <p className="mb-3 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('auth.noAccount')}</p>
                                            <Link
                                                href={route('register')}
                                                className="flex h-11 w-full items-center justify-center gap-2 rounded-control border border-[color:var(--app-border-strong)] bg-transparent px-4 text-sm font-semibold text-[color:var(--app-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]"
                                            >
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21C7.043 21 4.862 20.355 3 19.234Z" />
                                                </svg>
                                                {t('auth.createEmployeeAccount')}
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <p className="mt-6 text-center text-[11px] text-[color:var(--app-muted-foreground)]">
                                © {new Date().getFullYear()} {orgName} · {t('auth.footerGov')}
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    );
}
