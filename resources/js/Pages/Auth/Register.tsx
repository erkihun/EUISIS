import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        employee_number: '',
        password: '',
        password_confirmation: '',
    });

    const [showPassword, setShowPassword] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <div className="flex min-h-screen">
            <Head title="Create Account" />

            {/* ── Left branding panel ───────────────────────────────────── */}
            <div className="relative hidden w-[46%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                <div className="absolute inset-0 bg-gradient-to-br from-blue-950 via-[#0c1228] to-slate-950" />
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.035]"
                    style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,0.8) 1px, transparent 1px)', backgroundSize: '28px 28px' }}
                />
                <div className="pointer-events-none absolute -top-48 -left-48 h-[600px] w-[600px] rounded-full bg-[color:var(--color-primary)]/25 blur-[130px]" />
                <div className="pointer-events-none absolute -bottom-32 right-0 h-[400px] w-[400px] rounded-full bg-orange-600/15 blur-[100px]" />
                <div className="pointer-events-none absolute -right-28 top-1/2 -translate-y-1/2">
                    <div className="h-[520px] w-[520px] rounded-full border border-white/[0.06]" />
                    <div className="absolute inset-[52px] rounded-full border border-white/[0.05]" />
                    <div className="absolute inset-[104px] rounded-full border border-white/[0.04]" />
                    <div className="absolute inset-[156px] rounded-full border border-white/[0.03]" />
                </div>

                <div className="relative z-10 flex h-full flex-col items-center justify-between px-10 py-10 text-center">
                    <div className="flex flex-col items-center gap-3">
                        <ApplicationLogo className="h-24 w-24 fill-white drop-shadow-lg" />
                        <div>
                            <p className="text-lg font-bold tracking-wide text-white">EUISIS</p>
                            <p className="text-[11px] text-orange-300/60">Employee Unified Identity System</p>
                        </div>
                    </div>

                    <div className="flex max-w-sm flex-col items-center gap-6">
                        <div className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-4 py-1.5">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-orange-400" />
                            <span className="text-[11px] font-medium text-orange-300">Employee Self-Service</span>
                        </div>

                        <div className="space-y-3">
                            <h1 className="text-[2.2rem] font-bold leading-[1.2] text-white">
                                Create your<br />
                                <span className="text-orange-400">Portal</span> Account
                            </h1>
                            <p className="text-sm leading-relaxed text-slate-400">
                                Already registered as an employee? Create your login using
                                your employee number — no need to re-enter your details.
                            </p>
                        </div>

                        <div className="w-16 border-t border-white/10" />

                        <div className="space-y-3 text-left">
                            {[
                                { step: '1', text: 'Enter your employee number assigned by HR.' },
                                { step: '2', text: 'Choose a secure password for your account.' },
                                { step: '3', text: 'Sign in to browse announcements and manage your applications.' },
                            ].map(({ step, text }) => (
                                <div key={step} className="flex items-start gap-3">
                                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[color:var(--color-primary)]/40 text-[11px] font-bold text-blue-300">
                                        {step}
                                    </span>
                                    <p className="text-[13px] leading-relaxed text-slate-400">{text}</p>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex w-full items-center gap-3">
                        <div className="h-px flex-1 bg-white/[0.06]" />
                        <p className="text-[11px] text-slate-600">Government of Ethiopia · Addis Ababa City Administration</p>
                        <div className="h-px flex-1 bg-white/[0.06]" />
                    </div>
                </div>
            </div>

            {/* ── Right form panel ──────────────────────────────────────── */}
            <div className="flex flex-1 flex-col bg-gray-50 dark:bg-[#0d0f14]">

                {/* Mobile top bar */}
                <div className="flex items-center gap-2.5 px-6 py-5 lg:hidden">
                    <ApplicationLogo className="h-8 w-8 fill-slate-800 dark:fill-white" />
                    <span className="text-sm font-bold text-slate-900 dark:text-white">EUISIS</span>
                </div>

                <div className="flex flex-1 items-center justify-center px-6 py-10">
                    <div className="w-full max-w-[400px]">

                        <div className="rounded-panel border border-gray-200 bg-white px-8 py-8 dark:border-slate-800 dark:bg-slate-900">

                            {/* Heading */}
                            <div className="mb-7">
                                <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-panel bg-[color:var(--color-primary)] shadow-md shadow-blue-600/30">
                                    <svg className="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3M13.5 19.5l-.397-1.191A4.5 4.5 0 0 0 9 15H4.5a2.25 2.25 0 0 1-2.25-2.25v-9A2.25 2.25 0 0 1 4.5 1.5h15a2.25 2.25 0 0 1 2.25 2.25v3.75" />
                                    </svg>
                                </div>
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Create account</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                    Use your employee number to register
                                </p>
                            </div>

                            <form onSubmit={submit} className="space-y-4">

                                {/* Employee number */}
                                <div>
                                    <label htmlFor="employee_number" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                                        Employee Number
                                    </label>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <svg className="h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                            </svg>
                                        </div>
                                        <input
                                            id="employee_number"
                                            name="employee_number"
                                            type="text"
                                            value={data.employee_number}
                                            autoFocus
                                            autoComplete="off"
                                            onChange={(e) => setData('employee_number', e.target.value)}
                                            placeholder="e.g. EMP-001234"
                                            className={`w-full rounded-card border py-2.5 pl-10 pr-4 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-0 dark:text-slate-100 dark:placeholder:text-slate-500 ${
                                                errors.employee_number
                                                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                                    : 'border-gray-300 bg-white focus:border-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-800'
                                            }`}
                                        />
                                    </div>
                                    {errors.employee_number && (
                                        <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
                                            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                            </svg>
                                            {errors.employee_number}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                        Your employee number is on your appointment letter or ID card.
                                    </p>
                                </div>

                                {/* Password */}
                                <div>
                                    <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                                        Password
                                    </label>
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
                                            autoComplete="new-password"
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder="Min. 8 characters"
                                            className={`w-full rounded-card border py-2.5 pl-10 pr-11 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:text-slate-100 dark:placeholder:text-slate-500 ${
                                                errors.password
                                                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                                    : 'border-gray-300 bg-white focus:border-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-800'
                                            }`}
                                        />
                                        <button type="button" tabIndex={-1} onClick={() => setShowPassword(v => !v)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300">
                                            {showPassword
                                                ? <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                                                : <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" /></svg>
                                            }
                                        </button>
                                    </div>
                                    {errors.password && (
                                        <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
                                            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" /></svg>
                                            {errors.password}
                                        </p>
                                    )}
                                </div>

                                {/* Confirm password */}
                                <div>
                                    <label htmlFor="password_confirmation" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                                        Confirm Password
                                    </label>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <svg className="h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                            </svg>
                                        </div>
                                        <input
                                            id="password_confirmation"
                                            type={showConfirm ? 'text' : 'password'}
                                            name="password_confirmation"
                                            value={data.password_confirmation}
                                            autoComplete="new-password"
                                            onChange={(e) => setData('password_confirmation', e.target.value)}
                                            placeholder="Repeat password"
                                            className={`w-full rounded-card border py-2.5 pl-10 pr-11 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] dark:text-slate-100 dark:placeholder:text-slate-500 ${
                                                errors.password_confirmation
                                                    ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                                    : 'border-gray-300 bg-white focus:border-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-800'
                                            }`}
                                        />
                                        <button type="button" tabIndex={-1} onClick={() => setShowConfirm(v => !v)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300">
                                            {showConfirm
                                                ? <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                                                : <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" /></svg>
                                            }
                                        </button>
                                    </div>
                                    {errors.password_confirmation && (
                                        <p className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
                                            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" /></svg>
                                            {errors.password_confirmation}
                                        </p>
                                    )}
                                </div>

                                {/* Submit */}
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="flex w-full items-center justify-center gap-2 rounded-card bg-[color:var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-blue-600/25 transition-all hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                                >
                                    {processing ? (
                                        <>
                                            <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                            </svg>
                                            Creating account…
                                        </>
                                    ) : (
                                        <>
                                            Create account
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                            </svg>
                                        </>
                                    )}
                                </button>
                            </form>
                        </div>

                        <p className="mt-5 text-center text-sm text-gray-500 dark:text-slate-400">
                            Already have an account?{' '}
                            <Link href={route('login')} className="font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)]">
                                Sign in
                            </Link>
                        </p>

                        <p className="mt-2 text-center text-[11px] text-gray-400 dark:text-slate-600">
                            © {new Date().getFullYear()} Addis Ababa City Administration · Government of Ethiopia
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
