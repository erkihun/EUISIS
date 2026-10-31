import { useCallback } from 'react';
import type { Tone } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';

/** The badge tones of the Blade portal: pending orange, active blue, closed grey. */
export const CASE_STATUS_TONES: Record<string, Tone> = { pending: 'warning', active: 'info', adjourned: 'info', closed: 'neutral', dismissed: 'neutral' };

/** Review of a case or a response: awaiting and returned amber, rejected red, accepted green. */
export const REVIEW_TONES: Record<string, Tone> = { awaiting_review: 'warning', returned: 'warning', rejected: 'danger', accepted: 'success' };

export const LETTER_TONES: Record<string, Tone> = { approved: 'success', returned: 'warning', rejected: 'danger', draft: 'neutral' };

const REVIEW_KEYS = ['awaiting_review', 'returned', 'rejected'];

/** Status labels as the Blade portal printed them (`cases.status.*`, `cases.review_status.*`). */
export function useStatusLabels() {
    const { t, tx } = useRespondentText();

    const caseStatus = useCallback((status: string | null | undefined): string => {
        const value = status || 'pending';
        return tx(`cm.cases.status.${value}`, value.charAt(0).toUpperCase() + value.slice(1));
    }, [tx]);

    // Anything but awaiting, returned or rejected reads as accepted, as on the Blade pages.
    const reviewStatus = useCallback((status: string): string =>
        t(`cm.cases.review_status.${REVIEW_KEYS.includes(status) ? status : 'accepted'}`), [t]);

    const reviewTone = useCallback((status: string): Tone => REVIEW_TONES[REVIEW_KEYS.includes(status) ? status : 'accepted'], []);

    return { caseStatus, reviewStatus, reviewTone };
}
