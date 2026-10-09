import ApplicationLogo from '@/Components/ApplicationLogo';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Props {
    user: { name: string; email: string };
}

export default function MfaChallenge({ user }: Props) {
    const { t } = useLocale();
    const [useRecovery, setUseRecovery] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('mfa.challenge.verify'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    const errorMessage = errors.code || errors.recovery_code;

    return (
        <div className="flex min-h-screen items-center justify-center bg-white px-4 py-10 dark:bg-slate-950">
            <Head title={t('auth.mfaChallenge')} />

            <div className="w-full max-w-md">
                <div className="mb-8 flex items-center justify-center gap-2">
                    <ApplicationLogo className="h-9 w-9 fill-slate-900 dark:fill-white" />
                    <span className="text-lg font-bold text-slate-900 dark:text-white">
                        EUISIS
                    </span>
                </div>

                <div className="rounded-panel border border-slate-200 bg-white p-8 dark:border-slate-800 dark:bg-slate-900">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {t('auth.mfaChallenge')}
                    </h1>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {useRecovery
                            ? t('auth.mfaRecoveryCodes')
                            : t('auth.mfaChallengeDescription')}
                    </p>
                    <p className="mt-2 text-xs text-slate-400 dark:text-slate-500">
                        {user.email}
                    </p>

                    <form onSubmit={submit} className="mt-6 space-y-4">
                        {!useRecovery ? (
                            <div>
                                <label
                                    htmlFor="code"
                                    className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {t('auth.mfaCode')}
                                </label>
                                <input
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    className={`w-full rounded-card border px-4 py-2.5 text-center font-mono text-lg tracking-widest text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-900 dark:text-slate-100 ${
                                        errorMessage
                                            ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                            : 'border-slate-300 bg-white dark:border-slate-700'
                                    }`}
                                    placeholder="123456"
                                    autoFocus
                                />
                            </div>
                        ) : (
                            <div>
                                <label
                                    htmlFor="recovery_code"
                                    className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300"
                                >
                                    {t('auth.mfaRecoveryCodes')}
                                </label>
                                <input
                                    id="recovery_code"
                                    name="recovery_code"
                                    autoComplete="off"
                                    value={data.recovery_code}
                                    onChange={(e) =>
                                        setData('recovery_code', e.target.value)
                                    }
                                    className={`w-full rounded-card border px-4 py-2.5 text-center font-mono text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-900 dark:text-slate-100 ${
                                        errorMessage
                                            ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                            : 'border-slate-300 bg-white dark:border-slate-700'
                                    }`}
                                    placeholder="ABCDE-12345"
                                    autoFocus
                                />
                            </div>
                        )}

                        {errorMessage && (
                            <p className="text-xs text-red-500">{errorMessage}</p>
                        )}

                        <button
                            type="submit"
                            disabled={processing}
                            className="flex w-full items-center justify-center rounded-card bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-950"
                        >
                            {processing ? '…' : t('auth.mfaSubmit')}
                        </button>

                        <button
                            type="button"
                            onClick={() => setUseRecovery((v) => !v)}
                            className="block w-full text-center text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300"
                        >
                            {useRecovery ? t('auth.mfa') : t('auth.mfaUseRecoveryCode')}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
