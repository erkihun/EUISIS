import { useLocale } from '@/hooks/useLocale';

export default function CodeRulePreviewCard({
    preview,
    loading = false,
    error = null,
    requiresContext = false,
    usesRandomToken = false,
}: {
    preview: string | null;
    loading?: boolean;
    error?: string | null;
    requiresContext?: boolean;
    usesRandomToken?: boolean;
}) {
    const { t } = useLocale();

    return (
        <div className="rounded-2xl border border-blue-200 bg-blue-50 p-5 shadow-sm dark:border-blue-900/40 dark:bg-slate-900">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('codeRules.generatedPreview')}
                    </h3>
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('codeRules.usePreviewHint')}</p>
                </div>
                {loading && (
                    <span className="text-xs text-blue-600 dark:text-blue-300">
                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                    </span>
                )}
            </div>

            {error && (
                <div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-400">
                    {error}
                </div>
            )}

            {requiresContext && !error && (
                <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-400">
                    {t('codeRules.previewRequiresContext')}
                </div>
            )}

            <div className="mt-4 rounded-xl border border-blue-200 bg-white px-4 py-3 font-mono text-sm text-blue-700 dark:border-slate-700 dark:bg-slate-950 dark:text-blue-300">
                {loading ? <span className="text-gray-400 dark:text-slate-600">…</span> : (preview || '—')}
            </div>

            {usesRandomToken && (
                <p className="mt-3 text-xs text-gray-600 dark:text-slate-400">
                    {t('codeRules.randomValueStable')}
                </p>
            )}
        </div>
    );
}
