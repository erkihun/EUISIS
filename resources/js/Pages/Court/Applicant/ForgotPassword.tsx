import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Button, FormField, Input } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AuthShell from '@/court/components/applicant/AuthShell';
import type { ApplicantForgotPasswordProps } from '@/court/types/applicant';

/** Step one of the applicant's password reset: the email the six-digit reset code goes to. */
export default function ApplicantForgotPassword({ email, status }: ApplicantForgotPasswordProps) {
    const { t } = useLocale();
    const form = useForm({ email });
    // Read from the page: an expired reset session redirects here from the later steps with their error.
    const errors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const otherError = Object.entries(errors).find(([field]) => field !== 'email')?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.password.email'));
    };

    return (
        <AuthShell title={t('cm.auth.forgot_password_title')} subtitle={t('cm.auth.forgot_password_subtitle')}>
            <Head title={t('cm.auth.forgot_password_title')} />
            {status && <Alert tone="success" className="mb-4">{status}</Alert>}
            {otherError && <Alert tone="danger" className="mb-4">{otherError}</Alert>}
            <form onSubmit={submit} className="space-y-4">
                <FormField label={t('cm.auth.email')} error={errors.email}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} type="email" name="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required autoFocus autoComplete="email" aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                    )}
                </FormField>
                <Button type="submit" className="w-full" disabled={form.processing}>{t('cm.auth.send_reset_link')}</Button>
            </form>
        </AuthShell>
    );
}
