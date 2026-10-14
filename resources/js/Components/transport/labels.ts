import { useCallback } from 'react';
import type { PaginationMeta as UiPaginationMeta, Tone } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import type { Named, PaginationMeta } from '@/types/transport';

const TONES: Record<string, Tone> = {
    active: 'success', accepted: 'success', boarding: 'info', scheduled: 'info', completed: 'neutral', departed: 'neutral',
    maintenance: 'warning', suspended: 'warning', inactive: 'neutral', expired: 'neutral', cancelled: 'danger', rejected: 'danger',
};

/** Locale-aware names and the shared transport vocabularies (statuses, reasons, scopes). */
export function useTransportLabels() {
    const { t, locale } = useLocale();

    const name = useCallback(
        (value: Partial<Named> | null | undefined, fallback = '—'): string =>
            (locale === 'am' ? value?.name_am || value?.name_en : value?.name_en || value?.name_am) || fallback,
        [locale],
    );

    /** A label for a vocabulary value; an unknown value shows as itself rather than as a key. */
    const label = useCallback(
        (group: 'statuses' | 'reasons' | 'scopeLabels' | 'vehicleTypes' | 'settingLabels' | 'settingHelp', value: string | null | undefined): string => {
            if (!value) return '—';
            const key = `transport.${group}.${value}`;
            const text = t(key);
            return text === key ? value : text;
        },
        [t],
    );

    const tone = (status: string | null | undefined): Tone => TONES[status ?? ''] ?? 'neutral';

    return { t, locale, name, label, tone };
}

/** Laravel's paginator meta in the shape the kit's <Pagination> takes. */
export function uiMeta(meta: PaginationMeta): UiPaginationMeta {
    return { currentPage: meta.current_page, lastPage: meta.last_page, perPage: meta.per_page, total: meta.total, from: meta.from ?? null, to: meta.to ?? null };
}
