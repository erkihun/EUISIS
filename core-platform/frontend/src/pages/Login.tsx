import { useMemo, useRef, useState, type FormEventHandler, type KeyboardEvent } from 'react';
import { fieldErrors, firstError } from '../api';
import { useAuth } from '../auth';
import ApplicationLogo from '../components/ApplicationLogo';
import { FlowBridge, FlowStepper, IdentityOrbit, NetworkFlow, type FlowService, type FlowStep, useMotionAllowed } from '../components/auth/LoginStage';
import AppToaster from '../components/AppToaster';
import LanguageSwitcher from '../components/LanguageSwitcher';
import ThemeToggle from '../components/ThemeToggle';
import { useLocale } from '../i18n';
import { useBranding, useSettings } from '../settings';

/**
 * EUISIS Pages/Auth/Login.tsx as opened at /core/login: the same stage (city →
 * institutions → employees → card → verified, services in orbit), titled for
 * the Core, with no self-registration and no password reset (Core accounts are
 * made by an administrator).
 */
export default function LoginPage() {
    const { login } = useAuth();
    const { t, locale } = useLocale();
    const [data, setData] = useState({ email: '', password: '' });
    const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
    const [processing, setProcessing] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [capsLock, setCapsLock] = useState(false);
    const cardRef = useRef<HTMLDivElement>(null);
    const motion = useMotionAllowed();

    const { getString } = useSettings();
    const { brandName, officialName, orgName, bureauName } = useBranding(locale);
    const systemName = t('core.title');
    // Only an administrator's own sign-in message (Core settings › General), when one is set.
    const customMsg = getString(locale === 'am' ? 'general.login_page_message_am' : 'general.login_page_message_en');

    // Every rejected attempt shakes the card once. The class is restarted in place, so the form keeps its focus and values.
    const shakeCard = () => {
        const card = cardRef.current;
        if (!card) return;
        card.classList.remove('lg-shake');
        void card.offsetWidth;
        card.classList.add('lg-shake');
    };

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});
        try {
            await login(data.email, data.password);
        } catch (caught) {
            const fields = fieldErrors(caught);
            setErrors({ email: fields.email ?? (fields.password ? undefined : firstError(caught) ?? t('platform.error')), password: fields.password });
            shakeCard();
            setData((current) => ({ ...current, password: '' }));
        } finally {
            setProcessing(false);
        }
    };

    const watchCapsLock = (event: KeyboardEvent<HTMLInputElement>) => {
        if (typeof event.getModifierState === 'function') setCapsLock(event.getModifierState('CapsLock'));
    };

    const steps: FlowStep[] = useMemo(() => ['city', 'institutions', 'employees', 'card', 'verified'].map((key) => ({
        key, title: t(`auth.stage.steps.${key}.title`), text: t(`auth.stage.steps.${key}.text`),
    })), [t]);
    const services: FlowService[] = useMemo(() => ['cafeteria', 'transport', 'performance', 'grievance', 'activity', 'reports'].map((key) => ({
        key, label: t(`auth.stage.services.${key}`),
    })), [t]);
    const cardLogo = <ApplicationLogo className="h-7 w-7 fill-white" />;

    const fieldClass = (invalid: boolean) =>
        `h-12 w-full rounded-control border bg-(--app-surface) pl-11 pr-11 text-sm text-(--app-foreground) placeholder:text-(--app-muted-foreground) transition-[box-shadow,border-color,background-color] focus:outline-hidden ${
            invalid ? 'border-red-400 dark:border-red-500' : 'border-(--app-border-strong) hover:border-(--app-muted-foreground) focus:border-primary'
        }`;

    const card = (
        <div ref={cardRef}>
            <div className="lg-rim rounded-panel p-px shadow-[0_40px_90px_-30px_rgba(0,0,0,0.8)]">
                <div className="relative overflow-hidden rounded-panel bg-(--app-surface)">
                    <div className="login-sheen h-1 w-full bg-[linear-gradient(90deg,var(--color-primary),var(--color-accent),var(--color-primary))]" aria-hidden="true" />
                    {processing && (
                        <div className="absolute inset-x-0 top-0 h-1 overflow-hidden" aria-hidden="true">
                            <div className="lg-progress h-full w-1/3 bg-linear-to-r from-transparent via-white to-transparent opacity-80" />
                        </div>
                    )}

                    <div className="px-6 py-8 sm:px-9">
                        <div className="mb-7 flex items-center gap-3">
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center">
                                <ApplicationLogo className="h-10 w-10 fill-primary" />
                            </div>
                            <div className="min-w-0">
                                <h2 className="login-rise min-w-0 truncate text-2xl font-bold tracking-tight text-(--app-foreground)" style={{ animationDelay: '200ms' }}>{systemName}</h2>
                                <p className="login-rise mt-0.5 text-sm text-(--app-muted-foreground)" style={{ animationDelay: '260ms' }}>{t('core.signInHint')}</p>
                            </div>
                        </div>

                        <form onSubmit={submit} className="space-y-5">
                            <div className="login-rise" style={{ animationDelay: '380ms' }}>
                                <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">{t('auth.emailAddress')}</label>
                                <div className="lg-field">
                                    <svg className="pointer-events-none absolute left-4 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
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
                                        onChange={(e) => setData({ ...data, email: e.target.value })}
                                        placeholder="you@example.com"
                                        className={fieldClass(Boolean(errors.email))}
                                    />
                                </div>
                                {errors.email && <p id="email-error" role="alert" className="login-rise mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.email}</p>}
                            </div>

                            <div className="login-rise" style={{ animationDelay: '450ms' }}>
                                <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-(--app-foreground)">{t('auth.password')}</label>
                                <div className="lg-field">
                                    <svg className="pointer-events-none absolute left-4 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
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
                                        aria-describedby={[errors.password ? 'password-error' : null, capsLock ? 'caps-lock-warning' : null].filter(Boolean).join(' ') || undefined}
                                        onChange={(e) => setData({ ...data, password: e.target.value })}
                                        onKeyDown={watchCapsLock}
                                        onKeyUp={watchCapsLock}
                                        onBlur={() => setCapsLock(false)}
                                        placeholder="••••••••"
                                        className={fieldClass(Boolean(errors.password))}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((v) => !v)}
                                        aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')}
                                        aria-pressed={showPassword}
                                        className="absolute right-2 top-1/2 z-10 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-control text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary"
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
                                {capsLock && (
                                    <p id="caps-lock-warning" role="status" className="login-rise mt-1.5 flex items-center gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4 5 11h4v5h6v-5h4l-7-7ZM9 20h6" />
                                        </svg>
                                        {t('auth.stage.capsLock')}
                                    </p>
                                )}
                                {errors.password && <p id="password-error" role="alert" className="login-rise mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.password}</p>}
                            </div>

                            <div className="login-rise" style={{ animationDelay: '520ms' }}>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-control bg-primary px-4 text-sm font-semibold text-(--color-on-primary) shadow-lg shadow-[rgba(18,33,112,0.3)] transition-[transform,box-shadow,background-color] hover:-translate-y-px hover:bg-primary-hover hover:shadow-xl focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-(--app-surface) active:translate-y-0 active:scale-[0.99] disabled:cursor-wait disabled:opacity-80 disabled:hover:translate-y-0"
                                >
                                    <span className="login-sheen pointer-events-none absolute inset-0 bg-[linear-gradient(110deg,transparent_35%,rgba(255,255,255,0.16)_50%,transparent_65%)] opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
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
                                            <svg className="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                            </svg>
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <div className="relative isolate flex min-h-screen flex-col overflow-hidden bg-[#070d26] text-white">
            <AppToaster />
            {/* Backdrop: slow lights and a grid that fades toward the edges. */}
            <div className="login-blob lg-glow-primary pointer-events-none absolute -left-40 -top-44 -z-10 h-136 w-136 rounded-full opacity-70" aria-hidden="true" />
            <div className="login-blob lg-glow-accent pointer-events-none absolute -bottom-48 right-[-14%] -z-10 h-128 w-lg rounded-full opacity-25" style={{ animationDelay: '-9s', animationDuration: '27s' }} aria-hidden="true" />
            <div className="login-blob lg-glow-sky pointer-events-none absolute left-[40%] top-[30%] -z-10 h-80 w-80 rounded-full opacity-15" style={{ animationDelay: '-16s', animationDuration: '19s' }} aria-hidden="true" />
            <div
                className="pointer-events-none absolute inset-0 -z-10 opacity-[0.06]"
                style={{
                    backgroundImage: 'linear-gradient(rgba(255,255,255,0.7) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.7) 1px, transparent 1px)',
                    backgroundSize: '44px 44px',
                    maskImage: 'radial-gradient(ellipse at 50% 50%, black 20%, transparent 75%)',
                    WebkitMaskImage: 'radial-gradient(ellipse at 50% 50%, black 20%, transparent 75%)',
                }}
                aria-hidden="true"
            />

            <header className="mx-auto flex w-full max-w-[90%] items-center justify-between gap-4 pt-5 lg:pt-7">
                <div className="login-rise flex min-w-0 items-center gap-3">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center">
                        <ApplicationLogo className="h-10 w-10 fill-white" />
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-base font-bold tracking-tight">{systemName}</p>
                        <p className="truncate text-[11px] text-white/60">{officialName}</p>
                    </div>
                </div>
                <div className="login-rise flex shrink-0 items-center gap-3" style={{ animationDelay: '80ms' }}>
                    <LanguageSwitcher variant="toolbar" />
                    <span className="flex rounded-md bg-white/10 ring-1 ring-white/15 [&_button]:text-white">
                        <ThemeToggle variant="toolbar" />
                    </span>
                </div>
            </header>

            <main className="mx-auto flex w-full max-w-[90%] flex-1 flex-col justify-center">
                <div className="mx-auto mt-8 max-w-4xl text-center lg:mt-6 [@media(max-height:820px)]:lg:mt-4">
                    <p className="login-rise text-[12px] font-semibold uppercase tracking-[0.16em] text-sky-300/80" style={{ animationDelay: '120ms' }}>
                        {orgName} · {bureauName}
                    </p>
                    <h1 className="login-rise mt-3 text-[2rem] font-bold leading-[1.08] tracking-tight sm:text-[2.4rem] xl:text-[2.7rem] [@media(max-height:820px)]:lg:text-[2.1rem]" style={{ animationDelay: '200ms' }}>
                        {t('auth.stage.headline')}{' '}
                        <span className="bg-linear-to-r from-orange-200 via-orange-400 to-amber-300 bg-clip-text text-transparent">{t('auth.stage.headlineAccent')}</span>
                    </h1>
                    {customMsg && (
                        <p className="login-rise mx-auto mt-3 max-w-3xl text-[15px] leading-relaxed text-white/70 [@media(max-height:820px)]:lg:hidden" style={{ animationDelay: '280ms' }}>
                            {customMsg}
                        </p>
                    )}
                </div>

                {/* One row: the organisation network on the left, its single line into the employee
                    ID card in the centre (services in orbit), the sign-in form on the right. */}
                <div data-login-stage className="relative my-8 grid items-stretch lg:my-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)_minmax(0,400px)] lg:gap-8">
                    <div className="relative hidden min-h-[380px] lg:block">
                        <div className="absolute inset-0"><NetworkFlow steps={steps} /></div>
                    </div>
                    <div className="relative hidden min-h-[380px] lg:block">
                        <div className="absolute inset-0">
                            <IdentityOrbit
                                steps={steps}
                                services={services}
                                brandName={brandName}
                                logo={cardLogo}
                                cardTitle={t('auth.stage.cardTitle')}
                                verifiedLabel={t('auth.stage.cardVerified')}
                                motion={motion}
                                photos={[]}
                            />
                        </div>
                    </div>
                    <div className="mb-4 lg:hidden"><FlowStepper steps={steps} /></div>
                    <div className="lg-card-slot relative z-20 mx-auto w-full max-w-[420px] self-center">{card}</div>
                    <FlowBridge />
                </div>
            </main>

            <footer className="mx-auto w-full max-w-[90%] pb-5">
                <div className="login-rise flex flex-col items-center gap-2 text-center text-[11px] text-white/45 sm:flex-row sm:justify-between sm:text-left" style={{ animationDelay: '700ms' }}>
                    <span>© {new Date().getFullYear()} {orgName}</span>
                    <span>{t('common.designCredit')}</span>
                </div>
            </footer>
        </div>
    );
}
