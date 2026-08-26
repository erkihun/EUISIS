import { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

/**
 * Forced password change on first login.
 *
 * Rendered on the guest layout deliberately: the user is authenticated but may
 * not reach any module yet, so showing the full application chrome would
 * suggest navigation that every link would refuse.
 */
export default function ForcedPasswordChange(): JSX.Element {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.forced.update'), {
            onError: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t('auth.forcedPasswordTitle')} />

            <h1 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                {t('auth.forcedPasswordTitle')}
            </h1>

            <div
                role="status"
                className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            >
                {t('auth.forcedPasswordNotice')}
            </div>

            <form onSubmit={submit} className="mt-5 space-y-4">
                <Field
                    id="current_password"
                    label={t('auth.currentPassword')}
                    value={data.current_password}
                    error={errors.current_password}
                    autoComplete="current-password"
                    autoFocus
                    onChange={(value) => setData('current_password', value)}
                />

                <Field
                    id="password"
                    label={t('auth.newPassword')}
                    value={data.password}
                    error={errors.password}
                    autoComplete="new-password"
                    onChange={(value) => setData('password', value)}
                />

                <Field
                    id="password_confirmation"
                    label={t('auth.confirmNewPassword')}
                    value={data.password_confirmation}
                    error={errors.password_confirmation}
                    autoComplete="new-password"
                    onChange={(value) => setData('password_confirmation', value)}
                />

                <button
                    type="submit"
                    disabled={processing}
                    className="min-h-[44px] w-full rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:opacity-60"
                >
                    {processing ? t('auth.changingPassword') : t('auth.changePasswordAction')}
                </button>
            </form>
        </GuestLayout>
    );
}

function Field({
    id,
    label,
    value,
    error,
    autoComplete,
    autoFocus,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    autoComplete: string;
    autoFocus?: boolean;
    onChange: (value: string) => void;
}): JSX.Element {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                {label}
            </label>
            <input
                id={id}
                type="password"
                value={value}
                autoComplete={autoComplete}
                autoFocus={autoFocus}
                onChange={(e) => onChange(e.target.value)}
                className="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-base text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
            {error && <p className="mt-1.5 text-sm text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
