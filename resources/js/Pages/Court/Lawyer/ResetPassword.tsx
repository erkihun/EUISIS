import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ShieldCheck } from '@/Components/Icons';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';

/** A lawyer chooses their password from the emailed link (first time, or after forgetting it). */
export default function LawyerResetPassword({ token, email }: { token: string; email: string }) {
    const { t } = usePublicText();
    const text = (key: string) => t(`cm.lawyer.reset.${key}`);
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const messages = Object.values(form.errors).filter((message): message is string => Boolean(message));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.lawyer.password.update'), { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <CourtAuthShell title={text('title')} icon={<ShieldCheck className="h-5 w-5" />} backToLogin={false}
            footer={<div className="mt-6 text-center"><Link href={route('court.lawyer.login')} className="text-sm font-semibold text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">{t('cm.lawyer.forgot.back')}</Link></div>}>
            {messages.length > 0 && (
                <CourtAuthAlert tone="error">
                    <ul className="ml-5 list-disc">{messages.map((message) => <li key={message}>{message}</li>)}</ul>
                </CourtAuthAlert>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="email" className={courtAuthLabelClass}>{t('cm.lawyer.login.email')}</label>
                    <input id="email" type="email" name="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)}
                        required autoComplete="username" className={courtAuthInputClass(Boolean(form.errors.email))} />
                </div>
                <div>
                    <label htmlFor="password" className={courtAuthLabelClass}>{text('password')}</label>
                    <input id="password" type="password" name="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)}
                        required autoFocus autoComplete="new-password" className={courtAuthInputClass(Boolean(form.errors.password))} />
                </div>
                <div>
                    <label htmlFor="password_confirmation" className={courtAuthLabelClass}>{text('confirm')}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)} required autoComplete="new-password" className={courtAuthInputClass(false)} />
                </div>
                <PasswordPolicyChecklist password={form.data.password} confirmation={form.data.password_confirmation} />
                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>{text('submit')}</button>
            </form>
        </CourtAuthShell>
    );
}
