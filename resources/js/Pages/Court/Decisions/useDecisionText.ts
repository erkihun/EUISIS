import { useCallback } from 'react';
import { useLocale } from '@/hooks/useLocale';
import decisionForms, { type DecisionFormsTextKey } from '@/court/i18n/decisions';
import type { Tone } from '@/Components/ui';

/** The tone of each review outcome, as the Blade page coloured its chips. */
export const OUTCOME_TONES: Record<string, Tone> = {
    approve: 'success', reject: 'danger', improve: 'warning', pending: 'neutral',
};

/**
 * The translator of the decision pages: `t` for cm.* keys, `text` for the
 * handful of labels only these pages need (court/i18n/decisions.ts, read
 * through `court.decisionForms.*` once it is wired in), `fill` for the
 * `:placeholder` tokens of the lang strings.
 */
export function useDecisionText() {
    const { t, locale } = useLocale();
    const lang: 'en' | 'am' = locale === 'am' ? 'am' : 'en';

    const text = useCallback((key: DecisionFormsTextKey): string => {
        const wired = t(`court.decisionForms.${key}`);
        if (wired !== `court.decisionForms.${key}`) return wired;
        return decisionForms[lang][key] ?? decisionForms.en[key];
    }, [t, lang]);

    /** `__('x', ['name' => y])`: the lang strings carry `:name` placeholders. */
    const fill = useCallback((template: string, values: Record<string, string>): string =>
        Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(value), template), []);

    /** The label of a review outcome (Agree / Have difference / Improvement / Pending). */
    const outcomeLabel = useCallback((outcome: string | null | undefined): string => {
        if (!outcome) return '—';
        const value = t(`cm.decisions.reviews.${outcome}`);
        return value.startsWith('cm.') ? outcome : value;
    }, [t]);

    return { t, text, fill, locale, outcomeLabel };
}
