import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { MailIcon } from '@/Components/Icons';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import CourtOtpInput from '@/court/public/CourtOtpInput';
import { usePublicText } from '@/court/i18n/usePublicText';

interface FlashProps {
    info?: string | null;
    success?: string | null;
}

/**
 * Staff password recovery, step 2: the 6-digit code sent by email
 * (PasswordResetLinkController::verifyOtp), with "use a different email" and
 * "resend code" exactly as on the Blade page. Its notices show inline.
 */
export default function PasswordOtp() {
    const { t } = usePublicText();
    const page = usePage();
    const flash = (page.props as { flash?: FlashProps }).flash ?? {};
    const pageErrors = page.props.errors as Record<string, string>;
    const form = useForm({ code: '' });
    const [resending, setResending] = useState(false);
    const firstError = form.errors.code || Object.values(pageErrors)[0];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('admin.password.otp.verify'), { onError: () => form.setData('code', '') });
    };

    const resend = (event: FormEvent) => {
        event.preventDefault();
        router.post(route('admin.password.otp.resend'), {}, {
            preserveScroll: true,
            onStart: () => setResending(true),
            onFinish: () => setResending(false),
        });
    };

    return (
        <CourtAuthShell
            title={t('cm.auth.password_otp_title')}
            subtitle={t('cm.auth.password_otp_description')}
            icon={<MailIcon className="h-5 w-5" />}
            flashToasts={false}
            footer={(
                <div className="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    <Link href={route('password.request')} className="font-semibold text-gray-500 hover:text-primary dark:text-slate-400">
                        {t('cm.auth.use_different_email')}
                    </Link>
                    <form onSubmit={resend}>
                        <button type="submit" disabled={resending} className="font-bold text-primary hover:text-primary-hover disabled:opacity-60">
                            {t('cm.auth.resend_code')}
                        </button>
                    </form>
                </div>
            )}
        >
            {flash.info && <CourtAuthAlert tone="info">{flash.info}</CourtAuthAlert>}
            {flash.success && <CourtAuthAlert tone="success">{flash.success}</CourtAuthAlert>}
            {firstError && <CourtAuthAlert tone="error">{firstError}</CourtAuthAlert>}

            <form onSubmit={submit} className="space-y-5" noValidate>
                <div>
                    <p id="code-label" className={`${courtAuthLabelClass} text-center`}>{t('cm.auth.verification_code')}</p>
                    <CourtOtpInput
                        value={form.data.code}
                        onChange={(code) => form.setData('code', code)}
                        label={t('cm.auth.verification_code')}
                        invalid={Boolean(form.errors.code)}
                        disabled={form.processing}
                        autoFocus
                    />
                </div>

                <button type="submit" disabled={form.processing}className={courtAuthButtonClass}>
                    {t('cm.auth.verify_code')}
                </button>
            </form>
        </CourtAuthShell>
    );
}
