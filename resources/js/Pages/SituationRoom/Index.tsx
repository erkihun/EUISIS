import { Head } from '@inertiajs/react';
import { Alert } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { ActionsBlock, BriefingHero, ComparisonBlock, ExploreCard, InstitutionsBlock, JumpNav, KpiGroups, PeriodSwitch, QualityBlock, SectionHeading, Skeleton, StructureBlock, useIssues, type ExploreItem, type JumpTarget } from '@/Components/SituationRoom/Briefing';
import HeaderActions from '@/Components/SituationRoom/HeaderActions';
import { PanelCard } from '@/Components/SituationRoom/Panels';
import { SECTION_ACCENT } from '@/Components/SituationRoom/sectionAccent';
import { Freshness, ScopeTrail } from '@/Components/SituationRoom/SituationFilters';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { useLocale } from '@/hooks/useLocale';
import type { SrBriefing, SrCategory, SrFrameProps, SrKpiItem, SrResult } from '@/types/situationRoom';

type Props = SrFrameProps & { result?: SrResult; briefing?: SrBriefing; privacy: { small_cell: number }; settings_href: string | null };

const SECTIONS: SrCategory['section'][] = ['command', 'people', 'public_voice', 'operations', 'governance'];

/**
 * The Situation Room's landing page, read top to bottom as a briefing: what the
 * figures say and what needs attention, the headlines by area, how the period
 * compares, how far the figures can be trusted, then every area to go deeper.
 * A sticky row of jump links keeps each part one click away. Everything is
 * inside the viewer's scope and period.
 */
