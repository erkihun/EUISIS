import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, FormLabel } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AuthShell, { FirstError } from '@/court/components/applicant/AuthShell';
import OtpCodeInput from '@/court/components/applicant/OtpCodeInput';

/** Step two of the applicant's password reset: the six-digit code from the email, or a new one. */
export default function ApplicantPasswordOtp() {
    const { t } = useLocale();
    const form = useForm({ code: '' });
    const [resending, setResending] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.password.otp.verify'), { onError: () => form.reset('code') });
    };

    const resend = () => router.post(route('court.applicant.password.otp.resend'), {}, {
        preserveScroll: true,
        onStart: () => setResending(true),
        onFinish: () => setResending(false),
    });

    return (
        <AuthShell title={t('cm.auth.password_otp_title')} subtitle={t('cm.auth.password_otp_description')}>
            <Head title={t('cm.auth.password_otp_title')} />
            <FirstError className="mb-4" />
            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <FormLabel htmlFor="code" className="block text-center">{t('cm.auth.verification_code')}</FormLabel>
                    <OtpCodeInput id="code" value={form.data.code} onChange={(code) => form.setData('code', code)} autoFocus invalid={Boolean(form.errors.code)} />
                </div>
                <Button type="submit" className="w-full" disabled={form.processing || form.data.code.length < 6}>{t('cm.auth.verify_code')}</Button>
            </form>
            <div className="mt-4 flex items-center justify-between gap-3 border-t border-(--app-border) pt-4 text-sm">
                <Link href={route('court.applicant.password.request')} className="text-(--app-muted-foreground) hover:text-(--app-foreground) hover:underline">{t('cm.auth.use_different_email')}</Link>
                <button type="button" onClick={resend} disabled={resending} className="font-semibold text-primary hover:underline disabled:opacity-50">{t('cm.auth.resend_code')}</button>
            </div>
        </AuthShell>
    );
}
