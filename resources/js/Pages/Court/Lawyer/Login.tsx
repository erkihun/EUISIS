import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Briefcase } from '@/Components/Icons';
import CourtAuthShell, { CourtAuthAlert, courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';

/**
 * The lawyers' sign-in (LawyerAuthController, EUISIS ADR 0013 #13). Only a
 * lawyer the registry verified gets in; they then work in the court portal on
 * the cases of the employees they represent. The registry opens the account,
 * or a lawyer applies (/lawyer/register) and the registry verifies the
 * licence; the lawyer then chooses a password from the emailed link.
 */
export default function LawyerLogin({ status }: { status?: string | null }) {
    const { t } = usePublicText();
    const text = (key: string) => t(`cm.lawyer.login.${key}`);
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.lawyer.login.submit'), { onFinish: () => form.reset('password') });
    };

    return (
        <CourtAuthShell title={text('title')} subtitle={text('subtitle')} icon={<Briefcase className="h-5 w-5" />} backToLogin={false}
            footer={(
                <p className="mt-6 border-t border-gray-100 pt-5 text-center text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400">
                    {text('not_registered')}{' '}
                    <Link href={route('court.lawyer.register')} className="font-semibold text-primary hover:underline">{text('apply')}</Link>
                </p>
            )}>
            {status && <CourtAuthAlert tone="success">{status}</CourtAuthAlert>}

            <form onSubmit={submit} className="space-y-4" noValidate>
                <div>
                    <label htmlFor="email" className={courtAuthLabelClass}>{text('email')}</label>
                    <input id="email" type="email" name="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)}
                        required autoFocus autoComplete="username" aria-invalid={form.errors.email ? true : undefined}
                        aria-describedby={form.errors.email ? 'email-error' : undefined} className={courtAuthInputClass(Boolean(form.errors.email))} />
                    {form.errors.email && <p id="email-error" role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.email}</p>}
                </div>

                <div>
                    <div className="mb-1.5 flex items-center justify-between gap-3">
                        <label htmlFor="password" className="block text-sm font-medium text-gray-700 dark:text-slate-300">{text('password')}</label>
                        <Link href={route('court.lawyer.password.request')} className="text-xs font-semibold text-primary hover:underline">{text('forgot')}</Link>
                    </div>
                    <input id="password" type="password" name="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)}
                        required autoComplete="current-password" aria-invalid={form.errors.password ? true : undefined} className={courtAuthInputClass(Boolean(form.errors.password))} />
                    {form.errors.password && <p role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.password}</p>}
                </div>

                <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-300">
                    <input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-slate-600 dark:bg-slate-800" />
                    {text('remember')}
                </label>

                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>{text('submit')}</button>
            </form>
        </CourtAuthShell>
    );
}
