import type { InsightConfidence } from '@/types/intelligence';
import type { Paginated } from '@/types/intelligenceSystem';

export type KnowledgeClassification = 'public' | 'internal' | 'confidential' | 'restricted';
export type KnowledgeStatus = 'draft' | 'approved' | 'retired';
export type KnowledgeMode = 'ai' | 'extractive' | 'none';

export type KnowledgeCitation = { n: number; document_id: string; title: string; chunk_id: string; position: number; heading: string | null };

export type KnowledgePassage = KnowledgeCitation & { classification: KnowledgeClassification; content: string; score: number };

/** What KnowledgeAssistant::ask() returns, flashed back to the question page. */
export type KnowledgeAnswer = {
    id: string;
    asked_at: string;
    mode: KnowledgeMode;
    question: string;
    answer: string | null;
    grounded: boolean;
    citations: KnowledgeCitation[];
    confidence: InsightConfidence | null;
    run_id: string | null;
    passages: KnowledgePassage[];
    notice: string | null;
};

export type KnowledgeQuestionRow = { id: string; question: string; mode: KnowledgeMode; grounded: boolean; citations: number; asked_at: string };

export type KnowledgeDocumentRow = {
    id: string;
    title: string;
    slug: string;
    source_type: 'manual' | 'repo_doc' | 'upload';
    source_path: string | null;
    classification: KnowledgeClassification;
    organization_id: string | null;
    required_permission: string | null;
    language: 'en' | 'am';
    status: KnowledgeStatus;
    version: number;
    chunks: number;
    approved_by: string | null;
    approved_at: string | null;
    created_by: string | null;
    updated_at: string | null;
};

export type KnowledgeDocumentPage = Paginated<KnowledgeDocumentRow>;

export type AiStatus = { enabled: boolean; provider: string; model: string | null; configured: boolean; breaker_open: boolean; calls_today: number; daily_cap: number; outbound_classes: string[] };
