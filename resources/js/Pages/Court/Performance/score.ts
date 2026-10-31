import type { Tone } from '@/Components/ui';
import type { EvaluationPeriodType, EvaluationStatus } from '@/court/types/performance';

/** The period types, in the order the Blade pages listed them. */
export const PERIOD_TYPES: EvaluationPeriodType[] = ['monthly', 'quarterly', 'annual'];

export const STATUS_TONES: Record<EvaluationStatus, Tone> = { draft: 'warning', submitted: 'info', reviewed: 'success' };

/** An overall score (0-100) as the Blade pages printed it: one decimal. */
export function formatScore(score: number): string {
    return Number(score ?? 0).toFixed(1);
}

/** The overall score's colour: 85 and up excellent, 70 good, 50 satisfactory, below that needs improvement. */
export function overallTextClass(score: number): string {
    if (score >= 85) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 70) return 'text-blue-600 dark:text-blue-400';
    if (score >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
}

export function overallBarClass(score: number): string {
    if (score >= 85) return 'bg-emerald-500';
    if (score >= 70) return 'bg-blue-500';
    if (score >= 50) return 'bg-amber-400';
    return 'bg-red-500';
}

/** The cm.performance.score_labels.* key for an overall score. */
export function overallLabelKey(score: number): 'excellent' | 'good' | 'satisfactory' | 'needs_improvement' {
    if (score >= 85) return 'excellent';
    if (score >= 70) return 'good';
    if (score >= 50) return 'satisfactory';
    return 'needs_improvement';
}

/** One criterion's score (0-10): 8 and up green, 6 blue, 4 amber, below that red. */
export function criterionTextClass(score: number): string {
    if (score >= 8) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 6) return 'text-blue-600 dark:text-blue-400';
    if (score >= 4) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-500 dark:text-red-400';
}

export function criterionBarClass(score: number): string {
    if (score >= 8) return 'bg-emerald-500';
    if (score >= 6) return 'bg-blue-500';
    if (score >= 4) return 'bg-amber-400';
    return 'bg-red-500';
}
