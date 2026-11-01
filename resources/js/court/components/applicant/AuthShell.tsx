import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { Alert, AppToaster, LocalizedUiProvider, cx } from '@/Components/ui';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';

type Flash = { success?: string | null; error?: string | null; info?: string | null; warning?: string | null };

/** The brand mark the applicant pages show: the system logo, or the brand's first two letters. */
export function BrandMark({ className = 'h-9 w-9' }: { className?: string }) {
    const { brandName, logoUrl } = useBranding();
    return logoUrl
        ? <img src={logoUrl} alt="" className={cx('shrink-0 rounded-lg object-contain', className)} />
        : <span aria-hidden="true" className={cx('inline-flex shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-orange-500 to-orange-800 text-xs font-extrabold tracking-wide text-white', className)}>{Array.from(brandName).slice(0, 2).join('').toUpperCase()}</span>;
}

/** Brand on the left, language and theme on the right: the top strip of every applicant guest page. */
export function AuthTopBar({ children }: { children?: ReactNode }) {
    const { brandName } = useBranding();
    return (
        <header className="flex items-center justify-between gap-3 border-b border-(--app-border) px-5 py-4 sm:px-8">
            <a href="/" className="flex min-w-0 items-center gap-2.5">
                <BrandMark />
                <span className="truncate text-sm font-bold tracking-tight text-(--app-foreground)">{brandName}</span>
            </a>
            <div className="flex shrink-0 items-center gap-2">
                {children}
                <LanguageSwitcher variant="toolbar" />
                <ThemeToggle variant="toolbar" />
            </div>
        </header>
    );
}

/** The flash messages a guest page shows inline (as the Blade pages did) instead of as toasts. */
export function FlashAlerts({ className }: { className?: string }) {
    const flash = (usePage().props as { flash?: Flash }).flash ?? {};
    const items = ([['success', 'success'], ['info', 'info'], ['warning', 'warning'], ['error', 'danger']] as const)
        .filter(([key]) => Boolean(flash[key]));
    if (items.length === 0) return null;
    return (
        <div className={cx('space-y-2', className)}>
            {items.map(([key, tone]) => <Alert key={key} tone={tone}>{flash[key]}</Alert>)}
        </div>
    );
}

/** The first validation error of the page, as the Blade OTP and reset pages showed it above the form. */
export function FirstError({ className }: { className?: string }) {
    const errors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const first = Object.values(errors)[0];
    return first ? <Alert tone="danger" className={className}>{first}</Alert> : null;
}

/**
 * The shell of the applicant's small guest pages (password reset, reset code,
 * new password, email verification): the top strip, one card titled with the
 * page, its flash messages inline, and the way back to the sign-in page.
 */
export default function AuthShell({ title, subtitle, children, showBackToLogin = true, wide = false }: {
    title: string;
    subtitle?: string | null;
    children: ReactNode;
    showBackToLogin?: boolean;
    wide?: boolean;
}) {
    const { t } = useLocale();
    const { brandName } = useBranding();

    return (
        <LocalizedUiProvider>
            <div className="flex min-h-screen flex-col bg-(--app-surface-muted)">
                <AppToaster flashToasts={false} />
                <AuthTopBar />
                <main className="flex flex-1 items-start justify-center px-4 py-10 sm:items-center">
                    <div className={cx('w-full rounded-panel border border-(--app-border) bg-(--app-surface) px-6 py-7 shadow-sm sm:px-8', wide ? 'max-w-4xl' : 'max-w-md')}>
                        <div className="mb-6 flex items-start gap-3">
                            <BrandMark className="h-10 w-10" />
                            <div className="min-w-0">
                                <p className="truncate text-xs font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{brandName}</p>
                                <h1 className="mt-1 text-lg font-semibold text-(--app-foreground) md:text-xl">{title}</h1>
                                {subtitle && <p className="mt-1 text-sm text-(--app-muted-foreground)">{subtitle}</p>}
                            </div>
                        </div>
                        <FlashAlerts className="mb-4" />
                        {children}
                        {showBackToLogin && (
                            <div className="mt-6 border-t border-(--app-border) pt-4 text-center text-sm">
                                <Link href={route('court.applicant.login')} className="font-medium text-primary hover:underline">{t('cm.auth.back_to_login')}</Link>
                            </div>
                        )}
                    </div>
                </main>
                <footer className="pb-6 text-center text-xs text-(--app-muted-foreground)">&copy; {new Date().getFullYear()} {brandName}</footer>
            </div>
        </LocalizedUiProvider>
    );
}
