import { useCallback } from 'react';
import type { PaginationMeta as UiPaginationMeta, Tone } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import type { Named, PaginationMeta } from '@/types/transport';

const TONES: Record<string, Tone> = {
    active: 'success', accepted: 'success', approved: 'success', paid: 'success', reviewed: 'success', online: 'success', offline_reconciled: 'success',
    boarding: 'info', in_progress: 'info', planned: 'info', assigned: 'info', scheduled: 'info', submitted: 'info', under_review: 'info', draft: 'neutral',
    completed: 'neutral', departed: 'neutral', superseded: 'neutral', ended: 'neutral', dismissed: 'neutral', withdrawn: 'neutral', returned: 'warning',
    maintenance: 'warning', suspended: 'warning', pending: 'warning', disputed: 'warning', open: 'warning', offline_pending: 'warning', warning: 'warning',
    inactive: 'neutral', expired: 'neutral', info: 'neutral', cancelled: 'danger', failed: 'danger', rejected: 'danger', revoked: 'danger', voided: 'danger', critical: 'danger', offline_rejected: 'danger',
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
        (group: 'statuses' | 'reasons' | 'scopeLabels' | 'vehicleTypes' | 'settingLabels' | 'settingHelp' | 'directions' | 'methods' | 'settlementModels' | 'settlementPeriods' | 'exceptionTypes' | 'adjustmentOrigins' | 'disputeTypes' | 'occupancy' | 'dataQuality' | 'subjectTypes' | 'severities' | 'syncStates', value: string | null | undefined): string => {
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
