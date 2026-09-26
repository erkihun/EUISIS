import { AuthField, CardHeading, ICONS, Notice, PasswordField, SubmitButton } from '@/Components/ProviderPortal/AuthFields';
import { useLocale } from '@/hooks/useLocale';
import ProviderAuthLayout from '@/Layouts/ProviderAuthLayout';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Login({
    status,
    sessionNotice,
}: {
    /** e.g. "Your password has been reset" after the forgot-password flow. */
    status?: string | null;
    sessionNotice?: 'idle_timeout' | 'password_changed' | 'page_expired' | null;
}) {
    const { t } = useLocale();
    const form = useForm({ identifier: '', password: '' });

    // A portal page that signs the user out (e.g. access revoked) redirects
    // here with its error; useForm only sees errors from its own submits.
    const pageErrors = usePage().props.errors;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('provider.portal.login.store'), { onFinish: () => form.reset('password') });
    }

    return (
        <ProviderAuthLayout title={t('providerPortal.login')}>
            <CardHeading icon={ICONS.store} title={t('providerPortal.login')} subtitle={t('providerPortal.loginSubtitle')} />

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
                        <Link
                            href={route('provider.portal.password.request')}
                            className="text-xs font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300"
                        >
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

            <p className="mt-6 border-t border-gray-200 pt-5 text-center text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400">
                {t('providerPortal.staffLoginPrompt')}{' '}
                <Link href={route('login')} className="font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300">
                    {t('providerPortal.staffLogin')}
                </Link>
            </p>
        </ProviderAuthLayout>
    );
}
