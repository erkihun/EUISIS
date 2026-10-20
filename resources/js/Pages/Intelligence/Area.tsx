import { Deferred, Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AIStatusBadge from '@/Components/intelligence/AIStatusBadge';
import InsightCard from '@/Components/intelligence/InsightCard';
import PanelSkeleton from '@/Components/SituationRoom/PanelSkeleton';
import { PanelGrid } from '@/Components/SituationRoom/Panels';
import { Freshness, ScopeTrail, SituationFilterBar } from '@/Components/SituationRoom/SituationFilters';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { IntelligenceAnalysisFrame, IntelligenceAreaInfo, IntelligenceFrame } from '@/types/intelligence';

type Props = IntelligenceFrame & Partial<IntelligenceAnalysisFrame> & { area: IntelligenceAreaInfo };

/**
 * One Intelligence area. The frame (scope trail, filters) paints at once; the
 * insights and panels are a deferred prop. An area without an analysis class
 * says so instead of showing a placeholder figure (docs/intelligence-architecture.md, D-5).
 */
export default function Area({ area, ai, labels, filters, query, scope, options, result }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const analysed = Boolean(labels && filters && scope && options);
    const url = route('intelligence.area', area.key);
    const title = t(`intelligence.areas.${area.key}.title`);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={title}
                    description={t(`intelligence.areas.${area.key}.description`)}
                    backHref={route('intelligence.index')}
                    actions={<AIStatusBadge enabled={ai.enabled} provider={ai.provider} />}
                />
            }
        >
            <Head title={`${title} · ${t('intelligence.title')}`} />

            {!analysed ? (
                <Alert tone="info" title={t('intelligence.notReady.title')}>{t('intelligence.notReady.body')}</Alert>
            ) : (
                <div className="space-y-5">
                    {scope!.empty && <Alert tone="warning">{sr('ui.empty_scope')}</Alert>}
                    <ScopeTrail url={url} query={query!} scope={scope!} />
                    <SituationFilterBar url={url} filters={filters!} query={query!} scope={scope!} options={options!} />

                    <Deferred data="result" fallback={<PanelSkeleton label={sr('ui.loading')} />}>
                        <div className="space-y-5">
                            {result && <Freshness generatedAt={result.generated_at} cachedFor={result.cached_for} />}
                            {result && result.payload.insights.length > 0 && (
                                <section aria-labelledby="intelligence-insights-title">
                                    <h2 id="intelligence-insights-title" className="mb-3 text-section-title text-(--app-foreground)">{sr('intelligence.ui.insights')}</h2>
                                    <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                        {result.payload.insights.map((insight) => (
                                            <li key={insight.key}><InsightCard insight={insight} /></li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                            {result && result.payload.insights.length === 0 && (
                                <p className="text-sm text-(--app-muted-foreground)">{sr('intelligence.ui.no_insights')}</p>
                            )}
                            <PanelGrid panels={result?.payload.panels ?? []} options={{ smallCell: 5 }} />
                        </div>
                    </Deferred>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
