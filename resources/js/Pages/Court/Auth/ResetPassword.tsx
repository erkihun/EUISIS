import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ShieldCheck } from '@/Components/Icons';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';

/**
 * Staff password recovery, step 3: the new password for the verified email
 * (NewPasswordController::store), then back to sign-in.
 */
export default function ResetPassword() {
    const { t } = usePublicText();
    const pageErrors = usePage().props.errors as Record<string, string>;
    const form = useForm({ password: '', password_confirmation: '' });
    const errors = { ...pageErrors, ...form.errors } as Record<string, string | undefined>;
    const messages = Object.values(errors).filter((message): message is string => Boolean(message));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <CourtAuthShell title={t('cm.auth.reset_password_title')} subtitle={t('cm.auth.reset_password_subtitle')} icon={<ShieldCheck className="h-5 w-5" />}>
            {messages.length > 0 && (
                <CourtAuthAlert tone="error">
                    <ul className="ml-5 list-disc">
                        {messages.map((message) => <li key={message}>{message}</li>)}
                    </ul>
                </CourtAuthAlert>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="password" className={courtAuthLabelClass}>{t('cm.auth.new_password')}</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        required
                        autoFocus
                        autoComplete="new-password"
                        aria-invalid={errors.password ? true : undefined}
                        className={courtAuthInputClass(Boolean(errors.password))}
                    />
                    {errors.password && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.password}</p>}
                </div>

                <div>
                    <label htmlFor="password_confirmation" className={courtAuthLabelClass}>{t('cm.auth.confirm_password')}</label>
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        required
                        autoComplete="new-password"
                        aria-invalid={errors.password_confirmation ? true : undefined}
                        className={courtAuthInputClass(Boolean(errors.password_confirmation))}
                    />
                    {errors.password_confirmation && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{errors.password_confirmation}</p>}
                </div>

                <PasswordPolicyChecklist password={form.data.password} confirmation={form.data.password_confirmation} />

                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>
                    {t('cm.auth.reset_password_button')}
                </button>
            </form>
        </CourtAuthShell>
    );
}
