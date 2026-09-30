import ApplicationLogo from '@/Components/ApplicationLogo';
import { ArrowLeftIcon, CheckCircle, EyeIcon, EyeOffIcon, InfoIcon } from '@/Components/Icons';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import OtpInput from '@/Components/public/OtpInput';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import '../../../css/public-site.css';

type Props = {
    otpSent?: boolean;
    pendingEmployeeNumber?: string | null;
    status?: string | null;
};

const STEPS = [
    { title: 'identifyTitle', detail: 'identifyDetail' },
    { title: 'codeTitle', detail: 'codeDetail' },
    { title: 'passwordTitle', detail: 'passwordDetail' },
] as const;

/**
 * Employee self-registration (Claude Design canvas "EUISIS Register Redesign").
 *
 * Two server steps behind three visible ones: the employee number sends a
 * code (step 1); the code and the new password finish the account (steps 2
 * and 3, one form). The brand panel is the public site's hero
 * (resources/css/public-site.css), so registration looks like the site it is
 * reached from. On phones the panel becomes a short band and the steps fold
 * into a disclosure, so the form starts on the first screen.
 */
export default function Register({ otpSent = false, pendingEmployeeNumber = null, status = null }: Props) {
    const { t } = useLocale();
    const { getBoolean } = useSystemSettings();
    const form = useForm({ employee_number: pendingEmployeeNumber ?? '', otp: '', password: '', password_confirmation: '' });
    const [showPassword, setShowPassword] = useState(false);
    const [sendingCode, setSendingCode] = useState(false);
    // Step 2 shows the number as a summary; "Change" reopens the field.
    const [editingNumber, setEditingNumber] = useState(false);
    const codeGroup = useRef<HTMLDivElement>(null);
    const passwordInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (pendingEmployeeNumber) form.setData('employee_number', pendingEmployeeNumber);
    }, [pendingEmployeeNumber]);

    const focusCode = () => codeGroup.current?.querySelector('input')?.focus();

    useEffect(() => {
        if (otpSent) {
            setEditingNumber(false);
            focusCode();
        }
    }, [otpSent]);

    const sendCode = () => {
        if (form.processing) return;
        setSendingCode(true);
        form.post(route('register.send-otp'), {
            preserveScroll: true,
            only: ['otpSent', 'pendingEmployeeNumber', 'status', 'errors'],
            onSuccess: () => { setEditingNumber(false); focusCode(); },
            onFinish: () => setSendingCode(false),
        });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (form.processing) return;
        if (!otpSent || editingNumber) { sendCode(); return; }
        form.post(route('register'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const finishing = otpSent && !editingNumber;
    const inputClass = (hasError: boolean) => `min-h-[52px] w-full min-w-0 rounded-[10px] border px-4 py-3 text-base text-slate-900 outline-none transition placeholder:text-slate-400 focus:ring-4 dark:text-slate-100 dark:placeholder:text-slate-500 ${hasError
        ? 'border-red-400 bg-red-50 focus:border-red-500 focus:ring-red-500/10 dark:border-red-700 dark:bg-red-950/20'
        : 'border-slate-300 bg-white focus:border-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]/15 dark:border-slate-600 dark:bg-slate-950/40'}`;
    const primaryClass = 'flex min-h-[52px] w-full items-center justify-center gap-3 rounded-[10px] bg-[color:var(--color-primary)] px-5 py-3.5 text-[15px] font-bold text-white shadow-md transition hover:bg-[color:var(--color-primary-hover)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[color:var(--color-primary)]/30 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none dark:disabled:bg-slate-800 dark:disabled:text-slate-400';
    const labelClass = 'mb-2 block text-sm font-semibold text-slate-800 dark:text-slate-200';
    const errorClass = 'mt-2 text-xs text-red-600 dark:text-red-400';
    const linkClass = 'rounded font-bold text-[color:var(--color-primary)] underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-[color:var(--color-primary-200)]';

    // Step 1 is done once a code was sent; steps 2 and 3 share the second form.
    const stepState = (index: number): 'done' | 'current' | 'next' =>
        finishing ? (index === 0 ? 'done' : 'current') : (index === 0 ? 'current' : 'next');

    return (
        <div
            className="public-site min-h-screen bg-gray-50 text-slate-900 dark:bg-[#0d0f14] dark:text-slate-100"
            data-motion={getBoolean('appearance.enable_ui_animations', true) ? 'on' : 'off'}
        >
            <Head title={t('auth.registration.title')} />
            <main className="flex min-h-screen w-full flex-col lg:flex-row">
                {/* ── Brand panel (desktop): the public site's hero ── */}
                <aside className="public-hero hidden w-[44%] max-w-[640px] shrink-0 flex-col justify-between px-10 py-10 lg:flex xl:px-14">
                    <div className="public-hero-grid" aria-hidden="true" />
                    <div className="public-hero-orb" aria-hidden="true" />

                    <div className="relative flex items-center gap-3">
                        <ApplicationLogo className="h-12 w-12 shrink-0 fill-white" />
                        <div>
                            <p className="text-lg font-extrabold">EUISIS</p>
                            <p className="text-xs text-blue-200">{t('auth.registration.systemName')}</p>
                        </div>
                    </div>

                    <div className="relative max-w-md py-10">
                        <span className="public-hero-enter inline-block rounded-full border border-orange-400/30 bg-orange-500/20 px-3 py-1 text-xs font-semibold text-orange-200">
                            {t('auth.registration.secureRegistration')}
                        </span>
                        <h1 className="public-hero-enter mt-6 text-3xl font-extrabold xl:text-[2.25rem]">{t('auth.registration.verifyIdentity')}</h1>
                        <ol aria-label={t('auth.registration.progress')} className="public-hero-enter mt-10 space-y-6">
                            {STEPS.map(({ title, detail }, index) => {
                                const state = stepState(index);
                                return (
                                    <li key={title} aria-current={state === 'current' ? 'step' : undefined} className="flex gap-4">
                                        <span aria-hidden="true" className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-extrabold ${state === 'done'
                                            ? 'bg-emerald-400 text-emerald-950'
                                            : state === 'current' ? 'bg-white text-[color:var(--color-primary-700)]' : 'border border-white/35 text-blue-100'}`}>
                                            {state === 'done' ? <CheckCircle className="h-5 w-5" /> : index + 1}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[15px] font-bold">{t(`auth.registration.${title}`)}</span>
                                            <span className={`mt-1 block text-sm leading-relaxed ${state === 'next' ? 'text-blue-200/80' : 'text-blue-100'}`}>
                                                {state === 'done' ? t('auth.registration.done') : t(`auth.registration.${detail}`)}
                                            </span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>

                    <p className="relative text-xs leading-relaxed text-blue-200">
                        {t('auth.registration.contactDetails')} · {t('auth.registration.footer')}
                    </p>
                </aside>

                {/* ── Brand band (phones and tablets) ── */}
                <header className="public-hero px-4 pb-8 pt-4 sm:px-6 lg:hidden">
                    <div className="public-hero-grid" aria-hidden="true" />
                    <div className="relative flex items-center justify-between gap-3">
                        <Link href="/" className="flex items-center gap-2.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
                            <ApplicationLogo className="h-9 w-9 shrink-0 fill-white" />
                            <span className="text-base font-extrabold">EUISIS</span>
                        </Link>
                        <div className="rounded-lg bg-white/95 text-slate-900"><LanguageSwitcher /></div>
                    </div>
                    <p className="relative mt-5 text-xs font-semibold text-orange-200">{t('auth.registration.secureRegistration')}</p>
                    <p className="relative mt-1 text-[22px] font-extrabold leading-tight">
                        {t(finishing ? 'auth.registration.finishTitle' : 'auth.createEmployeeAccount')}
                    </p>
                </header>

                {/* ── Form side ── */}
                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="hidden items-center justify-between gap-4 px-8 py-5 lg:flex">
                        <Link href="/" className="inline-flex min-h-10 items-center gap-1.5 rounded text-sm font-semibold text-slate-600 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-slate-300 dark:hover:text-white">
                            <ArrowLeftIcon aria-hidden="true" className="h-4 w-4" />
                            {t('auth.registration.backHome')}
                        </Link>
                        <LanguageSwitcher />
                    </div>

                    <div className="-mt-4 flex flex-1 items-start justify-center px-4 pb-8 sm:px-6 lg:mt-0 lg:items-center lg:pb-12">
                        <div className="w-full max-w-[480px] space-y-4">
                            <section aria-labelledby="registration-heading" className="public-card relative w-full border border-gray-200/80 bg-white px-5 py-6 sm:p-9 dark:border-slate-800 dark:bg-slate-900">
                                {/* Progress: three segments, text alongside. */}
                                <div aria-hidden="true" className="grid grid-cols-3 gap-2">
                                    {STEPS.map(({ title }, index) => (
                                        <span key={title} className={`h-1 rounded-full ${stepState(index) === 'next' ? 'bg-slate-200 dark:bg-slate-700' : 'bg-[color:var(--color-primary)]'}`} />
                                    ))}
                                </div>
                                <p className="mt-4 text-[13px] font-semibold text-[color:var(--color-primary-500)] dark:text-[color:var(--color-primary-200)]">
                                    {finishing ? t('auth.registration.stepsOf') : t('auth.registration.stepOf').replace(':n', '1')}
                                </p>
                                <h2 id="registration-heading" className="mt-1.5 text-2xl font-extrabold leading-snug tracking-tight sm:text-[26px]">
                                    {t(finishing ? 'auth.registration.finishTitle' : 'auth.createEmployeeAccount')}
                                </h2>
                                <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                                    {t(finishing ? 'auth.registration.finishDescription' : 'auth.registration.startDescription')}
                                </p>

                                {status && (
                                    <div role="status" className="mt-5 flex gap-3 rounded-[10px] border border-emerald-200 bg-emerald-50 p-3.5 text-sm leading-relaxed text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
                                        <CheckCircle aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0" />
                                        <span>{status}</span>
                                    </div>
                                )}

                                <form onSubmit={submit} noValidate aria-busy={form.processing} className="mt-6 space-y-5">
                                    {finishing ? (
                                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
                                            <span className="min-w-0">
                                                <span className="block text-xs text-slate-500 dark:text-slate-400">{t('auth.registration.employeeNumber')}</span>
                                                <span className="block truncate font-mono font-semibold">{form.data.employee_number}</span>
                                            </span>
                                            <button type="button" onClick={() => setEditingNumber(true)} className={`${linkClass} min-h-10 px-1 text-sm`}>
                                                {t('auth.registration.changeNumber')}
                                            </button>
                                        </div>
                                    ) : (
                                        <div>
                                            <label htmlFor="employee_number" className={labelClass}>{t('auth.registration.employeeNumber')}</label>
                                            <input id="employee_number" value={form.data.employee_number} onChange={(e) => form.setData('employee_number', e.target.value)} autoComplete="off" autoCapitalize="characters" spellCheck={false} enterKeyHint="send" placeholder={t('auth.registration.employeeNumberPlaceholder')} aria-invalid={Boolean(form.errors.employee_number)} aria-describedby={form.errors.employee_number ? 'employee-number-error' : 'employee-number-help'} className={inputClass(Boolean(form.errors.employee_number))} />
                                            {form.errors.employee_number
                                                ? <p id="employee-number-error" role="alert" className={errorClass}>{form.errors.employee_number}</p>
                                                : <p id="employee-number-help" className="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{t('auth.registration.employeeNumberHelp')}</p>}
                                        </div>
                                    )}

                                    {finishing && <>
                                        <div>
                                            <div className="mb-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                                <span id="otp-label" className="text-sm font-semibold text-slate-800 dark:text-slate-200">{t('auth.registration.verificationCode')}</span>
                                                <button type="button" onClick={sendCode} disabled={form.processing || !form.data.employee_number.trim()} className={`${linkClass} min-h-10 px-1 text-sm disabled:cursor-not-allowed disabled:opacity-50`}>
                                                    {t(sendingCode ? 'auth.registration.sendingCode' : 'auth.registration.resend')}
                                                </button>
                                            </div>
                                            <div ref={codeGroup}>
                                                <OtpInput
                                                    value={form.data.otp}
                                                    onChange={(code) => form.setData('otp', code)}
                                                    onComplete={() => passwordInput.current?.focus()}
                                                    label={t('auth.registration.verificationCode')}
                                                    describedBy={form.errors.otp ? 'otp-error' : 'otp-help'}
                                                    disabled={form.processing}
                                                />
                                            </div>
                                            {form.errors.otp
                                                ? <p id="otp-error" role="alert" className={errorClass}>{form.errors.otp}</p>
                                                : <p id="otp-help" className="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{t('auth.registration.codeHelp')}</p>}
                                        </div>
                                        <div>
                                            <label htmlFor="password" className={labelClass}>{t('auth.password')}</label>
                                            <div className="relative">
                                                <input ref={passwordInput} id="password" type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" aria-invalid={Boolean(form.errors.password)} aria-describedby={form.errors.password ? 'password-error' : undefined} className={`${inputClass(Boolean(form.errors.password))} pr-14`} />
                                                <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={t(showPassword ? 'auth.hidePassword' : 'auth.showPassword')} aria-pressed={showPassword} className="absolute inset-y-1 right-1 flex w-11 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-slate-400 dark:hover:bg-slate-800">
                                                    {showPassword ? <EyeOffIcon aria-hidden="true" className="h-5 w-5" /> : <EyeIcon aria-hidden="true" className="h-5 w-5" />}
                                                </button>
                                            </div>
                                            {form.errors.password && <p id="password-error" role="alert" className={errorClass}>{form.errors.password}</p>}
                                        </div>
                                        <div>
                                            <label htmlFor="password_confirmation" className={labelClass}>{t('auth.confirmPassword')}</label>
                                            <input id="password_confirmation" type={showPassword ? 'text' : 'password'} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" aria-invalid={Boolean(form.errors.password_confirmation)} aria-describedby={form.errors.password_confirmation ? 'confirmation-error' : undefined} className={inputClass(Boolean(form.errors.password_confirmation))} />
                                            {form.errors.password_confirmation && <p id="confirmation-error" role="alert" className={errorClass}>{form.errors.password_confirmation}</p>}
                                        </div>
                                        <PasswordPolicyChecklist password={form.data.password} confirmation={form.data.password_confirmation} personal={[form.data.employee_number]} />
                                    </>}

                                    <button type="submit" disabled={form.processing || !form.data.employee_number.trim()} className={primaryClass}>
                                        {form.processing && <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent motion-reduce:animate-none" />}
                                        {t(form.processing ? (sendingCode ? 'auth.registration.sendingCode' : 'auth.registration.verifying') : (finishing ? 'auth.registration.verifyAndCreate' : 'auth.registration.sendCode'))}
                                        {!form.processing && <ArrowLeftIcon aria-hidden="true" className="h-4 w-4 rotate-180" />}
                                    </button>
                                </form>

                                <div className="mt-6 flex gap-3 rounded-[10px] bg-slate-50 p-4 dark:bg-slate-800/50">
                                    <InfoIcon aria-hidden="true" className="mt-0.5 h-[18px] w-[18px] shrink-0 text-slate-500" />
                                    <p className="text-[13px] leading-relaxed text-slate-600 dark:text-slate-400">
                                        <span className="font-semibold text-slate-800 dark:text-slate-200">{t('auth.registration.needHelp')}</span>{' '}
                                        {t('auth.registration.helpDescription')}
                                    </p>
                                </div>

                                <p className="mt-6 border-t border-slate-100 pt-5 text-center text-sm text-slate-600 dark:border-slate-800 dark:text-slate-400">
                                    {t('auth.registration.alreadyHaveAccount')}{' '}
                                    <Link href={route('login')} className={`${linkClass} inline-flex min-h-10 items-center`}>{t('auth.signIn')}</Link>
                                </p>
                            </section>

                            {/* Phones: the three steps the desktop panel shows. */}
                            <details className="rounded-[14px] border border-gray-200 bg-white px-4 py-3 lg:hidden dark:border-slate-800 dark:bg-slate-900">
                                <summary className="min-h-[36px] cursor-pointer py-1.5 text-sm font-bold">{t('auth.registration.howItWorks')}</summary>
                                <ol className="mt-2 space-y-3 pb-1">
                                    {STEPS.map(({ title, detail }, index) => (
                                        <li key={title} className="flex gap-3">
                                            <span aria-hidden="true" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[color:var(--color-primary)] text-xs font-bold text-white">{index + 1}</span>
                                            <span className="min-w-0 text-sm">
                                                <span className="block font-semibold">{t(`auth.registration.${title}`)}</span>
                                                <span className="block text-slate-600 dark:text-slate-400">{t(`auth.registration.${detail}`)}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            </details>

                            <p className="px-2 text-center text-xs leading-relaxed text-slate-500 lg:hidden dark:text-slate-400">{t('auth.registration.footer')}</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}
