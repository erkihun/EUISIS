import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <div className="flex min-h-screen">
            <Head title="Forgot Password" />

            {/* ── Left branding panel ───────────────────────────────────── */}
            <div className="relative hidden w-[46%] shrink-0 overflow-hidden lg:flex lg:flex-col">
                <div className="absolute inset-0 bg-gradient-to-br from-blue-950 via-[#0c1228] to-slate-950" />

                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.035]"
                    style={{
                        backgroundImage: 'radial-gradient(rgba(255,255,255,0.8) 1px, transparent 1px)',
                        backgroundSize: '28px 28px',
                    }}
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
                            <span className="text-[11px] font-medium text-orange-300">Secure Government Portal</span>
                        </div>

                        <div className="space-y-3">
                            <h1 className="text-[2.2rem] font-bold leading-[1.2] text-white">
                                Account<br />
                                <span className="text-orange-400">Recovery</span>
                            </h1>
                            <p className="text-sm leading-relaxed text-slate-400">
                                Enter your registered email address and we'll send you a secure link to reset your password.
                            </p>
                        </div>

                        <div className="w-16 border-t border-white/10" />

                        <div className="space-y-4 text-left">
                            {[
                                { icon: 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75', label: 'Reset link sent to your email' },
                                { icon: 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z', label: 'Secure one-time reset token' },
                                { icon: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z', label: 'Link expires after 60 minutes' },
                            ].map(({ icon, label }) => (
                                <div key={label} className="flex items-center gap-3">
                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-500/10">
                                        <svg className="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d={icon} />
                                        </svg>
                                    </div>
                                    <span className="text-[13px] text-slate-300">{label}</span>
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
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 0 1 21.75 8.25Z" />
                                    </svg>
                                </div>
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Forgot password?</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                    Enter your email and we'll send a reset link.
                                </p>
                            </div>

                            {/* Success status */}
                            {status && (
                                <div className="mb-5 flex items-start gap-2.5 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-900/20 dark:text-emerald-400">
                                    <svg className="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                    </svg>
                                    {status}
                                </div>
                            )}

                            <form onSubmit={submit} className="space-y-4">
                                <div>
                                    <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                                        Email address
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
                                            Sending…
                                        </>
                                    ) : (
                                        <>
                                            Send reset link
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                            </svg>
                                        </>
                                    )}
                                </button>
                            </form>

                            <div className="mt-6 text-center">
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center gap-1.5 text-sm text-gray-500 transition-colors hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                                    </svg>
                                    Back to sign in
                                </Link>
                            </div>
                        </div>

                        <p className="mt-5 text-center text-[11px] text-gray-400 dark:text-slate-600">
                            © {new Date().getFullYear()} Addis Ababa City Administration · Government of Ethiopia
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
