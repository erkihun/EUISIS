import { badgeClassName, type Tone } from '@/Components/ui';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { InsightConfidence, InsightSeverity } from '@/types/intelligence';

const SEVERITY_TONE: Record<InsightSeverity, Tone> = {
    critical: 'danger',
    high: 'danger',
    medium: 'warning',
    low: 'info',
    info: 'neutral',
};

/** The rule-assigned severity of an insight, incident or alert. */
export function SeverityBadge({ severity, className }: { severity: InsightSeverity; className?: string }) {
    const { sr } = useSituationLabels();

    return <span className={badgeClassName(SEVERITY_TONE[severity], className)}>{sr(`intelligence.severity.${severity}`)}</span>;
}

const CONFIDENCE_TONE: Record<InsightConfidence, Tone> = { high: 'success', medium: 'warning', low: 'neutral' };

/**
 * How sure an AI- or forecast-derived statement is, in words. A deterministic
 * figure carries no confidence badge: it is a count, not an estimate.
 */
export function ConfidenceBadge({ confidence, className }: { confidence: InsightConfidence; className?: string }) {
    const { sr } = useSituationLabels();

    return (
        <span className={badgeClassName(CONFIDENCE_TONE[confidence], className)} title={sr(`intelligence.confidence.${confidence}_help`)}>
            {sr('intelligence.ui.confidence')}: {sr(`intelligence.confidence.${confidence}`)}
        </span>
    );
}

type Health = 'healthy' | 'degraded' | 'unhealthy' | 'unknown';
const HEALTH_TONE: Record<Health, Tone> = { healthy: 'success', degraded: 'warning', unhealthy: 'danger', unknown: 'neutral' };

/** A component's health state; `unknown` is shown as such, never as green. */
export function HealthBadge({ state, className }: { state: Health; className?: string }) {
    const { sr } = useSituationLabels();

    return <span className={badgeClassName(HEALTH_TONE[state], className)}>{sr(`intelligence.health.${state}`)}</span>;
}
