import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

/**
 * Shows a generated one-time password to the administrator who created or
 * reset the account — once. The server flashes it for a single response and
 * never stores or logs it; this component keeps it only in memory and drops
 * it on dismiss or navigation.
 */
export default function TemporaryPasswordNotice() {
    const { t } = useLocale();
    const flash = (usePage().props as { flash?: { temporary_password?: string | null } }).flash;
    const [value, setValue] = useState<string | null>(flash?.temporary_password ?? null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        setValue(flash?.temporary_password ?? null);
        setCopied(false);
    }, [flash?.temporary_password]);

    if (!value) return null;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div role="alert" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200">
            <p className="font-semibold">{t('auth.passwordPolicy.temporaryTitle')}</p>
            <p className="mt-1">{t('auth.passwordPolicy.temporaryBody')}</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <code className="select-all rounded bg-white px-3 py-1.5 font-mono text-base tracking-wide text-gray-900 dark:bg-slate-900 dark:text-slate-100">{value}</code>
                <button type="button" onClick={() => void copy()} className="rounded-md border border-amber-400 px-3 py-1.5 text-xs font-semibold hover:bg-amber-100 dark:border-amber-600 dark:hover:bg-amber-900/40">
                    {copied ? t('auth.passwordPolicy.copied') : t('auth.passwordPolicy.copy')}
                </button>
                <button type="button" onClick={() => setValue(null)} className="rounded-md px-3 py-1.5 text-xs font-semibold hover:bg-amber-100 dark:hover:bg-amber-900/40">
                    {t('auth.passwordPolicy.dismiss')}
                </button>
            </div>
        </div>
    );
}
