import { AuthField, ICONS, Notice, PasswordField, SubmitButton, useAccent } from '@/Components/ProviderPortal/AuthFields';
import { useLocale } from '@/hooks/useLocale';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

export type ProviderPortal = 'cafeteria' | 'transport';

export type SessionNotice = 'idle_timeout' | 'password_changed' | 'page_expired';

const OTHER: Record<ProviderPortal, ProviderPortal> = { cafeteria: 'transport', transport: 'cafeteria' };

/**
 * The sign-in form both portals share. It posts to the portal's own sign-in,
 * which admits only accounts whose provider runs that service; the server
 * answers anyone else with a pointer to the other portal.
 */
export default function ProviderSignInForm({
    portal,
    status,
    sessionNotice,
}: {
    portal: ProviderPortal;
    /** e.g. "Your password has been reset" after the forgot-password flow. */
    status?: string | null;
    sessionNotice?: SessionNotice | null;
}) {
    const { t } = useLocale();
    const accent = useAccent();
    const form = useForm({ identifier: '', password: '' });

    // A portal page that signs the user out (e.g. access revoked) redirects
    // here with its error; useForm only sees errors from its own submits.
    const pageErrors = usePage().props.errors;
    const other = OTHER[portal];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route(`provider.portal.${portal}.login.store`), { onFinish: () => form.reset('password') });
    }

    return (
        <>
            {status && <Notice tone="success">{status}</Notice>}
            {sessionNotice && <Notice tone="warning">{t(`auth.session.${sessionNotice}`)}</Notice>}

            <form onSubmit={submit} className="space-y-4">
                <AuthField
                    id="identifier"
                    label={t('providerPortal.identifierLabel')}
                    icon={ICONS.user}
                    type="text"
                    value={form.data.identifier}
                    onChange={(e) => form.setData('identifier', e.target.value)}
                    placeholder={t('providerPortal.identifierPlaceholder')}
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    autoFocus
                    required
                    error={form.errors.identifier ?? pageErrors.identifier}
                />

                <PasswordField
                    id="password"
                    label={t('providerPortal.password')}
                    labelAside={
                        <Link href={route('provider.portal.password.request', { portal })} className={`text-xs font-medium ${accent.link}`}>
                            {t('providerPortal.forgotPassword')}
                        </Link>
                    }
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    placeholder="••••••••"
                    autoComplete="current-password"
                    required
                    error={form.errors.password ?? pageErrors.password}
                />

                <SubmitButton processing={form.processing} label={t('providerPortal.signIn')} processingLabel={t('auth.signingIn')} />
            </form>

            <div className="mt-6 space-y-2 border-t border-gray-200 pt-5 text-center text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400">
                <p>
                    {t(`providerPortal.switchTo_${other}_prompt`)}{' '}
                    <Link href={route(`provider.portal.${other}.login`)} className={`font-medium ${accent.link}`}>
                        {t(`providerPortal.switchTo_${other}`)}
                    </Link>
                </p>
                <p>
                    {t('providerPortal.staffLoginPrompt')}{' '}
                    <Link href={route('login')} className={`font-medium ${accent.link}`}>
                        {t('providerPortal.staffLogin')}
                    </Link>
                </p>
            </div>
        </>
    );
}
