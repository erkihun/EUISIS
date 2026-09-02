import type { InertiaFormProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import CodeRulePreviewCard from './CodeRulePreviewCard';
import FormatTokenHelper from './FormatTokenHelper';
import type { TokenDefinition } from './FormatTokenGroup';
import type { CodeFormatInputHandle } from './CodeFormatInput';
import CodeFormatInput from './CodeFormatInput';

export type CodeRuleFormData = {
    entity_type: string;
    scope_type: string;
    scope_id: string;
    name_en: string;
    name_am: string;
    prefix: string;
    suffix: string;
    format: string;
    separator: string;
    sequence_length: number;
    next_number: number;
    sequence_scope_strategy: string;
    sequence_scope_tokens: string[];
    reset_frequency: string;
    year_format: string;
    is_active: boolean;
    allow_manual_override: boolean;
    require_approval_for_override: boolean;
    description_en: string;
    description_am: string;
};

type Option = { value: string; label_key?: string; label?: string };
type ScopeOption = { id: string; label: string };

type FormOptions = {
    entity_types: Option[];
    scope_types: Option[];
    reset_frequencies: Option[];
    sequence_scope_strategies: Option[];
    scope_options: Record<string, ScopeOption[]>;
    year_formats: Array<{ value: string; label: string }>;
};

export default function CodeRuleForm({
    form,
    options,
    submitLabel,
    cancelHref,
    canPreview,
    initialPreview = '',
    availableTokens = [],
    onSubmit,
}: {
    form: InertiaFormProps<CodeRuleFormData>;
    options: FormOptions;
    submitLabel: string;
    cancelHref: string;
    canPreview: boolean;
    initialPreview?: string;
    availableTokens?: TokenDefinition[];
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    const { t } = useLocale();
    const [preview, setPreview] = useState(initialPreview);
    const [previewLoading, setPreviewLoading] = useState(false);
    const formatInputRef = useRef<CodeFormatInputHandle>(null);

    const inputClassName =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    const scopeOptions = useMemo(
        () => options.scope_options[form.data.scope_type] ?? [],
        [form.data.scope_type, options.scope_options],
    );

    const hasRandomToken = form.data.format.includes('{RAND_6}');
    const hasGenerationToken =
        form.data.format.includes('{SEQUENCE}') ||
        form.data.format.includes('{SEQUENCE_PADDED}') ||
        hasRandomToken;

    // Tokens present in the format that are valid scope candidates
    const availableScopeTokens = useMemo(() => {
        const excluded = new Set(['SEQUENCE', 'SEQUENCE_PADDED', 'RAND_6', 'PREFIX', 'SUFFIX', 'SEPARATOR']);
        const matches = [...form.data.format.matchAll(/\{([A-Z0-9_]+)\}/g)];
        return [...new Set(matches.map((m) => m[1]).filter((t) => !excluded.has(t)))];
    }, [form.data.format]);

    const scopeExplanation = useMemo(() => {
        if (form.data.sequence_scope_strategy === 'global') {
            return t('codeRules.globalScopeExplanation');
        }
        if (form.data.sequence_scope_strategy === 'custom_tokens' && form.data.sequence_scope_tokens.length > 0) {
            return t('codeRules.autoScopeExplanation').replace('{token}', form.data.sequence_scope_tokens.join(', '));
        }
        if (form.data.sequence_scope_strategy === 'auto' && availableScopeTokens.length > 0) {
            return t('codeRules.autoScopeExplanation').replace('{token}', availableScopeTokens.join(', '));
        }
        return t('codeRules.globalScopeExplanation');
    }, [form.data.sequence_scope_strategy, form.data.sequence_scope_tokens, availableScopeTokens, t]);

    useEffect(() => {
        if (!canPreview || form.data.format.trim() === '' || !hasGenerationToken) {
            return;
        }

        const timeout = window.setTimeout(async () => {
            setPreviewLoading(true);

            try {
                const response = await window.axios.post(route('code-rules.preview'), {
                    entity_type: form.data.entity_type,
                    scope_type: form.data.scope_type || null,
                    scope_id: form.data.scope_id || null,
                    prefix: form.data.prefix || null,
                    suffix: form.data.suffix || null,
                    format: form.data.format,
                    separator: form.data.separator,
                    sequence_length: form.data.sequence_length,
                    next_number: form.data.next_number,
                    reset_frequency: form.data.reset_frequency,
                    year_format: form.data.year_format || null,
                    organization_id: form.data.scope_type === 'organization' ? form.data.scope_id || null : null,
                    organization_type_id: form.data.scope_type === 'organization_type' ? form.data.scope_id || null : null,
                    organization_unit_type_id: form.data.scope_type === 'organization_unit_type' ? form.data.scope_id || null : null,
                    service_type_id: form.data.scope_type === 'service_type' ? form.data.scope_id || null : null,
                    sequence_scope_strategy: form.data.sequence_scope_strategy,
                    sequence_scope_tokens: form.data.sequence_scope_tokens,
                });

                setPreview(response.data.preview ?? '');
            } catch {
                setPreview('');
            } finally {
                setPreviewLoading(false);
            }
        }, 250);

        return () => window.clearTimeout(timeout);
    }, [
        canPreview,
        hasGenerationToken,
        form.data.entity_type,
        form.data.scope_type,
        form.data.scope_id,
        form.data.prefix,
        form.data.suffix,
        form.data.format,
        form.data.separator,
        form.data.sequence_length,
        form.data.next_number,
        form.data.reset_frequency,
        form.data.year_format,
        form.data.sequence_scope_strategy,
        form.data.sequence_scope_tokens,
    ]);

    function handleInsertToken(tokenOrExample: string) {
        // If it looks like a full example format string (contains multiple tokens), replace the whole field
        if (tokenOrExample.includes('{') && tokenOrExample.includes('}') && !tokenOrExample.startsWith('{')) {
            form.setData('format', tokenOrExample);

            return;
        }

        // Otherwise insert a single token {TOKEN} at cursor
        const tokenText = tokenOrExample.startsWith('{') ? tokenOrExample : `{${tokenOrExample}}`;
        formatInputRef.current?.insertAtCursor(tokenText);
        // Also update the form data (the input's onChange handler will do this, but as a fallback)
    }

    function renderError(field: keyof CodeRuleFormData | 'sequence_scope_tokens') {
        const message = form.errors[field];

        if (!message) {
            return null;
        }

        return <p className="text-sm text-red-600 dark:text-red-400">{message}</p>;
    }

    return (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_360px]">
            <form className="space-y-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900" onSubmit={onSubmit}>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.entityType')}</label>
                        <select className={inputClassName} value={form.data.entity_type} onChange={(event) => form.setData('entity_type', event.target.value)}>
                            {options.entity_types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label_key ? t(option.label_key) : option.label}
                                </option>
                            ))}
                        </select>
                        {renderError('entity_type')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.scopeType')}</label>
                        <select className={inputClassName} value={form.data.scope_type} onChange={(event) => {
                            form.setData('scope_type', event.target.value);
                            form.setData('scope_id', '');
                        }}>
                            <option value="">{t('codeRules.globalRule')}</option>
                            {options.scope_types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label_key ? t(option.label_key) : option.label}
                                </option>
                            ))}
                        </select>
                        {renderError('scope_type')}
                    </div>
                    {form.data.scope_type !== '' && (
                        <div className="space-y-2 md:col-span-2">
                            <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.scope')}</label>
                            <select className={inputClassName} value={form.data.scope_id} onChange={(event) => form.setData('scope_id', event.target.value)}>
                                <option value="">{t('common.select')}</option>
                                {scopeOptions.map((option) => (
                                    <option key={option.id} value={option.id}>{option.label}</option>
                                ))}
                            </select>
                            {renderError('scope_id')}
                        </div>
                    )}
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.nameEn')}</label>
                        <input className={inputClassName} value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} />
                        {renderError('name_en')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.nameAm')}</label>
                        <input className={inputClassName} value={form.data.name_am} onChange={(event) => form.setData('name_am', event.target.value)} />
                        {renderError('name_am')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.prefix')}</label>
                        <input className={inputClassName} value={form.data.prefix} onChange={(event) => form.setData('prefix', event.target.value.toUpperCase())} />
                        {renderError('prefix')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.suffix')}</label>
                        <input className={inputClassName} value={form.data.suffix} onChange={(event) => form.setData('suffix', event.target.value.toUpperCase())} />
                        {renderError('suffix')}
                    </div>
                    <div className="space-y-2 md:col-span-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.format')}</label>
                        <CodeFormatInput
                            ref={formatInputRef}
                            className={inputClassName}
                            value={form.data.format}
                            onChange={(value) => form.setData('format', value)}
                            placeholder="{PREFIX}-{YEAR}-{SEQUENCE_PADDED}"
                        />
                        {renderError('format')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.separator')}</label>
                        <input className={inputClassName} value={form.data.separator} onChange={(event) => form.setData('separator', event.target.value)} />
                        {renderError('separator')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.sequenceLength')}</label>
                        <input type="number" min="1" max="10" className={inputClassName} value={form.data.sequence_length} onChange={(event) => form.setData('sequence_length', parseInt(event.target.value, 10) || 1)} />
                        {renderError('sequence_length')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.nextNumber')}</label>
                        <input type="number" min="1" className={inputClassName} value={form.data.next_number} onChange={(event) => form.setData('next_number', parseInt(event.target.value, 10) || 1)} />
                        {renderError('next_number')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.resetFrequency')}</label>
                        <select className={inputClassName} value={form.data.reset_frequency} onChange={(event) => form.setData('reset_frequency', event.target.value)}>
                            {options.reset_frequencies.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label_key ? t(option.label_key) : option.label}
                                </option>
                            ))}
                        </select>
                        {renderError('reset_frequency')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.yearFormat')}</label>
                        <select className={inputClassName} value={form.data.year_format} onChange={(event) => form.setData('year_format', event.target.value)}>
                            {options.year_formats.map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </select>
                        {renderError('year_format')}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <label className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-slate-700 dark:text-slate-300">
                        <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                        <span>{t('codeRules.activeRule')}</span>
                    </label>
                    <label className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-slate-700 dark:text-slate-300">
                        <input type="checkbox" checked={form.data.allow_manual_override} onChange={(event) => form.setData('allow_manual_override', event.target.checked)} />
                        <span>{t('codeRules.manualOverride')}</span>
                    </label>
                    <label className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-slate-700 dark:text-slate-300">
                        <input type="checkbox" checked={form.data.require_approval_for_override} onChange={(event) => form.setData('require_approval_for_override', event.target.checked)} />
                        <span>{t('codeRules.requireApprovalForOverride')}</span>
                    </label>
                </div>

                {/* Sequence Behavior */}
                <div className="rounded-xl border border-blue-100 bg-blue-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                    <p className="mb-3 text-sm font-semibold text-blue-900 dark:text-blue-300">{t('codeRules.sequenceScopeBehavior')}</p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.sequenceScopeStrategy')}</label>
                            <select
                                className={inputClassName}
                                value={form.data.sequence_scope_strategy}
                                onChange={(e) => {
                                    form.setData('sequence_scope_strategy', e.target.value);
                                    if (e.target.value !== 'custom_tokens') {
                                        form.setData('sequence_scope_tokens', []);
                                    }
                                }}
                            >
                                {(options.sequence_scope_strategies ?? []).map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label_key ? t(opt.label_key) : opt.label}
                                    </option>
                                ))}
                            </select>
                            {renderError('sequence_scope_strategy' as keyof CodeRuleFormData)}
                        </div>
                        {form.data.sequence_scope_strategy === 'custom_tokens' && (
                            <div className="space-y-2">
                                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.sequenceScopeTokens')}</label>
                                {availableScopeTokens.length === 0 ? (
                                    <p className="text-xs text-amber-600 dark:text-amber-400">{t('codeRules.formatMustContainSequence')}</p>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        {availableScopeTokens.map((token) => {
                                            const checked = form.data.sequence_scope_tokens.includes(token);
                                            return (
                                                <label key={token} className="flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-300 px-2 py-1 text-xs dark:border-slate-600">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={(e) => {
                                                            const next = e.target.checked
                                                                ? [...form.data.sequence_scope_tokens, token]
                                                                : form.data.sequence_scope_tokens.filter((t) => t !== token);
                                                            form.setData('sequence_scope_tokens', next);
                                                        }}
                                                    />
                                                    <code>{token}</code>
                                                </label>
                                            );
                                        })}
                                    </div>
                                )}
                                {renderError('sequence_scope_tokens')}
                            </div>
                        )}
                        <div className="md:col-span-2">
                            <p className="rounded-md bg-white px-3 py-2 text-xs text-gray-600 dark:bg-slate-900 dark:text-slate-400">{scopeExplanation}</p>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.descriptionEn')}</label>
                        <textarea className={`${inputClassName} min-h-24`} value={form.data.description_en} onChange={(event) => form.setData('description_en', event.target.value)} />
                        {renderError('description_en')}
                    </div>
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-gray-700 dark:text-slate-300">{t('codeRules.descriptionAm')}</label>
                        <textarea className={`${inputClassName} min-h-24`} value={form.data.description_am} onChange={(event) => form.setData('description_am', event.target.value)} />
                        {renderError('description_am')}
                    </div>
                </div>

                <div className="flex gap-3">
                    <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60" disabled={form.processing}>
                        {submitLabel}
                    </button>
                    <Link href={cancelHref} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200">
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>

            <div className="space-y-6">
                <CodeRulePreviewCard preview={preview} loading={previewLoading} usesRandomToken={hasRandomToken} />
                <FormatTokenHelper
                    format={form.data.format}
                    onInsert={handleInsertToken}
                    tokens={availableTokens}
                />
            </div>
        </div>
    );
}
