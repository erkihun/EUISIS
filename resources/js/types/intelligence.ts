// Shared props of the Addis One Intelligence pages (IntelligenceController, IntelligenceService, Insight).
import type { SrFilters, SrLabel, SrLabels, SrPanel, SrQuery, SrScope } from '@/types/situationRoom';

export type IntelligenceSection = 'business' | 'knowledge' | 'system' | 'diagnostics' | 'governance';

export type IntelligenceAreaInfo = {
    key: string;
    section: IntelligenceSection;
    domain: 'business' | 'technical' | 'governance';
    icon: string;
    href: string;
};

export type IntelligenceFrame = {
    areas: IntelligenceAreaInfo[];
    sections: IntelligenceSection[];
    ai: { enabled: boolean; provider: string };
    can: { governance: boolean; manage_settings: boolean };
};

export type InsightSeverity = 'info' | 'low' | 'medium' | 'high' | 'critical';
export type InsightConfidence = 'high' | 'medium' | 'low';

export interface InsightChange {
    current: number | null;
    baseline: number | null;
    percent: number | null;
    unit: string | null;
    direction: 'up' | 'down' | 'flat';
}

export interface InsightEvidence {
    label: SrLabel;
    value: string | number | null;
    href?: string | null;
}

export interface IntelligenceInsight {
    key: string;
    severity: InsightSeverity;
    title: string;
    title_params: Record<string, string | number>;
    summary: string | null;
    summary_params: Record<string, string | number>;
    change: InsightChange | null;
    evidence: InsightEvidence[];
    recommendation: string | null;
    recommendation_params: Record<string, string | number>;
    confidence: InsightConfidence | null;
    href: string | null;
    source: string | null;
    metric: string | null;
}

export interface IntelligenceResult {
    generated_at: string;
    cached_for: number;
    payload: { panels: SrPanel[]; insights: IntelligenceInsight[]; has_errors: boolean };
}

/** Present only when the area has an analysis class (IntelligenceAreaRegistry). */
export type IntelligenceAnalysisFrame = {
    labels: SrLabels;
    filters: SrFilters;
    query: SrQuery;
    scope: SrScope;
    options: { units: Array<{ id: string; label: SrLabel }>; public_voice: boolean };
    result?: IntelligenceResult;
};
