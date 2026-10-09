import { useEffect, useRef, useState, useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';

export interface CodeRuleFieldProps {
    entityType: string;
    scopeType?: string;
    scopeId?: string | number | null;
    context?: Record<string, unknown>;
    value: string;
    onChange: (value: string) => void;
    fieldName: string;
    label: string;
    canManualOverride: boolean;
    required?: boolean;
    disabled?: boolean;
    existingCode?: string;
    preserveExistingCodeOnEdit?: boolean;
    error?: string;
}

interface PreviewResponse {
    code: string | null;
    rule: {
        id: string;
        name: string;
        entity_type: string;
        is_scoped: boolean;
        scope_type: string | null;
        next_number?: number;
    } | null;
    manual_override_allowed: boolean;
    requires_override_permission: boolean;
    error: string | null;
}

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

const inputClsReadOnly =
    'w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400';

export default function CodeRuleField({
    entityType,
    scopeType,
    scopeId,
    context = {},
    value,
    onChange,
    fieldName,
    label,
    canManualOverride,
    required = false,
    disabled = false,
    existingCode,
    preserveExistingCodeOnEdit = false,
    error,
}: CodeRuleFieldProps) {
    const { t } = useLocale();

    // On edit pages with preserveExistingCodeOnEdit, just show the existing code read-only
    const isEditReadOnly = preserveExistingCodeOnEdit && existingCode !== undefined;

    const [isManualMode, setIsManualMode] = useState(false);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [overrideWarning, setOverrideWarning] = useState<string | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const hasFetchedRef = useRef(false);

    const buildRequestBody = useCallback(() => {
        const ctx: Record<string, unknown> = { ...context };
        if (scopeType) ctx['scope_type'] = scopeType;
        if (scopeId !== undefined && scopeId !== null) ctx['scope_id'] = String(scopeId);

        return {
            entity_type: entityType,
            scope_type: scopeType ?? null,
            scope_id: scopeId != null ? String(scopeId) : null,
            context: ctx,
        };
    }, [entityType, scopeType, scopeId, context]);

    const fetchPreview = useCallback(async () => {
        if (isEditReadOnly) return;

        // Abort any in-flight request
        if (abortRef.current) {
            abortRef.current.abort();
        }
        abortRef.current = new AbortController();

        setLoading(true);
        setFetchError(null);

        try {
            const response = await window.axios.post<PreviewResponse>(
                route('code-rules.preview-code'),
                buildRequestBody(),
                {
                signal: abortRef.current.signal,
                },
            );

            const data = response.data;
            setPreview(data);

            // In auto mode, push the preview code into the form so backend gets it
            if (!isManualMode && data.code !== null) {
                onChange('');
            }
        } catch (err) {
            if (err instanceof Error && err.name === 'AbortError') return;
            const message =
                typeof err === 'object' &&
                err !== null &&
                'response' in err &&
                typeof (err as { response?: { data?: { message?: unknown } } }).response?.data?.message === 'string'
                    ? (err as { response: { data: { message: string } } }).response.data.message
                    : t('codeRules.previewUnavailable');

            setFetchError(message);
            setPreview(null);
        } finally {
            setLoading(false);
        }
    }, [buildRequestBody, isEditReadOnly, isManualMode, onChange, t]);

    // Fetch preview on mount (create mode only)
    useEffect(() => {
        if (isEditReadOnly || hasFetchedRef.current) return;
        hasFetchedRef.current = true;
        void fetchPreview();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Re-fetch when context changes (e.g. organization_type_id changes on org create)
    const prevContextRef = useRef<string>('');
    const contextKey = JSON.stringify({ entityType, scopeType, scopeId, ...context });
    useEffect(() => {
        if (prevContextRef.current === contextKey) return;
        prevContextRef.current = contextKey;
        if (!hasFetchedRef.current) return; // Don't double-fire on mount
        if (isEditReadOnly) return;
        setIsManualMode(false);
        onChange('');
        void fetchPreview();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contextKey]);

    function handleManualInput(inputValue: string) {
        if (!canManualOverride) {
            setOverrideWarning(
                preview?.requires_override_permission
                    ? t('codeRules.manualOverrideRequiresPermission')
                    : t('codeRules.manualOverrideNotAllowed'),
            );
            // Revert — do not allow manual entry
            return;
        }
        setOverrideWarning(null);
        setIsManualMode(inputValue !== '');
        onChange(inputValue);
    }

    function handleSwitchToAuto() {
        setIsManualMode(false);
        setOverrideWarning(null);
        onChange('');
        void fetchPreview();
    }

    // ---- Render: Edit read-only ----
    if (isEditReadOnly) {
        return (
            <div>
                <label className="block text-xs font-medium text-gray-600 dark:text-slate-400">
                    {label}
                </label>
                <div className="mt-1 flex items-center gap-2">
                    <input
                        className={inputClsReadOnly}
                        value={existingCode ?? ''}
                        readOnly
                        disabled
                        aria-label={label}
                    />
                    <span className="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-500 dark:bg-slate-800 dark:text-slate-400">
                        {t('codeRules.existingCodePreserved')}
                    </span>
                </div>
                {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
            </div>
        );
    }

    // ---- Render: No rule found ----
    const noRule = preview !== null && preview.rule === null && preview.error !== null;

    // ---- Auto-generate mode display ----
    const previewCode = preview?.code ?? null;
    const ruleInfo = preview?.rule ?? null;
    const manualOverrideAllowed = preview?.manual_override_allowed ?? canManualOverride;

    return (
        <div>
            <div className="flex items-center justify-between">
                <label className="block text-xs font-medium text-gray-600 dark:text-slate-400">
                    {label}
                    {required && <span className="ml-1 text-red-500">*</span>}
                </label>
                {!disabled && manualOverrideAllowed && !isManualMode && (
                    <button
                        type="button"
                        onClick={() => {
                            setIsManualMode(true);
                            onChange('');
                        }}
                        className="text-xs text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                    >
                        {t('codeRules.enterCodeManually')}
                    </button>
                )}
                {!disabled && isManualMode && (
                    <button
                        type="button"
                        onClick={handleSwitchToAuto}
                        className="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {t('codeRules.autoGenerate')}
                    </button>
                )}
            </div>

            <div className="mt-1">
                {/* Manual mode: show text input */}
                {isManualMode ? (
                    <input
                        className={inputCls}
                        value={value}
                        onChange={(e) => handleManualInput(e.target.value)}
                        placeholder={t('codeRules.enterCodeManually')}
                        disabled={disabled}
                        aria-label={label}
                        aria-required={required}
                    />
                ) : (
                    /* Auto-generate mode: show preview badge */
                    <div className="flex items-center gap-2">
                        <div
                            className={[
                                'flex-1 rounded-lg border px-3 py-2 font-mono text-sm',
                                loading
                                    ? 'border-gray-200 bg-gray-50 text-gray-400 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-500'
                                    : noRule
                                    ? 'border-orange-200 bg-orange-50 text-orange-600 dark:border-orange-900/40 dark:bg-orange-950/20 dark:text-orange-400'
                                    : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-blue-300',
                            ].join(' ')}
                            aria-live="polite"
                        >
                            {loading
                                ? t('common.loading')
                                : noRule
                                ? t('codeRules.noActiveCodeRule')
                                : (previewCode ?? '—')}
                        </div>
                        <button
                            type="button"
                            onClick={() => void fetchPreview()}
                            disabled={disabled || loading}
                            title={t('codeRules.refreshPreview')}
                            className="shrink-0 rounded-lg border border-gray-300 px-2 py-2 text-gray-500 hover:bg-gray-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                            aria-label={t('codeRules.refreshPreview')}
                        >
                            <svg
                                className={['h-4 w-4', loading ? 'animate-spin' : ''].join(' ')}
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </div>
                )}
            </div>

            {/* Hint below the field */}
            {!isManualMode && !noRule && !loading && (
                <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                    {t('codeRules.autoGeneratedIfBlank')}
                </p>
            )}

            {/* No rule warning */}
            {noRule && (
                <p className="mt-1 text-xs text-orange-600 dark:text-orange-400">
                    {preview?.error ?? t('codeRules.noActiveCodeRule')}
                    {canManualOverride && ` ${t('codeRules.enterCodeManually')}.`}
                </p>
            )}

            {/* Override warning */}
            {overrideWarning && (
                <p className="mt-1 text-xs text-red-600 dark:text-red-400">{overrideWarning}</p>
            )}

            {/* Fetch error */}
            {fetchError && !loading && (
                <p className="mt-1 text-xs text-red-600 dark:text-red-400">{fetchError}</p>
            )}

            {/* Validation error from form */}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}

            {/* Hidden input: in auto mode we send empty string so backend auto-generates */}
            {!isManualMode && (
                <input type="hidden" name={fieldName} value="" />
            )}
        </div>
    );
}
