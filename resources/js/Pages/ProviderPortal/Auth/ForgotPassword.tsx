import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { AuthField, CardHeading, ICONS, Notice, PasswordField, SubmitButton } from '@/Components/ProviderPortal/AuthFields';
import { useLocale } from '@/hooks/useLocale';
import ProviderAuthLayout from '@/Layouts/ProviderAuthLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type Pending = {
    /** Exactly what the user typed; echoed back, never looked up from the account. */
    identifier: string;
    channel: 'email' | 'sms';
    /** Seconds until another code may be requested. */
    resend_in: number;
};

export default function ForgotPassword({
    pending,
    smsAvailable,
    codeTtlMinutes,
}: {
    pending: Pending | null;
    smsAvailable: boolean;
    codeTtlMinutes: number;
}) {
    const { t } = useLocale();

    return (
        <ProviderAuthLayout title={t('providerPortal.resetTitle')}>
            <CardHeading
                icon={ICONS.key}
                title={t('providerPortal.resetTitle')}
                subtitle={t(pending
                    ? 'providerPortal.resetCodeSubtitle'
                    : smsAvailable ? 'providerPortal.resetSubtitle' : 'providerPortal.resetSubtitleEmailOnly')}
            />

            {pending ? <CodeStep pending={pending} codeTtlMinutes={codeTtlMinutes} /> : <RequestStep smsAvailable={smsAvailable} />}

            <div className="mt-6 border-t border-gray-200 pt-5 text-center dark:border-slate-800">
                <Link
                    href={route('provider.portal.login')}
                    className="inline-flex items-center gap-1.5 text-sm text-gray-500 transition-colors hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    {t('providerPortal.backToSignIn')}
                </Link>
            </div>
        </ProviderAuthLayout>
    );
}

function RequestStep({ smsAvailable }: { smsAvailable: boolean }) {
    const { t } = useLocale();
    const form = useForm({ identifier: '' });
    // "Start again" errors arrive on a redirect, outside this form's submits.
    const pageErrors = usePage().props.errors;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('provider.portal.password.send'));
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <AuthField
                id="identifier"
                label={t(smsAvailable ? 'providerPortal.resetIdentifierLabel' : 'providerPortal.resetEmailLabel')}
                icon={ICONS.at}
                type={smsAvailable ? 'text' : 'email'}
                value={form.data.identifier}
                onChange={(e) => form.setData('identifier', e.target.value)}
                placeholder={smsAvailable ? t('providerPortal.resetIdentifierPlaceholder') : 'email@example.com'}
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                autoFocus
                required
                error={form.errors.identifier ?? pageErrors.identifier}
            />

            <SubmitButton processing={form.processing} label={t('providerPortal.sendCode')} processingLabel={t('providerPortal.sendingCode')} />
        </form>
    );
}

function CodeStep({ pending, codeTtlMinutes }: { pending: Pending; codeTtlMinutes: number }) {
    const { t } = useLocale();
    const form = useForm({ code: '', password: '', password_confirmation: '' });
    const [resendIn, setResendIn] = useState(pending.resend_in);
    const [resending, setResending] = useState(false);

    // Each (re)send returns a fresh `pending`, which restarts the countdown.
    useEffect(() => {
        setResendIn(pending.resend_in);
        const timer = window.setInterval(() => setResendIn((s) => Math.max(0, s - 1)), 1000);

        return () => window.clearInterval(timer);
    }, [pending]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('provider.portal.password.reset'));
    }

    function resend() {
        router.post(route('provider.portal.password.send'), { identifier: pending.identifier }, {
            preserveScroll: true,
            onStart: () => setResending(true),
            onFinish: () => setResending(false),
            onSuccess: () => {
                form.reset('code');
                form.clearErrors();
            },
        });
    }

    return (
        <>
            <Notice tone="info">
                {t('providerPortal.resetCodeSentTo').replace(':identifier', pending.identifier).replace(':minutes', String(codeTtlMinutes))}
            </Notice>

            <form onSubmit={submit} className="space-y-4">
                <AuthField
                    id="code"
                    label={t('providerPortal.resetCodeLabel')}
                    icon={ICONS.key}
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="\d{6}"
                    placeholder="000000"
                    value={form.data.code}
                    // No maxLength: it would cut a pasted "123 456" before the
                    // separators are stripped here.
                    onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                    autoFocus
                    required
                    error={form.errors.code}
                />

                <PasswordField
                    id="password"
                    label={t('providerPortal.newPassword')}
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    autoComplete="new-password"
                    required
                    error={form.errors.password}
                />

                <PasswordField
                    id="password_confirmation"
                    label={t('providerPortal.confirmNewPassword')}
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                    required
                    error={form.errors.password_confirmation}
                />

                <PasswordPolicyChecklist password={form.data.password} confirmation={form.data.password_confirmation} personal={[pending.identifier]} />

                <SubmitButton processing={form.processing} label={t('providerPortal.resetPassword')} processingLabel={t('providerPortal.resettingPassword')} />
            </form>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                <button
                    type="button"
                    onClick={resend}
                    disabled={resendIn > 0 || resending}
                    className="font-medium text-orange-600 hover:text-orange-700 disabled:cursor-not-allowed disabled:text-gray-400 dark:text-orange-400 dark:hover:text-orange-300 dark:disabled:text-slate-500"
                >
                    {resendIn > 0 ? t('providerPortal.resendCodeIn').replace(':seconds', String(resendIn)) : t('providerPortal.resendCode')}
                </button>
                <button
                    type="button"
                    onClick={() => router.delete(route('provider.portal.password.cancel'))}
                    className="font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                >
                    {t('providerPortal.useDifferentIdentifier')}
                </button>
            </div>

            <p className="mt-3 text-xs text-gray-500 dark:text-slate-400">{t('providerPortal.resetNoCodeHint')}</p>
        </>
    );
}
