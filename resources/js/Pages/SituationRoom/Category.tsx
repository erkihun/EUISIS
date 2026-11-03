import { Deferred, Head, Link } from '@inertiajs/react';
import { Alert, cx } from '@euisis/ui';
import PanelSkeleton from '@/Components/SituationRoom/PanelSkeleton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HeaderActions from '@/Components/SituationRoom/HeaderActions';
import { PanelGrid } from '@/Components/SituationRoom/Panels';
import { SECTION_ACCENT } from '@/Components/SituationRoom/sectionAccent';
import { Freshness, ScopeTrail, SituationFilterBar, type FilterOptions } from '@/Components/SituationRoom/SituationFilters';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { SrCategory, SrFrameProps, SrResult } from '@/types/situationRoom';

type Props = SrFrameProps & { category: SrCategory; options: FilterOptions; result?: SrResult; privacy: { small_cell: number } };

/**
 * One Situation Room category. The frame (filters, scope trail, category
 * tabs) paints at once; the figures are a deferred prop and arrive after.
 * Every panel stands alone, so one that failed or has nothing to show is a
 * card saying so. The tabs and panels carry the area's colour, the same one
 * the landing page and the wall use for it.
 */
export default function SituationRoomCategory({ categories, category, filters, query, scope, can, options, result, privacy }: Props) {
    const { sr } = useSituationLabels();
    const url = route('situation-room.category', category.key);
    const accent = SECTION_ACCENT[category.section] ?? SECTION_ACCENT.command;
    // Actions are recorded against one institution, so the link appears once the view is focused on one.
    const actionHref = can.actions_manage && scope.focus
        ? (panelKey: string) => route('situation-room.actions.index', { organization: scope.focus, new: 1, source_type: 'panel', source_reference: `${category.key}:${panelKey}` })
        : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={sr(`categories.${category.key}.title`)} description={sr(`categories.${category.key}.description`)} backHref={route('situation-room.index', query)} actions={<HeaderActions can={can} query={query} />} />}>
            <Head title={`${sr(`categories.${category.key}.title`)} · ${sr('title')}`} />

            <div className="space-y-5">
                <nav aria-label={sr('ui.categories')} className="-mx-1 overflow-x-auto pb-1">
                    <ul className="flex min-w-max gap-1.5 px-1">
                        {categories.map((c) => {
                            const current = c.key === category.key;
                            return (
                                <li key={c.key}>
                                    <Link
                                        href={c.href}
                                        data={query}
                                        aria-current={current ? 'page' : undefined}
                                        className={cx(
                                            'flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary',
                                            current ? 'border-primary bg-primary text-white' : 'border-(--app-border) bg-(--app-surface) text-(--app-foreground) hover:border-primary',
                                        )}
                                    >
                                        <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: current ? 'currentColor' : SECTION_ACCENT[c.section] }} />
                                        {sr(`categories.${c.key}.title`)}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {scope.empty && <Alert tone="warning">{sr('ui.empty_scope')}</Alert>}
                <ScopeTrail url={url} query={query} scope={scope} actions={result ? <Freshness generatedAt={result.generated_at} cachedFor={result.cached_for} /> : undefined} />
                <SituationFilterBar url={url} filters={filters} query={query} scope={scope} options={{ ...options, tip_types: category.key === 'tip_offs' }} />

                <Deferred data="result" fallback={<PanelSkeleton label={sr('ui.loading')} />}>
                    <PanelGrid panels={result?.payload.panels ?? []} options={{ smallCell: privacy.small_cell, actionHref, accent }} />
                </Deferred>
            </div>
        </AuthenticatedLayout>
    );
}
