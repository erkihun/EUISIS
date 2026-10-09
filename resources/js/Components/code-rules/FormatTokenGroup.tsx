import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import FormatTokenBadge from './FormatTokenBadge';

export type TokenDefinition = {
    token: string;
    label_en: string;
    label_am: string;
    description_en: string;
    description_am: string;
    category: string;
    requires_context: boolean;
    example: string;
    is_active: boolean;
};

const CATEGORY_LABEL_KEYS: Record<string, string> = {
    Core: 'codeRules.categoryCore',
    DateAndTime: 'codeRules.categoryDateTime',
    Organization: 'codeRules.categoryOrganization',
    Employee: 'codeRules.categoryEmployee',
    Position: 'codeRules.categoryPosition',
    Service: 'codeRules.categoryService',
    Location: 'codeRules.categoryLocation',
    Workflow: 'codeRules.categoryWorkflow',
    Custom: 'codeRules.categoryCustom',
};

export default function FormatTokenGroup({
    category,
    tokens,
    onInsert,
    usedTokens,
    defaultOpen = false,
}: {
    category: string;
    tokens: TokenDefinition[];
    onInsert: (token: string) => void;
    usedTokens: string[];
    defaultOpen?: boolean;
}) {
    const { t, locale } = useLocale();
    const [open, setOpen] = useState(defaultOpen);

    const labelKey = CATEGORY_LABEL_KEYS[category];
    const categoryLabel = labelKey ? t(labelKey) : category;

    return (
        <div className="border-b border-gray-100 last:border-0 dark:border-slate-800">
            <button
                type="button"
                className="flex w-full items-center justify-between px-1 py-2 text-xs font-semibold text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
            >
                <span>{categoryLabel}</span>
                <svg
                    className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2.5}
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {open && (
                <div className="flex flex-wrap gap-1.5 pb-3 pt-1">
                    {tokens.map((tokenDef) => {
                        const isUsed = usedTokens.includes(tokenDef.token);
                        const label = locale === 'am' ? tokenDef.label_am : tokenDef.label_en;
                        const description = locale === 'am' ? tokenDef.description_am : tokenDef.description_en;
                        const tooltip = `${label}\n${description}\n${t('codeRules.formatExamples')}: ${tokenDef.example}`;

                        if (!tokenDef.is_active) {
                            return (
                                <FormatTokenBadge
                                    key={tokenDef.token}
                                    token={tokenDef.token}
                                    variant="inactive"
                                    title={tooltip}
                                />
                            );
                        }

                        return (
                            <FormatTokenBadge
                                key={tokenDef.token}
                                token={tokenDef.token}
                                variant={isUsed ? 'active' : 'default'}
                                title={tooltip}
                                onClick={() => onInsert(tokenDef.token)}
                            />
                        );
                    })}
                </div>
            )}
        </div>
    );
}
