import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AttentionSection, CategorySection, ExportsSection, InstitutionSection, KpiRow, RatingsSection, ResolutionSection, ServiceSection, SummarySection, SupportingSection, TrendSection, type AttentionItem, type CategoryRow, type ExportRow, type Institutions, type Kpis, type Ratings, type Resolution, type ServiceRow, type SummarySentence, type Trend } from '@/Components/SituationRoom/communityVoice/sections';
import { Breadcrumb, FilterBar, SectionSkeleton, fmt, useDateLabel, useNamer, viewQuery, type FilterOptions, type Filters, type ScopeProps } from '@/Components/SituationRoom/communityVoice/shared';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, buttonClassName } from '@euisis/ui';
import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    scope: ScopeProps;
    filters: Filters;
    filterOptions: FilterOptions;
    headline: { kpis: Kpis; trend: Trend; generated_at: string };
    comparison?: { institutions: Institutions; attention: AttentionItem[]; summary: SummarySentence[]; generated_at: string };
    breakdown?: { categories: CategoryRow[]; services: ServiceRow[]; resolution: Resolution; targets: Record<string, Record<string, number>>; ratings: Ratings; generated_at: string };
    supporting?: { cards: Record<string, Record<string, number | boolean>>; configuration: { without_qr: number; without_public_phone: number }; generated_at: string };
    exports: ExportRow[];
    rules: Record<string, number | null>;
    can: { employeeTargeted: boolean; viewDetail: boolean; export: boolean; wall: boolean; manageActions: boolean };
};

/**
 * Executive Situation Room overview (docs/executive-situation-room.md).
 * KPIs and the trend arrive with the page; comparison, attention and the
 * breakdowns stream in as deferred props so the first paint is quick.
 */
export default function SituationRoomIndex({ scope, filters, filterOptions, headline, comparison, breakdown, supporting, exports, rules, can }: Props) {
    const { t } = useLocale();
    const name = useNamer();
    const { date, dateTime } = useDateLabel();
    const [refreshing, setRefreshing] = useState(false);
    const query = viewQuery(scope, filters);
    const title = scope.node ? name(scope.node) : t('situationRoom.cityWide');

    function refresh() {
        router.post(route('situation-room.refresh'), query, { preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) });
    }

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={t('situationRoom.communityVoiceTitle')}
                description={t('situationRoom.subtitle')}
                backHref={route('situation-room.index')}
                actions={(
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" onClick={refresh} loading={refreshing}>{t('situationRoom.refresh')}</Button>
                        {can.wall && <Link href={route('situation-room.wall', scope.node ? { organization_id: scope.node.id } : {})} className={buttonClassName({ variant: 'outline' })}>{t('situationRoom.wallMode')}</Link>}
                        <Link href={route('situation-room.actions.index', scope.node ? { organization: scope.node.id } : {})} className={buttonClassName({ variant: 'outline' })}>{t('situationRoom.actionRegister')}</Link>
                    </div>
                )}
            />
        )}>
            <Head title={`${t('situationRoom.communityVoiceTitle')} — ${title}`} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <Breadcrumb scope={scope} filters={filters} />
                        <h2 className="mt-1 text-xl font-semibold">{title}</h2>
                        <p className="text-sm text-[color:var(--app-muted-foreground)]">
                            {fmt(t('situationRoom.periodRange'), { from: date(filters.from), to: date(filters.to) })}
                            {' · '}
                            {fmt(t('situationRoom.previousRange'), { from: date(filters.previous_from), to: date(filters.previous_to) })}
                        </p>
                    </div>
                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('situationRoom.lastUpdated')}: <time dateTime={headline.generated_at}>{dateTime(headline.generated_at)}</time></p>
                </div>

                <FilterBar key={JSON.stringify(query)} scope={scope} filters={filters} options={filterOptions} />

                <KpiRow kpis={headline.kpis} />

                <div className="grid gap-5 xl:grid-cols-3">
                    <TrendSection trend={headline.trend} kpis={headline.kpis} />
                    <Deferred data="comparison" fallback={<SectionSkeleton rows={4} />}>
                        {comparison ? <AttentionSection items={comparison.attention} rules={rules} scope={scope} filters={filters} canManageActions={can.manageActions} /> : <SectionSkeleton />}
                    </Deferred>
                    <Deferred data="comparison" fallback={<SectionSkeleton rows={4} />}>
                        {comparison ? <SummarySection sentences={comparison.summary} /> : <SectionSkeleton />}
                    </Deferred>
                </div>

                <Deferred data="comparison" fallback={<SectionSkeleton rows={8} />}>
                    {comparison ? <InstitutionSection institutions={comparison.institutions} scope={scope} filters={filters} /> : <SectionSkeleton />}
                </Deferred>

                <Deferred data={['breakdown', 'supporting']} fallback={<div className="grid gap-5 lg:grid-cols-2"><SectionSkeleton rows={5} /><SectionSkeleton rows={5} /></div>}>
                    {breakdown ? (
                        <div className="grid gap-5 lg:grid-cols-2">
                            <CategorySection categories={breakdown.categories} />
                            <ResolutionSection resolution={breakdown.resolution} targets={breakdown.targets} />
                            <ServiceSection services={breakdown.services} />
                            <RatingsSection ratings={breakdown.ratings} />
                        </div>
                    ) : <SectionSkeleton />}
                </Deferred>

                {can.employeeTargeted && (
                    <Card className="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div className="min-w-0">
                            <h2 className="font-semibold">{t('situationRoom.employeesTitle')}</h2>
                            <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('situationRoom.employeesIntro')}</p>
                        </div>
                        <Link href={route('situation-room.employees', query)} className={buttonClassName({ variant: 'outline' })}>{t('situationRoom.employeesOpen')}</Link>
                    </Card>
                )}

                <Deferred data={['breakdown', 'supporting']} fallback={<SectionSkeleton rows={2} />}>
                    {supporting ? <SupportingSection cards={supporting.cards} configuration={supporting.configuration} /> : <SectionSkeleton />}
                </Deferred>

                {can.export && <ExportsSection exports={exports} scope={scope} filters={filters} />}

                <Alert tone="info">
                    <p>{t('situationRoom.neutralNote')}</p>
                    <p className="mt-1">{t('situationRoom.countsOnlyNote')}</p>
                </Alert>
            </div>
        </AuthenticatedLayout>
    );
}
