/**
 * Presentation helpers for the public transfer pages.
 *
 * The admin side stores an eligibility rule as `{ type, operator, value }`
 * (Pages/Transfers/Announcements/Create.tsx); an announcement created before
 * that may still carry a plain sentence. Both shapes have to read as text —
 * handing an object to React as a child takes the whole public page down.
 */

export type EligibilityRule = { type: string; operator: string; value: string };

type Translate = (key: string) => string;

const RULE_TYPE_KEYS: Record<string, string> = {
    employment_status: 'transfers.ruleEmploymentStatus',
    current_grade: 'transfers.ruleCurrentGrade',
    current_organization: 'transfers.ruleCurrentOrganization',
    current_position: 'transfers.ruleCurrentPosition',
    minimum_service_months: 'transfers.ruleMinimumService',
};

const OPERATOR_KEYS: Record<string, string> = {
    equals: 'transfers.ruleEquals',
    in: 'transfers.ruleIn',
    greater_than_or_equal: 'transfers.ruleAtLeast',
};

function humanize(value: unknown): string {
    return String(value ?? '').replaceAll('_', ' ');
}

/** One rule as a localized sentence: "Employment status is active". */
export function formatEligibilityRule(rule: EligibilityRule | string | null | undefined, t: Translate): string {
    if (rule === null || rule === undefined) return '';
    if (typeof rule === 'string') return rule;

    const subject = RULE_TYPE_KEYS[rule.type] ? t(RULE_TYPE_KEYS[rule.type]) : humanize(rule.type);
    const operator = OPERATOR_KEYS[rule.operator] ? t(OPERATOR_KEYS[rule.operator]) : humanize(rule.operator);

    return [subject, operator, String(rule.value ?? '')].filter((part) => part !== '').join(' ');
}

function formatAmount(value: string | number | null | undefined, locale: string): string | null {
    if (value === null || value === undefined || value === '') return null;

    const amount = typeof value === 'number' ? value : Number(String(value).replace(/,/g, ''));
    if (!Number.isFinite(amount)) return String(value);

    return new Intl.NumberFormat(locale === 'am' ? 'am' : 'en', { maximumFractionDigits: 0 }).format(amount);
}

/** "9,000 – 12,500"; one bound alone; null when neither is set. */
export function formatSalaryRange(
    min: string | number | null | undefined,
    max: string | number | null | undefined,
    locale: string,
): string | null {
    const parts = [formatAmount(min, locale), formatAmount(max, locale)].filter((part): part is string => part !== null);

    return parts.length === 0 ? null : parts.join(' – ');
}
