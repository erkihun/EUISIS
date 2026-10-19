import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Button, Card } from '@/Components/ui';
import { ChevronDown, ChevronRight } from '@/Components/Icons';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { ConfidenceBadge, SeverityBadge } from './Badges';
import type { IntelligenceInsight } from '@/types/intelligence';

function formatValue(value: string | number | null, unit: string | null | undefined, sr: (k: string) => string): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'number') {
        const text = Number.isInteger(value) ? value.toLocaleString() : value.toLocaleString(undefined, { maximumFractionDigits: 1 });

        return unit === 'percent' ? `${text}%` : unit ? `${text} ${sr(`units.${unit}`)}` : text;
    }

    return value;
}

/**
 * One insight: title, what changed, why it matters, the evidence behind it
 * (one click away), the recommended next step, and — only when the statement
 * is an estimate — how confident it is. Never "AI detected a risk" without
 * the figures that say so (brief §92–94).
 */
export default function InsightCard({ insight }: { insight: IntelligenceInsight }) {
    const { sr, label } = useSituationLabels();
    const [open, setOpen] = useState(false);
    const change = insight.change;
    const arrow = change?.direction === 'up' ? '↑' : change?.direction === 'down' ? '↓' : '→';

    return (
        <Card className="flex h-full flex-col gap-3 p-4" aria-labelledby={`insight-${insight.key}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h3 id={`insight-${insight.key}`} className="min-w-0 flex-1 font-semibold text-(--app-foreground)">
                    {sr(insight.title, insight.title_params)}
                </h3>
                <div className="flex shrink-0 flex-wrap gap-1.5">
                    <SeverityBadge severity={insight.severity} />
                    {insight.confidence && <ConfidenceBadge confidence={insight.confidence} />}
                </div>
            </div>

            {insight.summary && <p className="text-sm text-(--app-foreground)">{sr(insight.summary, insight.summary_params)}</p>}

            {change && (change.current !== null || change.baseline !== null) && (
                <dl className="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                    <div className="flex items-baseline gap-1.5">
                        <dt className="text-(--app-muted-foreground)">{sr('intelligence.ui.current')}</dt>
                        <dd className="font-semibold tabular-nums text-(--app-foreground)">{formatValue(change.current, change.unit, sr)}</dd>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                        <dt className="text-(--app-muted-foreground)">{sr('intelligence.ui.baseline')}</dt>
                        <dd className="tabular-nums text-(--app-foreground)">{formatValue(change.baseline, change.unit, sr)}</dd>
                    </div>
                    {change.percent !== null && (
                        <div className="flex items-baseline gap-1.5">
                            <dt className="text-(--app-muted-foreground)">{sr('intelligence.ui.change')}</dt>
                            <dd className="tabular-nums font-medium text-(--app-foreground)">{arrow} {Math.abs(change.percent).toLocaleString(undefined, { maximumFractionDigits: 1 })}%</dd>
                        </div>
                    )}
                </dl>
            )}

            {insight.recommendation && (
                <p className="rounded-control bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)">
                    <span className="font-medium">{sr('intelligence.ui.recommendation')}: </span>
                    {sr(insight.recommendation, insight.recommendation_params)}
                </p>
            )}

            <div className="mt-auto flex flex-wrap items-center gap-2 pt-1">
                {insight.evidence.length > 0 && (
                    <Button variant="ghost" size="sm" icon={open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />} aria-expanded={open} aria-controls={`insight-${insight.key}-evidence`} onClick={() => setOpen((v) => !v)}>
                        {sr('intelligence.ui.view_evidence')} ({insight.evidence.length})
                    </Button>
                )}
                {insight.href && (
                    <Link href={insight.href} className="text-sm font-medium text-primary hover:underline">{sr('intelligence.ui.open_detail')}</Link>
                )}
                {insight.source && <span className="ml-auto text-xs text-(--app-muted-foreground)">{sr('intelligence.ui.source')}: {sr(insight.source)}</span>}
            </div>

            {open && insight.evidence.length > 0 && (
                <dl id={`insight-${insight.key}-evidence`} className="divide-y divide-(--app-border) rounded-control border border-(--app-border) text-sm">
                    {insight.evidence.map((row, index) => (
                        <div key={index} className="flex items-baseline justify-between gap-3 px-3 py-1.5">
                            <dt className="text-(--app-muted-foreground)">{label(row.label)}</dt>
                            <dd className="tabular-nums font-medium text-(--app-foreground)">
                                {row.href ? <Link href={row.href} className="text-primary hover:underline">{formatValue(row.value, null, sr)}</Link> : formatValue(row.value, null, sr)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </Card>
    );
}
