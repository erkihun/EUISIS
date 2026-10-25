/**
 * Contracts shared with the public submission backend. Values are the
 * server's stable codes (App\Enums\PublicTipType, ServiceFeedbackType); the
 * page translates them, it never sends a label.
 */

/** Internal submission types. `suggestion` is a Tip-off (ጥቆማ) to the public. */
export type SubmissionKind = 'feedback' | 'suggestion' | 'complaint';

/** App\Enums\PublicTipType. */
export type TipType = 'CORRUPTION' | 'IMPROPER_PRACTICE';

export const TIP_TYPES: readonly TipType[] = ['CORRUPTION', 'IMPROPER_PRACTICE'];

/** Analytics only: a Tip-off stored before tip types existed. */
export type TipTypeBucket = TipType | 'UNCLASSIFIED_LEGACY';

/** App\Services\ServiceFeedback\SubmissionRequirements::toPublicArray(). */
export interface SubmissionRequirements {
    type: SubmissionKind;
    name_required: boolean;
    phone_required: boolean;
    anonymous_allowed: boolean;
    tip_type_required: boolean;
    service_required: boolean;
    narrative_required: boolean;
    evidence_allowed: boolean;
    incident_context: boolean;
    involved_parties: boolean;
    submitter_address: boolean;
    submitter_organization: boolean;
}

/** App\Services\ServiceFeedback\PublicEvidencePolicy::toPublicArray(). */
export interface EvidenceLimits {
    max_files: number;
    max_total_mb: number;
    max_mb: Record<'photo' | 'video' | 'audio' | 'document', number>;
    accept: string;
}

/** Tip-off counts; reconcile: total = CORRUPTION + IMPROPER_PRACTICE + UNCLASSIFIED_LEGACY. */
export interface TipOffBreakdown {
    total: number;
    CORRUPTION: number;
    IMPROPER_PRACTICE: number;
    UNCLASSIFIED_LEGACY: number;
}
