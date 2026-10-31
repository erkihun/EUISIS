import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert, Button, FormField } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AuthShell from '@/court/components/applicant/AuthShell';
import PasswordInput from '@/court/components/applicant/PasswordInput';

/** Last step of the applicant's password reset, once the code is verified: the new password. Saving it signs the person in. */
export default function ApplicantNewPassword() {
    const { t } = useLocale();
    const form = useForm({ password: '', password_confirmation: '' });
    const errors = Object.values(form.errors).filter(Boolean);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.password.new.update'), { onFinish: () => form.reset() });
    };

    return (
        <AuthShell title={t('cm.auth.reset_password_title')} subtitle={t('cm.auth.reset_password_subtitle')}>
            <Head title={t('cm.auth.reset_password_title')} />
            {errors.length > 0 && (
                <Alert tone="danger" className="mb-4">
                    <ul className="list-disc space-y-0.5 pl-5">{errors.map((message) => <li key={message}>{message}</li>)}</ul>
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-4">
                <FormField label={t('cm.auth.new_password')} error={form.errors.password}>
                    {({ id, describedBy, invalid }) => (
                        <PasswordInput id={id} name="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} required autoFocus autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                    )}
                </FormField>
                <FormField label={t('cm.auth.confirm_new_password')} error={form.errors.password_confirmation}>
                    {({ id, describedBy, invalid }) => (
                        <PasswordInput id={id} name="password_confirmation" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} required autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                    )}
                </FormField>
                <Button type="submit" className="w-full" disabled={form.processing}>{t('cm.auth.reset_password_button')}</Button>
            </form>
        </AuthShell>
    );
}
