import ApplicationLogo from '@/Components/ApplicationLogo';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type MfaSetupPageProps = PageProps<{
    qrCodeUri: string;
    secretKey: string;
    user: { name: string; email: string };
    issuer: string;
    flash?: {
        mfa_recovery_codes_once?: string[];
        status?: string;
    };
}>;

export default function MfaSetup() {
    const { t } = useLocale();
    const { props } = usePage<MfaSetupPageProps>();
    const { qrCodeUri, secretKey, user, issuer } = props;
    const recoveryCodes = props.flash?.mfa_recovery_codes_once ?? [];

    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('mfa.setup.confirm'), {
            onFinish: () => reset('code'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-white px-4 py-10 dark:bg-slate-950">
            <Head title={t('auth.mfaSetup')} />

            <div className="w-full max-w-lg">
                <div className="mb-8 flex items-center justify-center gap-2">
                    <ApplicationLogo className="h-9 w-9 fill-slate-900 dark:fill-white" />
                    <span className="text-lg font-bold text-slate-900 dark:text-white">
                        {issuer}
                    </span>
                </div>

                <div className="rounded-panel border border-slate-200 bg-white p-8 dark:border-slate-800 dark:bg-slate-900">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {t('auth.mfaSetup')}
                    </h1>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        {t('auth.mfaSetupDescription')}
                    </p>

                    <p className="mt-2 text-xs text-slate-400 dark:text-slate-500">
                        {user.email}
                    </p>

                    {recoveryCodes.length > 0 && (
                        <div className="mt-6 rounded-card border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/20">
                            <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                                {t('auth.mfaRecoveryCodes')}
                            </h2>
                            <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                                {t('auth.mfaRecoveryCodesNote')}
                            </p>
                            <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-xs text-amber-900 dark:text-amber-100">
                                {recoveryCodes.map((code) => (
                                    <li
                                        key={code}
                                        className="rounded bg-white px-2 py-1 dark:bg-slate-950"
                                    >
                                        {code}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="mt-6 flex flex-col items-center gap-4">
                        <img
                            src={qrCodeUri}
                            alt="MFA QR code"
                            className="h-56 w-56 rounded-card border border-slate-200 bg-white p-3 dark:border-slate-700"
                        />
                        <div className="w-full">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                {t('auth.mfaManualKey')}
                            </span>
                            <div className="mt-1 break-all rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                                {secretKey}
                            </div>
                        </div>
                    </div>

                    <form onSubmit={submit} className="mt-8 space-y-4">
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
                                    errors.code
                                        ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                                        : 'border-slate-300 bg-white dark:border-slate-700'
                                }`}
                                placeholder="123456"
                                autoFocus
                            />
                            {errors.code && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.code}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="flex w-full items-center justify-center rounded-card bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-950"
                        >
                            {processing ? '…' : t('auth.mfaConfirmSetup')}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
