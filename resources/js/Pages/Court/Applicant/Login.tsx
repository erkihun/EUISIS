import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { X } from '@/Components/Icons';
import { Alert, AppToaster, Button, FormLabel, Input, LocalizedUiProvider, cx } from '@/Components/ui';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { AuthTopBar, BrandMark, FlashAlerts } from '@/court/components/applicant/AuthShell';
import PasswordInput from '@/court/components/applicant/PasswordInput';
import type { ApplicantLoginProps, LoginAs } from '@/court/types/applicant';

/**
 * The applicant portal's sign-in (the Blade applicant/auth/login): one form
 * for both portals — the tabs choose whether the person signs in to file
 * cases (applicant) or to answer them (respondent) — beside the published
 * About page, or the brand when there is none. On a phone the About page
 * opens over the form from "View details".
 */
export default function ApplicantLogin({ loginAs, email, bannerUrl, about }: ApplicantLoginProps) {
    const { t } = useLocale();
    const { brandName } = useBranding();
    const [aboutOpen, setAboutOpen] = useState(false);
    const form = useForm({ email, password: '', remember: false, login_as: loginAs as LoginAs });

    useEffect(() => {
        if (!aboutOpen) return undefined;
        const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setAboutOpen(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [aboutOpen]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.login.submit'), { onFinish: () => form.reset('password') });
    };

    // Every error on the page, as the Blade list showed them (the sign-in reports them all on `email`).
    const pageErrors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const errors = [...new Set(Object.values(pageErrors).filter(Boolean))];
    const roles: { id: LoginAs; label: string }[] = [
        { id: 'applicant', label: t('cm.auth.role_applicant') },
        { id: 'respondent', label: t('cm.auth.role_respondent') },
    ];
    const panelLabel = form.data.login_as === 'respondent' ? t('cm.auth.respondent_panel_label') : t('cm.auth.applicant_panel_label');

    return (
        <LocalizedUiProvider>
            <Head title={t('cm.auth.sign_in_title')} />
            <AppToaster flashToasts={false} />
            <div className="flex min-h-screen bg-(--app-surface)">
                <div className="flex w-full flex-col lg:w-[460px] lg:shrink-0 lg:border-r lg:border-(--app-border)">
                    <AuthTopBar>
                        <button type="button" onClick={() => setAboutOpen(true)} className="rounded-md border border-(--app-border) px-2.5 py-1.5 text-xs font-semibold text-(--app-foreground) hover:bg-(--app-surface-muted) lg:hidden">
                            {t('cm.app.View details')}
                        </button>
                    </AuthTopBar>

                    <main className="flex flex-1 items-center justify-center px-6 py-10 sm:px-10">
                        <div className="w-full max-w-sm">
                            <h1 className="text-2xl font-bold tracking-tight text-(--app-foreground)">{t('cm.auth.sign_in_title')}</h1>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('cm.auth.sign_in_subtitle')}</p>

                            <div className="mt-6 grid grid-cols-2 gap-1 rounded-control bg-(--app-surface-muted) p-1" role="group" aria-label={t('cm.auth.sign_in_as')}>
                                {roles.map((role) => (
                                    <button
                                        key={role.id}
                                        type="button"
                                        aria-pressed={form.data.login_as === role.id}
                                        onClick={() => form.setData('login_as', role.id)}
                                        className={cx(
                                            'rounded-md px-3 py-2 text-sm font-semibold transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                            form.data.login_as === role.id ? 'bg-(--app-surface) text-primary shadow-xs' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)',
                                        )}
                                    >
                                        {role.label}
                                    </button>
                                ))}
                            </div>
                            <p className="mt-2 text-xs font-semibold uppercase tracking-wider text-primary">{panelLabel}</p>

                            <FlashAlerts className="mt-5" />
                            {errors.length > 0 && (
                                <Alert tone="danger" className="mt-5">
                                    <ul className="space-y-0.5">{errors.map((message) => <li key={message}>{message}</li>)}</ul>
                                </Alert>
                            )}

                            <form onSubmit={submit} className="mt-6 space-y-5">
                                <div className="space-y-1.5">
                                    <FormLabel htmlFor="email">{t('cm.auth.email')}</FormLabel>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        value={form.data.email}
                                        onChange={(event) => form.setData('email', event.target.value)}
                                        placeholder={t('cm.auth.email_placeholder')}
                                        required
                                        autoFocus
                                        autoComplete="email"
                                        aria-invalid={pageErrors.email ? true : undefined}
                                        className="w-full"
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <div className="flex items-center justify-between gap-2">
                                        <FormLabel htmlFor="password">{t('cm.auth.password')}</FormLabel>
                                        <Link href={route('court.applicant.password.request')} className="text-xs font-medium text-primary hover:underline">{t('cm.auth.forgot_password')}</Link>
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        value={form.data.password}
                                        onChange={(event) => form.setData('password', event.target.value)}
                                        placeholder={t('cm.auth.password_placeholder')}
                                        required
                                        autoComplete="current-password"
                                        aria-invalid={pageErrors.password ? true : undefined}
                                    />
                                </div>

                                <label className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                    <input type="checkbox" name="remember" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} className="h-4 w-4 rounded border-(--app-border-strong) text-primary focus:ring-primary" />
                                    <span>{t('cm.auth.remember_me')}</span>
                                </label>

                                <Button type="submit" className="w-full" disabled={form.processing}>{t('cm.auth.sign_in')}</Button>
                            </form>

                            <p className="mt-6 text-center text-sm text-(--app-muted-foreground)">
                                {t('cm.auth.no_account')}{' '}
                                <Link href={route('court.applicant.register')} className="font-semibold text-primary hover:underline">{t('cm.auth.create_account')}</Link>
                            </p>
                        </div>
                    </main>

                    <footer className="pb-6 text-center text-xs text-(--app-muted-foreground)">&copy; {new Date().getFullYear()} {brandName}</footer>
                </div>

                <aside
                    className={cx(
                        'relative isolate flex-1 flex-col overflow-hidden bg-slate-900 text-white',
                        aboutOpen ? 'fixed inset-0 z-50 flex' : 'hidden lg:flex',
                    )}
                    aria-label={about?.title ?? brandName}
                >
                    {bannerUrl ? (
                        <>
                            <div className="absolute inset-0 -z-10 bg-cover bg-center" style={{ backgroundImage: `url("${bannerUrl}")` }} aria-hidden="true" />
                            <div className="absolute inset-0 -z-10 bg-slate-950/75" aria-hidden="true" />
                        </>
                    ) : (
                        <div className="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_20%_20%,rgba(249,115,22,0.28),transparent_45%),radial-gradient(circle_at_80%_80%,rgba(59,130,246,0.22),transparent_45%)]" aria-hidden="true" />
                    )}

                    <button type="button" onClick={() => setAboutOpen(false)} aria-label={t('cm.app.Close details')} className="absolute right-4 top-4 rounded-md p-2 text-white/80 hover:bg-white/10 hover:text-white lg:hidden">
                        <X className="h-5 w-5" />
                    </button>

                    {about ? (
                        <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-8 py-14 lg:px-14">
                            <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">{about.title}</h2>
                            <div className="mt-4 h-1 w-16 rounded-full bg-orange-500" aria-hidden="true" />
                            <div className="public-prose mt-6 min-h-0 flex-1 overflow-y-auto pr-2 text-sm text-white/85 [&_a]:text-orange-200 [&_th]:bg-white/10" dangerouslySetInnerHTML={{ __html: about.body }} />
                        </div>
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center px-8 text-center">
                            <BrandMark className="h-16 w-16 text-lg" />
                            <p className="mt-5 text-2xl font-bold">{brandName}</p>
                            <p className="mt-2 text-sm text-white/70">{t('cm.auth.sign_in_subtitle')}</p>
                        </div>
                    )}
                </aside>
            </div>
        </LocalizedUiProvider>
    );
}
