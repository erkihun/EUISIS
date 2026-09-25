import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import ThemeToggle from '@/Components/ThemeToggle';
import LanguageSwitcher from '@/Components/LanguageSwitcher';

export default function Login({ sessionNotice }: { sessionNotice?: 'idle_timeout' | 'password_changed' | 'page_expired' | null }) {
    const { t } = useLocale();
    const form = useForm({ identifier: '', password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('provider.portal.login.store'));
    }

    const inputCls = 'w-full rounded-card border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 transition focus:border-orange-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-orange-400/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 dark:focus:border-orange-500 dark:focus:bg-slate-750';

    return (
        <div className="flex min-h-screen dark:bg-slate-950" style={{ background: 'linear-gradient(135deg, #fff7ed 0%, #fff 50%, #f0fdf4 100%)' }}>
            <Head title="Cafeteria Provider Login" />

            {/* Left panel — branding */}
            <div className="hidden lg:flex lg:w-1/2 lg:flex-col lg:items-center lg:justify-center lg:px-12" style={{ background: 'linear-gradient(180deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)' }}>
                <div className="max-w-sm text-center">
                    {/* Logo */}
                    <div className="mx-auto mb-8 flex h-20 w-20 items-center justify-center rounded-panel bg-gradient-to-br from-orange-400 to-orange-600 shadow-2xl shadow-orange-500/30">
                        <svg className="h-10 w-10 text-white" fill="none" stroke="currentColor" strokeWidth={1.75} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <h1 className="text-3xl font-bold text-white mb-3">Cafeteria Portal</h1>
                    <p className="text-slate-400 text-base leading-relaxed">
                        Modern cafeteria management for providers — scan cards, track meals, manage subsidies.
                    </p>

                    {/* Features */}
                    <div className="mt-10 space-y-4 text-left">
                        {[
                            { icon: '⚡', text: t('providerPortal.featureRealtime') || 'Real-time QR card scanning' },
                            { icon: '📊', text: t('providerPortal.featureTracking') || 'Transaction & ledger tracking' },
                            { icon: '🍽️', text: t('providerPortal.featureMenu') || 'Daily menu management' },
                            { icon: '📈', text: t('providerPortal.featureReports') || 'Subsidy reports & analytics' },
                        ].map(f => (
                            <div key={f.text} className="flex items-center gap-3 text-slate-300">
                                <span className="text-lg">{f.icon}</span>
                                <span className="text-sm">{f.text}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Right panel — login form */}
            <div className="flex flex-1 flex-col items-center justify-center px-6 py-12 lg:px-16">
                {/* Top bar controls */}
                <div className="absolute right-4 top-4 flex items-center gap-2">
                    <LanguageSwitcher />
                    <ThemeToggle />
                </div>

                <div className="w-full max-w-sm">
                    {/* Mobile logo */}
                    <div className="lg:hidden flex justify-center mb-8">
                        <div className="flex h-14 w-14 items-center justify-center rounded-panel bg-gradient-to-br from-orange-400 to-orange-600 shadow-lg">
                            <svg className="h-7 w-7 text-white" fill="none" stroke="currentColor" strokeWidth={1.75} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>

                    <h2 className="text-2xl font-bold text-gray-900 dark:text-slate-100 mb-1">
                        {t('providerPortal.login')}
                    </h2>
                    <p className="text-sm text-gray-500 dark:text-slate-400 mb-8">
                        {t('providerPortal.loginSubtitle') || 'Sign in to your cafeteria provider account'}
                    </p>

                    {sessionNotice && (
                        <p role="status" className="mb-5 rounded-card border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300">
                            {t(`auth.session.${sessionNotice}`)}
                        </p>
                    )}
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                {t('providerPortal.identifierLabel') || 'Email or Username'}
                            </label>
                            <input
                                className={inputCls}
                                type="text"
                                placeholder={t('providerPortal.identifierPlaceholder') || 'email@example.com or username'}
                                value={form.data.identifier}
                                onChange={e => form.setData('identifier', e.target.value)}
                                required
                                autoFocus
                                autoComplete="username"
                            />
                            <InputError message={form.errors.identifier} className="mt-2" />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">
                                {t('providerPortal.password')}
                            </label>
                            <input
                                className={inputCls}
                                type="password"
                                placeholder="••••••••"
                                value={form.data.password}
                                onChange={e => form.setData('password', e.target.value)}
                                required
                                autoComplete="current-password"
                            />
                            <InputError message={form.errors.password} className="mt-2" />
                        </div>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="relative w-full overflow-hidden rounded-card px-4 py-3 text-sm font-semibold text-white transition-all focus:outline-none focus:ring-2 focus:ring-orange-400 focus:ring-offset-2 disabled:opacity-60"
                            style={{ background: 'linear-gradient(135deg, #f97316 0%, #ea580c 100%)', boxShadow: '0 4px 15px rgba(249, 115, 22, 0.35)' }}
                        >
                            {form.processing ? (
                                <span className="flex items-center justify-center gap-2">
                                    <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                    </svg>
                                    {t('providerPortal.signingIn') || 'Signing in…'}
                                </span>
                            ) : t('providerPortal.signIn')}
                        </button>
                    </form>

                    <p className="mt-8 text-center text-xs text-gray-400 dark:text-slate-500">
                        {t('providerPortal.authorizedOnly') || 'Cafeteria Provider Portal · Authorized access only'}
                    </p>
                </div>
            </div>
        </div>
    );
}
