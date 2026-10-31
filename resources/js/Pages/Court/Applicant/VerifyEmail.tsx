import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, FormLabel } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AuthShell, { FirstError } from '@/court/components/applicant/AuthShell';
import OtpCodeInput from '@/court/components/applicant/OtpCodeInput';

/**
 * Confirming a signed-in applicant's email with the six-digit code sent to it
 * (opening the page sends one when none is pending), or asking for a new one.
 */
export default function ApplicantVerifyEmail() {
    const { t } = useLocale();
    const form = useForm({ code: '' });
    const [resending, setResending] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.verification.verify-otp'), { onError: () => form.reset('code') });
    };

    const resend = () => router.post(route('court.applicant.verification.send'), {}, {
        preserveScroll: true,
        onStart: () => setResending(true),
        onFinish: () => setResending(false),
    });

    return (
        <AuthShell title={t('cm.auth.verify_email_title')} subtitle={t('cm.auth.verify_email_otp_description')}>
            <Head title={t('cm.auth.verify_email_title')} />
            <FirstError className="mb-4" />
            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <FormLabel htmlFor="code" className="block text-center">{t('cm.auth.verification_code')}</FormLabel>
                    <OtpCodeInput id="code" value={form.data.code} onChange={(code) => form.setData('code', code)} autoFocus invalid={Boolean(form.errors.code)} />
                </div>
                <Button type="submit" className="w-full" disabled={form.processing || form.data.code.length < 6}>{t('cm.auth.verify_email_button')}</Button>
            </form>
            <div className="mt-4 flex items-center justify-between gap-3 border-t border-(--app-border) pt-4 text-sm">
                <span className="text-(--app-muted-foreground)">{t('cm.auth.didnt_receive_code')}</span>
                <button type="button" onClick={resend} disabled={resending} className="font-semibold text-primary hover:underline disabled:opacity-50">{t('cm.auth.resend_code')}</button>
            </div>
        </AuthShell>
    );
}
