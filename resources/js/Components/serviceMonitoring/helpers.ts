import { createElement, Fragment, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { cx, tableClassName, textareaClassName } from '@/Components/ui';
import { fill } from '@/Components/dailyActivity/helpers';
import type { Named } from '@/types/serviceMonitoring';

export { dangerBtn, fill, inputCls, labelCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';

/** Wide official tables scroll inside their own container, never the page (360 px screens). */
export const scrollCls = 'overflow-x-auto rounded-panel border border-(--app-border) bg-(--app-surface)';

/** Textareas grow with their text (the kit's textarea); inputCls would pin them to one line. */
export const textareaCls = cx(textareaClassName, 'w-full');

/** The kit's table class with collapsed borders: the official forms draw a full cell grid. */
export const tableCls = cx(tableClassName, 'min-w-full border-collapse');

export const thCls = 'border border-(--app-border) bg-(--app-surface-muted) px-2 py-1.5 text-start text-xs font-medium text-(--app-foreground) align-top';

export const tdCls = 'border border-(--app-border) px-2 py-1.5 text-sm text-(--app-foreground) align-top';

export const errorCls = 'mt-1 text-xs text-red-700 dark:text-red-400';

/** The module's translator: `sm('edit.title', { name })` reads serviceMonitoring.edit.title and fills placeholders. */
export function useSm() {
    const { t, locale } = useLocale();
    const sm = (key: string, params?: Record<string, string | number>): string => {
        const text = t(`serviceMonitoring.${key}`);
        return params ? fill(text, params) : text;
    };
    const name = (value: Named | null | undefined): string => {
        if (!value) return '—';
        return (locale === 'am' ? value.name_am || value.name_en : value.name_en || value.name_am) ?? '—';
    };

    return { sm, t, locale, name };
}

/** Days from today (Addis Ababa) to a deadline; negative once it has passed. */
export function daysUntil(deadlineIso: string): number {
    const ms = new Date(deadlineIso).getTime() - Date.now();
    return Math.ceil(ms / 86_400_000);
}

/** Marks where a rendered date goes in a translated sentence (Amharic puts it first, English last). */
export const DATE_MARK = '\u2063';

/** Puts the date element where the translation's {date} was, e.g. placeDate(sm('x', { date: DATE_MARK }), <LocalizedDateDisplay … />). */
export function placeDate(text: string, date: ReactNode): ReactNode {
    const [before, after = ''] = text.split(DATE_MARK);

    return createElement(Fragment, null, before, date, after);
}
