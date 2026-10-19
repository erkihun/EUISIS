import { Deferred, Head, Link } from '@inertiajs/react';
import { Alert, Card } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CategoryIcon from '@/Components/SituationRoom/CategoryIcon';
import HeaderActions from '@/Components/SituationRoom/HeaderActions';
import { KpiTiles, PanelGrid } from '@/Components/SituationRoom/Panels';
import { Freshness, ScopeTrail, SituationFilterBar } from '@/Components/SituationRoom/SituationFilters';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { useLocale } from '@/hooks/useLocale';
import type { SrCategory, SrFrameProps, SrResult } from '@/types/situationRoom';

type Props = SrFrameProps & { result?: SrResult; privacy: { small_cell: number } };

const SECTIONS: SrCategory['section'][] = ['command', 'people', 'public_voice', 'operations', 'governance'];

/**
 * The Situation Room launcher: one large card per category the viewer may
 * open, grouped by area, with the Executive Overview headlines underneath
 * once they have loaded. Everything is inside the viewer's scope.
 */
export default function SituationRoomIndex({ categories, filters, query, scope, can, result, privacy }: Props) {
    const { sr } = useSituationLabels();
    const { t } = useLocale();
    const url = route('situation-room.index');
    const kpis = result?.payload.panels.find((p) => p.type === 'kpis');

    return (
        <AuthenticatedLayout header={<PageHeader title={sr('title')} description={sr('subtitle')} actions={<HeaderActions can={can} query={query} />} />}>
            <Head title={sr('title')} />

            <div className="space-y-6">
                {scope.empty && <Alert tone="warning">{sr('ui.empty_scope')}</Alert>}
                <ScopeTrail url={url} query={query} scope={scope} />
                <SituationFilterBar url={url} filters={filters} query={query} scope={scope} />

                <section aria-labelledby="sr-headlines" className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="sr-headlines" className="text-base font-semibold text-(--app-foreground)">{sr('categories.overview.title')}</h2>
                        {result && <Freshness generatedAt={result.generated_at} cachedFor={result.cached_for} />}
                    </div>
                    <Deferred data="result" fallback={<p className="text-sm text-(--app-muted-foreground)" role="status">{sr('ui.loading')}</p>}>
                        <>
                            {kpis && kpis.type === 'kpis' && <KpiTiles items={kpis.data.items} />}
                        </>
                    </Deferred>
                </section>

                {categories.length === 0 && <Alert tone="info">{sr('ui.nothing_available')}</Alert>}

                {can.intelligence && (
                    <Link href={route('intelligence.index')} className="group block focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        <Card className="flex items-start gap-3 p-4 transition-colors group-hover:border-primary">
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-control bg-(--app-surface-muted) text-primary"><CategoryIcon name="LightbulbIcon" className="h-6 w-6" /></span>
                            <span className="min-w-0">
                                <span className="block font-semibold text-(--app-foreground)">{t('intelligence.title')}</span>
                                <span className="mt-1 block text-xs text-(--app-muted-foreground)">{t('intelligence.fromSituationRoom')}</span>
                            </span>
                        </Card>
                    </Link>
                )}

                {SECTIONS.map((section) => {
                    const items: SrCategory[] = categories.filter((c) => c.section === section);
                    // The institution-level Community Voice view sits with the community-voice categories.
                    if (section === 'public_voice' && can.community_voice) {
                        items.unshift({ key: 'community_voice', section, icon: 'MessageSquareIcon', wall: false, href: route('situation-room.community-voice') });
                    }
                    if (items.length === 0) return null;
                    return (
                        <section key={section} aria-labelledby={`sr-section-${section}`} className="space-y-3">
                            <h2 id={`sr-section-${section}`} className="text-sm font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{sr(`sections.${section}`)}</h2>
                            <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                {items.map((category) => (
                                    <li key={category.key}>
                                        <Link href={category.href} data={query} className="group block h-full focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                            <Card className="flex h-full items-start gap-3 p-4 transition-colors group-hover:border-primary">
                                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-control bg-(--app-surface-muted) text-primary">
                                                    <CategoryIcon name={category.icon} className="h-6 w-6" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block font-semibold text-(--app-foreground)">{sr(`categories.${category.key}.title`)}</span>
                                                    <span className="mt-1 block text-xs text-(--app-muted-foreground)">{sr(`categories.${category.key}.description`)}</span>
                                                </span>
                                            </Card>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}

                <Deferred data="result" fallback={null}>
                    <PanelGrid panels={(result?.payload.panels ?? []).filter((p) => p.type !== 'kpis')} options={{ smallCell: privacy.small_cell }} />
                </Deferred>
            </div>
        </AuthenticatedLayout>
    );
}
