// Payloads of the technical pages (IncidentController, RecommendationController, RunbookController).
import type { InsightConfidence, InsightSeverity } from '@/types/intelligence';
import type { LaravelPageMeta } from '@/Components/ui';

export type IncidentStatus = 'open' | 'investigating' | 'mitigated' | 'resolved' | 'closed';
export type VerificationStatus = 'pending' | 'verified' | 'failed';
export type ReviewStatus = 'pending' | 'useful' | 'not_useful' | 'accepted' | 'rejected' | 'incorrect_diagnosis';
export type RecommendationRisk = 'safe_check' | 'potential_fix' | 'high_risk';
export type RecommendationCategory = 'immediate' | 'short_term' | 'permanent' | 'verification' | 'management';

export interface IncidentSummary {
    id: string;
    reference: string;
    /** Dictionary key (`intelligence.rules.<rule>.title`) filled with `params`. */
    title: string;
    params: Record<string, string | number | null>;
    severity: InsightSeverity;
    status: IncidentStatus;
    component: string;
    endpoint: string | null;
    occurrences: number;
    detected_at: string;
    last_seen_at: string;
    owner: string | null;
    href: string;
}

export interface IncidentDetail extends IncidentSummary {
    rule: string;
    module: string | null;
    first_seen_at: string;
    mitigated_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    impact: string | null;
    evidence: { params: Record<string, string | number | null>; rows: Array<{ label: string; value: string | number | null }> };
    source_signals: string[];
    probable_cause: string | null;
    cause_confidence: InsightConfidence | null;
    verification_status: VerificationStatus | null;
    resolution_summary: string | null;
    deployment: { release: string; commit_hash: string | null; deployed_at: string; minutes_before: number } | null;
}

export interface IncidentEvent {
    id: string;
    event: string;
    from: string | null;
    to: string | null;
    note: string | null;
    payload: Record<string, unknown> | null;
    actor: string | null;
    occurred_at: string;
}

export interface RecommendationRow {
    id: string;
    category: RecommendationCategory;
    risk: RecommendationRisk;
    title: string;
    body: string;
    generated_by: 'rule' | 'ai';
    confidence: InsightConfidence | null;
    review_status: ReviewStatus;
    reviewed_at: string | null;
    reviewer: string | null;
    review_note: string | null;
    params: Record<string, string | number | null>;
    source_type?: string;
    generated_at?: string;
    incident?: { reference: string; severity: InsightSeverity; status: IncidentStatus; href: string } | null;
}

export interface FingerprintRow {
    exception_class: string;
    message: string;
    route_name: string | null;
    route_group: string;
    occurrences: number;
    first_seen_at: string;
    last_seen_at: string;
    last_error_id: string | null;
    sample_context: { file?: string; line?: number; frames?: string[]; method?: string } | null;
}

export interface Runbook {
    key: string;
    rules: string[];
    components: string[];
    steps: string[];
    title: string;
    summary: string;
    href?: string;
    active_incidents?: number;
}

export type Paginated<T> = LaravelPageMeta & { data: T[] };
