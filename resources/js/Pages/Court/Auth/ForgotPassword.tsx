import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { KeyIcon } from '@/Components/Icons';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { ForgotPasswordProps } from '@/court/types/public';

/**
 * Staff password recovery, step 1: the email address that receives a 6-digit
 * reset code (PasswordResetLinkController::store), then the code screen.
 */
export default function ForgotPassword({ status, email }: ForgotPasswordProps) {
    const { t } = usePublicText();
    const pageErrors = usePage().props.errors as Record<string, string>;
    const form = useForm({ email });

    // A redirect from a later step (an expired session) arrives with the page, not this form's request.
    const emailError = form.errors.email || pageErrors.email;
    const otherError = Object.entries(pageErrors).find(([key]) => key !== 'email')?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('password.email'));
    };

    return (
        <CourtAuthShell title={t('cm.auth.forgot_password_title')} subtitle={t('cm.auth.forgot_password_description')} icon={<KeyIcon className="h-5 w-5" />}>
            {status && <CourtAuthAlert tone="success">{status}</CourtAuthAlert>}
            {otherError && <CourtAuthAlert tone="error">{otherError}</CourtAuthAlert>}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="email" className={courtAuthLabelClass}>{t('cm.auth.email')}</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        required
                        autoFocus
                        autoComplete="username"
                        aria-invalid={emailError ? true : undefined}
                        aria-describedby={emailError ? 'email-error' : undefined}
                        className={courtAuthInputClass(Boolean(emailError))}
                    />
                    {emailError && <p id="email-error" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{emailError}</p>}
                </div>

                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>
                    {t('cm.auth.send_reset_link')}
                </button>
            </form>
        </CourtAuthShell>
    );
}
