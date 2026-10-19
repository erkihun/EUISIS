import { badgeClassName, type Tone } from '@/Components/ui';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { IncidentStatus, RecommendationRisk, ReviewStatus } from '@/types/intelligenceSystem';

const STATUS_TONE: Record<IncidentStatus, Tone> = { open: 'danger', investigating: 'warning', mitigated: 'info', resolved: 'success', closed: 'neutral' };
const RISK_TONE: Record<RecommendationRisk, Tone> = { safe_check: 'success', potential_fix: 'warning', high_risk: 'danger' };
const REVIEW_TONE: Record<ReviewStatus, Tone> = { pending: 'neutral', useful: 'success', accepted: 'success', not_useful: 'warning', rejected: 'danger', incorrect_diagnosis: 'danger' };

export function IncidentStatusBadge({ status }: { status: IncidentStatus }) {
    const { sr } = useSituationLabels();

    return <span className={badgeClassName(STATUS_TONE[status])}>{sr(`intelligence.status.${status}`)}</span>;
}

/** Safe check, potential fix or high-risk change: how a person should treat the recommendation (brief §104). */
export function RiskBadge({ risk }: { risk: RecommendationRisk }) {
    const { sr } = useSituationLabels();

    return <span className={badgeClassName(RISK_TONE[risk])}>{sr(`intelligence.risk.${risk}`)}</span>;
}

export function ReviewBadge({ status }: { status: ReviewStatus }) {
    const { sr } = useSituationLabels();

    return <span className={badgeClassName(REVIEW_TONE[status])}>{sr(`intelligence.review.${status}`)}</span>;
}

/** Filter chips as links: the server re-reads the query, nothing reloads from a select. */
export function FilterChips({ label, options, value, build }: { label: string; options: Array<{ key: string; label: string }>; value: string; build: (key: string) => string }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className="mr-1 text-xs font-medium text-(--app-muted-foreground)">{label}</span>
            {options.map((option) => (
                <a
                    key={option.key}
                    href={build(option.key)}
                    aria-current={option.key === value ? 'true' : undefined}
                    className={option.key === value
                        ? 'rounded-control bg-primary px-2.5 py-1 text-xs font-medium text-white'
                        : 'rounded-control border border-(--app-border) px-2.5 py-1 text-xs text-(--app-foreground) hover:border-primary'}
                >
                    {option.label}
                </a>
            ))}
        </div>
    );
}
