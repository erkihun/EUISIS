import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { KeyIcon } from '@/Components/Icons';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';

/** A lawyer asks for a link to choose a new password; the answer is the same for any address. */
export default function LawyerForgotPassword({ status }: { status?: string | null }) {
    const { t } = usePublicText();
    const text = (key: string) => t(`cm.lawyer.forgot.${key}`);
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.lawyer.password.email'));
    };

    return (
        <CourtAuthShell title={text('title')} subtitle={text('text')} icon={<KeyIcon className="h-5 w-5" />} backToLogin={false}
            footer={<div className="mt-6 text-center"><Link href={route('court.lawyer.login')} className="text-sm font-semibold text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">{text('back')}</Link></div>}>
            {status && <CourtAuthAlert tone="success">{status}</CourtAuthAlert>}

            <form onSubmit={submit} className="space-y-4" noValidate>
                <div>
                    <label htmlFor="email" className={courtAuthLabelClass}>{t('cm.lawyer.login.email')}</label>
                    <input id="email" type="email" name="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)}
                        required autoFocus autoComplete="username" aria-invalid={form.errors.email ? true : undefined} className={courtAuthInputClass(Boolean(form.errors.email))} />
                    {form.errors.email && <p role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.email}</p>}
                </div>
                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>{text('submit')}</button>
            </form>
        </CourtAuthShell>
    );
}
