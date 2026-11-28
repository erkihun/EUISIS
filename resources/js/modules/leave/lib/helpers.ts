import { badgeClassName, buttonClassName, cx, inputClassName, textareaClassName } from '@/Components/ui';
import type { Tone } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { Bilingual, LeaveStatus } from '@/modules/leave/types';

/* Form and surface classes, from the application's one visual system. */
export const inputCls = cx(inputClassName, 'w-full');
export const textareaCls = cx(textareaClassName, 'w-full');
export const primaryBtn = buttonClassName({ variant: 'primary' });
export const secondaryBtn = buttonClassName({ variant: 'outline' });
export const dangerBtn = buttonClassName({ variant: 'destructiveOutline' });
export const panelCls = 'rounded-panel border border-(--app-border) bg-(--app-surface)';
export const labelCls = 'mb-1 block text-xs font-medium text-(--app-muted-foreground)';
export const errorCls = 'mt-1 text-xs text-red-700 dark:text-red-400';
export const mutedCls = 'text-sm text-(--app-muted-foreground)';
export const sectionTitleCls = 'text-base font-semibold text-(--app-foreground)';
export const badge = badgeClassName;

export const STATUS_TONE: Record<LeaveStatus, Tone> = {
    pending_supervisor: 'warning',
    pending_hr: 'info',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

export function fill(template: string, params: Record<string, string | number>): string {
    return template.replace(/\{(\w+)\}/g, (match, key: string) => (key in params ? String(params[key]) : match));
}

/** 5 → "5", 2.5 → "2.5": leave is counted in half days at the smallest. */
export function formatDays(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * The module's translator and formatters. `lt('request.title')` reads
 * leave.request.title; `{name}` placeholders are filled from `params`.
 */
export function useLeave() {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const lt = (key: string, params?: Record<string, string | number>): string => {
        const text = t(`leave.${key}`);

        return params ? fill(text, params) : text;
    };
    const name = (value: Bilingual | null | undefined): string => {
        if (!value) return '—';

        return (am ? value.name_am || value.name_en : value.name_en || value.name_am) ?? '—';
    };
    /** "5 working days", "2.5 days": the unit follows the leave type. */
    const dayCount = (value: number, unit: 'working' | 'calendar' = 'calendar'): string =>
        lt(unit === 'working' ? 'common.workingDays' : 'common.days', { n: formatDays(value) });

    return { lt, name, dayCount, am, locale };
}
