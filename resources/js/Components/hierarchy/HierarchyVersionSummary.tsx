import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import type { HierarchyVersionPage } from '@/Components/hierarchy/types';

type Summary = {
    total_organizations: number;
    total_relations: number;
    root_nodes: number;
    max_depth: number;
};

export default function HierarchyVersionSummary({
    version,
    summary,
    editable,
}: {
    version: HierarchyVersionPage;
    summary: Summary;
    editable: boolean;
}) {
    const { t } = useLocale();

    const cards = [
        { label: t('hierarchyVersions.totalOrganizations'), value: summary.total_organizations },
        { label: t('hierarchyVersions.totalRelations'), value: summary.total_relations },
        { label: t('hierarchyVersions.rootOrganizations'), value: summary.root_nodes },
        { label: t('hierarchyVersions.maximumDepth'), value: summary.max_depth },
    ];

    return (
        <div className="space-y-4">
            <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold text-(--app-muted-foreground)">
                            {t('hierarchyVersions.hierarchyTree')}
                        </p>
                        <h2 className="mt-2 text-2xl font-semibold text-(--app-foreground)">
                            {version.version_name}
                        </h2>
                        <p className="mt-2 text-sm text-(--app-muted-foreground)">
                            {version.effective_from ?? '-'} {t('common.to')} {version.effective_to ?? '-'}
                        </p>
                    </div>
                    <StatusBadge status={version.status} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {cards.map((card) => (
                    <div key={card.label} className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                        <p className="text-xs font-medium text-(--app-muted-foreground)">{card.label}</p>
                        <p className="mt-2 text-2xl font-semibold text-(--app-foreground)">{card.value}</p>
                    </div>
                ))}
            </div>

            <div className={`rounded-panel border px-4 py-3 text-sm ${
                editable
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-200'
                    : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200'
            }`}>
                {editable
                    ? t('hierarchyVersions.editableDraftVersion')
                    : t('hierarchyVersions.readOnlyVersion')}
            </div>
        </div>
    );
}
