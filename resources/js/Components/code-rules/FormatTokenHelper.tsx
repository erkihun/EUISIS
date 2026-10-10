import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import FormatTokenGroup, { type TokenDefinition } from './FormatTokenGroup';
import FormatTokenBadge from './FormatTokenBadge';

import { cx, inputClassName } from '@/Components/ui';
const FORMAT_EXAMPLES = [
    '{PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
    '{ORG_TYPE_PREFIX}-{YEAR}-{SEQUENCE_PADDED}',
    '{PREFIX}-{SEQUENCE_PADDED}',
    'EMP-{YEAR}-{SEQUENCE_PADDED}',
    'EMP-{RAND_6}',
    'AAC-{RAND_8}',
    '{ORG_CODE}-{PREFIX}-{SEQUENCE_PADDED}',
];

const CATEGORY_ORDER = [
    'Core',
    'DateAndTime',
    'Organization',
    'Employee',
    'Position',
    'Service',
    'Location',
    'Workflow',
    'Custom',
];

export default function FormatTokenHelper({
    format = '',
    onInsert,
    tokens = [],
}: {
    format?: string;
    onInsert?: (token: string) => void;
    tokens?: TokenDefinition[];
}) {
    const { t, locale } = useLocale();
    const [search, setSearch] = useState('');

    // Tokens currently present in the format string
    const usedTokens = useMemo(() => {
        const matches = format.matchAll(/\{([A-Z0-9_]+)\}/g);

        return [...matches].map((m) => m[1]);
    }, [format]);

    // Filter tokens by search query
    const filteredTokens = useMemo(() => {
        if (search.trim() === '') {
            return tokens;
        }

        const q = search.toLowerCase();

        return tokens.filter(
            (t) =>
                t.token.toLowerCase().includes(q) ||
                t.label_en.toLowerCase().includes(q) ||
                t.label_am.includes(q) ||
                t.description_en.toLowerCase().includes(q),
        );
    }, [tokens, search]);

    // Group filtered tokens by category, preserving order
    const groupedTokens = useMemo(() => {
        const groups: Record<string, TokenDefinition[]> = {};

        for (const tokenDef of filteredTokens) {
            if (!groups[tokenDef.category]) {
                groups[tokenDef.category] = [];
            }

            groups[tokenDef.category].push(tokenDef);
        }

        return groups;
    }, [filteredTokens]);

    function handleInsert(token: string) {
        onInsert?.(token);
    }

    // If no tokens are passed (legacy usage), render the old static list
    if (tokens.length === 0) {
        const staticTokenKeys = [
            'prefix',
            'suffix',
            'year',
            'month',
            'day',
            'sequence',
            'randomSix',
            'orgCode',
            'orgTypeCode',
            'orgTypePrefix',
            'serviceTypeCode',
            'custom',
        ] as const;

        return (
            <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('codeRules.formatTokens')}</h3>
                <ul className="mt-3 space-y-2 text-sm text-[color:var(--app-muted-foreground)]">
                    {staticTokenKeys.map((tokenKey) => (
                        <li key={tokenKey}>{t(`codeRules.tokens.${tokenKey}`)}</li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <h3 className="mb-3 text-sm font-semibold text-[color:var(--app-foreground)]">
                {t('codeRules.formatTokens')}
            </h3>

            {/* Search */}
            <input
                type="search"
                placeholder={t('codeRules.searchTokens')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className={cx(inputClassName, 'mb-3 w-full')}
            />

            {/* Used tokens */}
            {usedTokens.length > 0 && (
                <div className="mb-3">
                    <p className="mb-1.5 text-xs font-medium text-[color:var(--app-muted-foreground)]">
                        {t('codeRules.usedTokens')}
                    </p>
                    <div className="flex flex-wrap gap-1">
                        {usedTokens.map((tok) => (
                            <FormatTokenBadge key={tok} token={tok} variant="active" />
                        ))}
                    </div>
                </div>
            )}

            {/* Format examples */}
            {onInsert && search === '' && (
                <div className="mb-3">
                    <p className="mb-1.5 text-xs font-medium text-[color:var(--app-muted-foreground)]">
                        {t('codeRules.formatExamples')}
                    </p>
                    <div className="space-y-1">
                        {FORMAT_EXAMPLES.map((example) => (
                            <button
                                key={example}
                                type="button"
                                className="block w-full rounded-md bg-[color:var(--app-surface-muted)] px-2.5 py-1 text-left font-mono text-xs text-[color:var(--app-foreground)] hover:bg-gray-100 dark:hover:bg-slate-700"
                                onClick={() => onInsert(example)}
                                title={t('codeRules.formatExamples')}
                            >
                                {example}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* Token groups */}
            <div className="max-h-72 overflow-y-auto">
                {CATEGORY_ORDER.filter((cat) => groupedTokens[cat]?.length).map((category) => (
                    <FormatTokenGroup
                        key={category}
                        category={category}
                        tokens={groupedTokens[category]}
                        onInsert={handleInsert}
                        usedTokens={usedTokens}
                        defaultOpen={category === 'Core'}
                    />
                ))}
            </div>

            {filteredTokens.length === 0 && (
                <p className="mt-2 text-center text-xs text-[color:var(--app-muted-foreground)]">
                    {t('codeRules.unknownToken')}
                </p>
            )}

            {onInsert && (
                <p className="mt-3 text-xs text-[color:var(--app-muted-foreground)]">
                    {t('codeRules.clickTokenToInsert')}
                </p>
            )}
        </div>
    );
}
