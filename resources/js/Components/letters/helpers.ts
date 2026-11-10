import { useLocale } from '@/hooks/useLocale';
import { cx, textareaClassName } from '@/Components/ui';
import { fill } from '@/Components/dailyActivity/helpers';
import type { Named } from '@/types/letters';
import type { Tone } from '@/Components/ui';

export { dangerBtn, fill, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';

/** Textareas grow with their text (the kit's textarea); inputCls would pin them to one line. */
export const textareaCls = cx(textareaClassName, 'w-full');

export const errorCls = 'mt-1 text-xs text-red-700 dark:text-red-400';

export const sectionTitleCls = 'text-base font-semibold text-(--app-foreground)';

export const mutedCls = 'text-sm text-(--app-muted-foreground)';

/** The module's translator: `lt('show.title', { name })` reads letters.show.title and fills placeholders. */
export function useLetters() {
    const { t, locale } = useLocale();
    const lt = (key: string, params?: Record<string, string | number>): string => {
        const text = t(`letters.${key}`);
        return params ? fill(text, params) : text;
    };
    const name = (value: Named | null | undefined): string => {
        if (!value) return '—';
        return (locale === 'am' ? value.name_am || value.name_en : value.name_en || value.name_am) ?? '—';
    };
    /** A bilingual pair the way the pick-lists carry it. */
    const pick = (value: { name_en: string | null; name_am?: string | null; code?: string | null } | null | undefined): string => {
        if (!value) return '—';
        const label = (locale === 'am' && value.name_am ? value.name_am : value.name_en ?? value.name_am) ?? '—';
        return value.code ? `${value.code} · ${label}` : label;
    };

    return { lt, t, locale, name, pick };
}

export function statusTone(status: string): Tone {
    switch (status) {
        case 'dispatched':
        case 'received':
        case 'completed':
        case 'closed':
        case 'published':
            return 'success';
        case 'pending_approval':
        case 'pending_receipt':
        case 'pending':
        case 'routed':
            return 'warning';
        case 'approved':
        case 'in_progress':
        case 'acknowledged':
        case 'registered':
            return 'info';
        case 'cancelled':
        case 'returned':
        case 'archived':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function priorityTone(priority: string): Tone {
    return priority === 'very_urgent' ? 'danger' : priority === 'urgent' ? 'warning' : 'neutral';
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Days from today to a date; negative once it has passed. */
export function daysUntil(iso: string): number {
    const target = new Date(`${iso}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((target.getTime() - today.getTime()) / 86_400_000);
}
