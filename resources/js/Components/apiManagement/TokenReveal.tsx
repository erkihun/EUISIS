import { KeyIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button } from '@euisis/ui';
import { useState } from 'react';

/** A newly generated token, shown exactly once, with copy-to-clipboard and a manual fallback. */
export default function TokenReveal({ token, hint }: { token: string; hint?: string }) {
    const { t } = useLocale();
    const [copied, setCopied] = useState(false);
    const [copyFailed, setCopyFailed] = useState(false);

    async function copy() {
        setCopied(false);
        setCopyFailed(false);
        try {
            await navigator.clipboard.writeText(token);
            setCopied(true);
        } catch {
            setCopyFailed(true);
        }
    }

    return (
        <Alert tone="warning" title={<span className="inline-flex items-center gap-2"><KeyIcon className="h-4 w-4" aria-hidden="true" />{t('apiManagement.copyTokenNow')}</span>}>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <code className="min-w-0 flex-1 select-all break-all rounded-[var(--radius-control)] bg-[color:var(--app-surface)] px-3 py-2 font-mono text-xs text-[color:var(--app-foreground)] ring-1 ring-inset ring-amber-200 dark:ring-amber-900">
                    {token}
                </code>
                <Button size="sm" variant="primary" onClick={copy}>{copied ? t('common.copied') : t('common.copy')}</Button>
            </div>
            {copyFailed && <p role="alert" className="mt-2">{t('apiManagement.copyFailed')}</p>}
            {hint && <p className="mt-2 text-xs opacity-90">{hint}</p>}
        </Alert>
    );
}