export default function SituationRoomIndex({ categories, filters, query, scope, can, result, briefing, privacy, settings_href }: Props) {
    const { sr } = useSituationLabels();
    const { t } = useLocale();
    const url = route('situation-room.index');
    const panels = result?.payload.panels ?? [];
    const kpiPanel = panels.find((p) => p.type === 'kpis');
    const items: SrKpiItem[] = kpiPanel?.type === 'kpis' ? kpiPanel.data.items : [];
    const panel = (key: string) => panels.find((p) => p.key === key);
    const categoryHref = (key: string) => {
        const target = categories.find((c) => c.key === key);
        return target ? route('situation-room.category', { category: key, ...query }) : null;
    };
    const issues = useIssues({ items, briefing, query, settingsHref: settings_href, categories });
    const trend = panel('overview_public_voice_trend');
    const headcount = panel('overview_headcount');
    const explore: ExploreItem[] = categories
        .filter((c) => c.key !== 'overview')
        .map((c) => ({ key: c.key, section: c.section, icon: c.icon, href: c.href, data: query, title: sr(`categories.${c.key}.title`), description: sr(`categories.${c.key}.description`) }));
    if (can.community_voice) {
        explore.push({ key: 'community_voice', section: 'public_voice', icon: 'MessageSquareIcon', href: route('situation-room.community-voice'), data: query, title: sr('categories.community_voice.title'), description: sr('categories.community_voice.description') });
    }
    if (can.intelligence) {
        explore.push({ key: 'intelligence', section: 'command', icon: 'LightbulbIcon', href: route('intelligence.index'), title: t('intelligence.title'), description: t('intelligence.fromSituationRoom') });
    }
    const hasStructure = Boolean(briefing && (briefing.structure.length > 0 || briefing.quality.length > 0));
    const jump: JumpTarget[] = [
        { id: 'sr-status', label: sr('briefing.nav_status') },
        { id: 'sr-headlines', label: sr('briefing.nav_headlines') },
        { id: 'sr-trends', label: sr('briefing.nav_trends') },
        ...(hasStructure ? [{ id: 'sr-structure', label: sr('briefing.nav_structure') }] : []),
        ...(explore.length > 0 ? [{ id: 'sr-explore', label: sr('briefing.nav_explore') }] : []),
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={sr('title')} description={sr('subtitle')} actions={<HeaderActions can={can} query={query} />} />}>
            <Head title={sr('title')} />

            <div className="space-y-5">
                {scope.empty && <Alert tone="warning">{sr('ui.empty_scope')}</Alert>}
                <ScopeTrail url={url} query={query} scope={scope} actions={<PeriodSwitch key={`${query.period ?? ''}${query.from ?? ''}`} url={url} query={query} filters={filters} />} />

                <JumpNav targets={jump} />

                <BriefingHero
                    scope={scope}
                    filters={filters}
                    items={items}
                    briefing={briefing}
                    issues={issues}
                    loading={!result || !briefing}
                    freshness={result ? <Freshness tone="dark" generatedAt={result.generated_at} cachedFor={result.cached_for} only={['result', 'briefing']} /> : undefined}
                />

                <SectionHeading id="sr-headlines" title={sr('panels.overview_kpis.title')} hint={sr('panels.overview_kpis.description')} />
                {!result
                    ? <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" role="status" aria-label={sr('ui.loading')}>{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-44" />)}</div>
                    : <KpiGroups items={items} comparison={briefing?.comparison} />}

                <SectionHeading id="sr-trends" title={sr('briefing.nav_trends')} hint={sr('briefing.comparison_hint')} />
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {!result && <Skeleton className="h-96 lg:col-span-2" />}
                    {trend && <div className="min-w-0 lg:col-span-2"><PanelCard panel={trend} options={{ smallCell: privacy.small_cell, accent: SECTION_ACCENT.public_voice }} /></div>}
                    {!briefing && <Skeleton className="h-96" />}
                    {briefing && briefing.comparison.length > 0 && <ComparisonBlock rows={briefing.comparison} href={categoryHref('trends')} />}
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {headcount && <div className="min-w-0"><PanelCard panel={headcount} options={{ smallCell: privacy.small_cell, accent: SECTION_ACCENT.people }} /></div>}
                    {briefing?.institutions && <InstitutionsBlock data={briefing.institutions} href={categoryHref('trends')} />}
                    {result && <ActionsBlock panel={panel('overview_actions')} href={route('situation-room.actions.index')} />}
                </div>

                {hasStructure && briefing && (
                    <>
                        <SectionHeading id="sr-structure" title={sr('briefing.nav_structure')} hint={sr('briefing.structure_hint')} />
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            {briefing.structure.length > 0 && <div className="min-w-0 lg:col-span-2"><StructureBlock items={briefing.structure} placement={briefing.placement} href={categoryHref('structure')} /></div>}
                            {briefing.quality.length > 0 && <QualityBlock checks={briefing.quality} href={categoryHref('data_quality')} />}
                        </div>
                    </>
                )}

                {categories.length === 0 && <Alert tone="info">{sr('ui.nothing_available')}</Alert>}

                {explore.length > 0 && (
                    <section id="sr-explore" aria-labelledby="sr-explore-title" className="scroll-mt-16 space-y-5 pt-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h2 id="sr-explore-title" className="text-base font-bold tracking-tight text-(--app-foreground)">{sr('briefing.explore_title')}</h2>
                            <p className="text-xs text-(--app-muted-foreground)">{sr('briefing.explore_hint')}</p>
                        </div>
                        {SECTIONS.map((section) => {
                            const cards = explore.filter((item) => item.section === section);
                            if (cards.length === 0) return null;
                            return (
                                <div key={section} className="space-y-2.5">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)">
                                        <span aria-hidden="true" className="h-3.5 w-1 rounded-full" style={{ background: SECTION_ACCENT[section] }} />
                                        {sr(`sections.${section}`)}
                                        <span className="rounded-full bg-(--app-surface-muted) px-1.5 text-[11px] font-medium tabular-nums text-(--app-muted-foreground)">{cards.length}</span>
                                    </h3>
                                    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                        {cards.map((item) => <li key={item.key}><ExploreCard item={item} headlines={briefing?.headlines[item.key]} /></li>)}
                                    </ul>
                                </div>
                            );
                        })}
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
