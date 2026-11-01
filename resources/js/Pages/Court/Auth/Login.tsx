import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler, type KeyboardEvent, useRef, useState } from 'react';
import CourtAuthLayout, { CourtEmblem } from '@/court/components/auth/CourtAuthLayout';
import { useLocale } from '@/hooks/useLocale';

/**
 * The court staff sign-in, in the court's own look (CourtAuthLayout). It posts
 * the same form as the EUISIS sign-in it replaces (route `login`: email and
 * password) and keeps its behaviour: session and status notices, the
 * show-password switch, the Caps Lock warning and a shake on a refused attempt.
 * Applicants and respondents are pointed to the employee portal.
 */
export default function CourtLogin({ status, canResetPassword, sessionNotice, portalUrl }: {
    status?: string;
    canResetPassword: boolean;
    sessionNotice?: 'idle_timeout' | 'password_changed' | 'page_expired' | null;
    /** Where employees follow their cases (EUISIS My Portal), once the court portal is closed. */
    portalUrl?: string | null;
}) {
    const { t } = useLocale();
    const text = (key: string) => t(`court.signIn.${key}`);
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '' });
    const [showPassword, setShowPassword] = useState(false);
    const [capsLock, setCapsLock] = useState(false);
    const cardRef = useRef<HTMLDivElement>(null);

    const shakeCard = () => {
        const card = cardRef.current;
        if (!card) return;
        card.classList.remove('lg-shake');
        void card.offsetWidth;
        card.classList.add('lg-shake');
    };
    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('login'), { onError: shakeCard, onFinish: () => reset('password') });
    };
    const watchCapsLock = (event: KeyboardEvent<HTMLInputElement>) => {
        if (typeof event.getModifierState === 'function') setCapsLock(event.getModifierState('CapsLock'));
    };

    const field = 'block w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs outline-none transition placeholder:text-slate-400 focus:border-(--court-brass) focus:ring-3 focus:ring-(--court-brass)/25 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500';

    return (
        <CourtAuthLayout>
            <Head title={text('title')} />

            <div ref={cardRef} className="login-rise overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-[0_24px_60px_-30px_rgba(12,26,49,0.45)] dark:border-slate-800 dark:bg-[#0f1b31]">
                <div aria-hidden="true" className="login-sheen h-1 bg-gradient-to-r from-(--court-navy) via-(--court-brass) to-(--court-navy)" />
                <div className="px-6 pb-7 pt-6 sm:px-8">
                    <div className="mb-6 flex items-center gap-3">
                        <span className="flex h-11 w-11 items-center justify-center rounded-full bg-(--court-ink) text-(--court-brass)"><CourtEmblem className="h-8 w-8" /></span>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight text-(--court-ink) dark:text-white">{text('title')}</h1>
                            <p className="text-xs leading-relaxed text-slate-500 dark:text-slate-400">{text('subtitle')}</p>
                        </div>
                    </div>

                    {sessionNotice && (
                        <div role="status" className="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                            {t(`auth.session.${sessionNotice}`)}
                        </div>
                    )}
                    {status && (
                        <div role="status" className="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-5" noValidate>
                        <div>
                            <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">{t('auth.emailAddress')}</label>
                            <input id="email" type="email" name="email" autoComplete="username" autoFocus required value={data.email}
                                onChange={(event) => setData('email', event.target.value)} aria-invalid={Boolean(errors.email)}
                                aria-describedby={errors.email ? 'email-error' : undefined} className={field} placeholder="name@example.gov.et" />
                            {errors.email && <p id="email-error" role="alert" className="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{errors.email}</p>}
                        </div>

                        <div>
                            <div className="mb-1.5 flex items-center justify-between gap-3">
                                <label htmlFor="password" className="block text-sm font-medium text-slate-700 dark:text-slate-300">{t('auth.password')}</label>
                                {canResetPassword && (
                                    <Link href={route('password.request')} className="text-xs font-medium text-(--court-navy) underline-offset-2 hover:underline dark:text-(--court-brass-soft)">
                                        {t('auth.forgotPassword')}
                                    </Link>
                                )}
                            </div>
                            <div className="relative">
                                <input id="password" type={showPassword ? 'text' : 'password'} name="password" autoComplete="current-password" required value={data.password}
                                    onChange={(event) => setData('password', event.target.value)} onKeyDown={watchCapsLock} onKeyUp={watchCapsLock} onBlur={() => setCapsLock(false)}
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={[errors.password ? 'password-error' : null, capsLock ? 'caps-lock-warning' : null].filter(Boolean).join(' ') || undefined}
                                    className={`${field} pr-11`} />
                                <button type="button" onClick={() => setShowPassword((shown) => !shown)} aria-pressed={showPassword}
                                    aria-label={showPassword ? t('auth.hidePassword') : t('auth.showPassword')}
                                    className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-slate-400 hover:text-(--court-navy) focus-visible:outline-2 focus-visible:outline-(--court-brass) dark:hover:text-(--court-brass-soft)">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" className="h-5 w-5">
                                        {showPassword
                                            ? <path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A9.8 9.8 0 0 1 12 5c5 0 9 4.5 10 7a12 12 0 0 1-3.2 4.3M6.6 6.6C4.4 8 2.8 10 2 12c1 2.5 5 7 10 7 1.6 0 3.1-.4 4.4-1.1" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                                            : <><path d="M2 12c1-2.5 5-7 10-7s9 4.5 10 7c-1 2.5-5 7-10 7S3 14.5 2 12z" stroke="currentColor" strokeWidth="1.6" /><circle cx="12" cy="12" r="3" stroke="currentColor" strokeWidth="1.6" /></>}
                                    </svg>
                                </button>
                            </div>
                            {capsLock && <p id="caps-lock-warning" role="status" className="mt-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">{t('auth.stage.capsLock')}</p>}
                            {errors.password && <p id="password-error" role="alert" className="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{errors.password}</p>}
                        </div>

                        <button type="submit" disabled={processing}
                            className="flex w-full items-center justify-center gap-2 rounded-md bg-(--court-navy) px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-(--court-navy-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--court-brass) disabled:cursor-wait disabled:opacity-70">
                            {processing ? t('auth.signingIn') : t('auth.signIn')}
                            {!processing && <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className="h-4 w-4"><path d="M4 10h11m-4-4 4 4-4 4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" /></svg>}
                        </button>
                    </form>
                </div>
            </div>

            <div className="mt-5 rounded-lg border border-(--court-brass)/40 bg-(--court-brass)/10 px-5 py-4 text-sm dark:bg-(--court-brass)/5">
                <p className="font-semibold text-(--court-ink) dark:text-(--court-brass-soft)">{text('partiesTitle')}</p>
                <p className="mt-1 leading-relaxed text-slate-600 dark:text-slate-300">{text('partiesText')}</p>
                {portalUrl && (
                    <a href={portalUrl} className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-(--court-navy) underline-offset-2 hover:underline dark:text-(--court-brass-soft)">
                        {text('partiesLink')} <span aria-hidden="true">›</span>
                    </a>
                )}
                <p className="mt-3 border-t border-(--court-brass)/30 pt-3 text-slate-600 dark:text-slate-300">
                    {text('lawyerText')}{' '}
                    <a href={route('court.lawyer.login')} className="inline-flex items-center gap-1 font-semibold text-(--court-navy) underline-offset-2 hover:underline dark:text-(--court-brass-soft)">
                        {text('lawyerLink')} <span aria-hidden="true">›</span>
                    </a>
                </p>
            </div>
        </CourtAuthLayout>
    );
}
