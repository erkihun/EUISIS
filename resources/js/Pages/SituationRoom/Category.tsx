import { Deferred, Head, Link } from '@inertiajs/react';
import { Alert } from '@euisis/ui';
import PanelSkeleton from '@/Components/SituationRoom/PanelSkeleton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HeaderActions from '@/Components/SituationRoom/HeaderActions';
import { PanelGrid } from '@/Components/SituationRoom/Panels';
import { Freshness, ScopeTrail, SituationFilterBar, type FilterOptions } from '@/Components/SituationRoom/SituationFilters';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { SrCategory, SrFrameProps, SrResult } from '@/types/situationRoom';

type Props = SrFrameProps & { category: SrCategory; options: FilterOptions; result?: SrResult; privacy: { small_cell: number } };

/**
 * One Situation Room category. The frame (filters, scope trail, category
 * tabs) paints at once; the figures are a deferred prop and arrive after.
 * Every panel stands alone, so one that failed or has nothing to show is a
 * card saying so.
 */
export default function SituationRoomCategory({ categories, category, filters, query, scope, can, options, result, privacy }: Props) {
    const { sr } = useSituationLabels();
    const url = route('situation-room.category', category.key);
    const actionHref = can.actions_manage
        ? (panelKey: string) => route('situation-room.actions.create', { category: category.key, panel_key: panelKey, ...(scope.focus ? { organization_id: scope.focus } : {}) })
        : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={sr(`categories.${category.key}.title`)} description={sr(`categories.${category.key}.description`)} backHref={route('situation-room.index', query)} actions={<HeaderActions can={can} query={query} />} />}>
            <Head title={`${sr(`categories.${category.key}.title`)} · ${sr('title')}`} />

            <div className="space-y-5">
                <nav aria-label={sr('ui.categories')} className="-mx-1 overflow-x-auto pb-1">
                    <ul className="flex min-w-max gap-1 px-1">
                        {categories.map((c) => (
                            <li key={c.key}>
                                <Link
                                    href={c.href}
                                    data={query}
                                    aria-current={c.key === category.key ? 'page' : undefined}
                                    className={c.key === category.key
                                        ? 'block rounded-control bg-[color:var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white'
                                        : 'block rounded-control border border-[color:var(--app-border)] px-3 py-1.5 text-xs text-[color:var(--app-foreground)] hover:border-[color:var(--color-primary)]'}
                                >
                                    {sr(`categories.${c.key}.title`)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>

                {scope.empty && <Alert tone="warning">{sr('ui.empty_scope')}</Alert>}
                <ScopeTrail url={url} query={query} scope={scope} />
                <SituationFilterBar url={url} filters={filters} query={query} scope={scope} options={{ ...options, tip_types: category.key === 'tip_offs' }} />

                <Deferred data="result" fallback={<PanelSkeleton label={sr('ui.loading')} />}>
                    <div className="space-y-3">
                        {result && <Freshness generatedAt={result.generated_at} cachedFor={result.cached_for} />}
                        <PanelGrid panels={result?.payload.panels ?? []} options={{ smallCell: privacy.small_cell, actionHref }} />
                    </div>
                </Deferred>
            </div>
        </AuthenticatedLayout>
    );
}
