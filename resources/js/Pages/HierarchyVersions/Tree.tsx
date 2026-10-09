import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HierarchyRelationModal from '@/Components/hierarchy/HierarchyRelationModal';
import HierarchyTree from '@/Components/hierarchy/HierarchyTree';
import HierarchyVersionSummary from '@/Components/hierarchy/HierarchyVersionSummary';
import RemoveRelationDialog from '@/Components/hierarchy/RemoveRelationDialog';
import TreeToolbar from '@/Components/hierarchy/TreeToolbar';
import { collectExpandableIds, collectExpandedIdsToDepth } from '@/Components/hierarchy/treeUtils';
import type { HierarchyEdge, HierarchyTreeNodeData, HierarchyVersionPage, OrganizationOption } from '@/Components/hierarchy/types';
import { useLocale } from '@/hooks/useLocale';
import { useTreeExpandState } from '@/hooks/useTreeExpandState';

type Props = {
    version: HierarchyVersionPage;
    tree: HierarchyTreeNodeData[];
    edges: HierarchyEdge[];
    summary: {
        total_organizations: number;
        total_relations: number;
        root_nodes: number;
        max_depth: number;
    };
    can: {
        manageTree: boolean;
        createEdge: boolean;
    };
};

function relationOptionsFromEdges(edges: HierarchyEdge[]): string[] {
    return Array.from(new Set(edges.map((edge) => edge.relationship_type))).filter(Boolean);
}

function optionFromEdgeOrganizations(edges: HierarchyEdge[]): OrganizationOption[] {
    const map = new Map<string, OrganizationOption>();

    for (const edge of edges) {
        for (const organization of [edge.parent_organization, edge.child_organization]) {
            if (!organization || map.has(organization.id)) {
                continue;
            }

            map.set(organization.id, {
                id: organization.id,
                code: organization.code,
                name_en: organization.name_en,
                name_am: organization.name_am,
                status: organization.status,
                type: organization.type
                    ? {
                        code: organization.type.code,
                        name_en: organization.type.name_en,
                        name_am: organization.type.name_am,
                    }
                    : null,
            });
        }
    }

    return Array.from(map.values());
}

export default function HierarchyVersionTree({ version, tree, edges, summary, can }: Props) {
    const { t } = useLocale();
    const [query, setQuery] = useState('');
    const defaultExpanded = useMemo(
        () => new Set(collectExpandedIdsToDepth(tree, 0)),
        [tree],
    );
    const { expandedIds, toggleNode, expandAll, collapseAll } = useTreeExpandState(
        `view:${version.id}`,
        defaultExpanded,
    );
    const [relationModal, setRelationModal] = useState<{
        mode: 'create' | 'edit';
        relationId: string | null;
        parentId: string;
    } | null>(null);
    const [removeRelationId, setRemoveRelationId] = useState<string | null>(null);

    const relationMap = useMemo(() => new Map(edges.map((edge) => [edge.id, edge])), [edges]);
    const relationTypes = useMemo(() => relationOptionsFromEdges(edges), [edges]);
    const organizationOptions = useMemo(() => optionFromEdgeOrganizations(edges), [edges]);
    const selectedRelation = relationModal?.relationId ? relationMap.get(relationModal.relationId) ?? null : null;
    const relationToRemove = removeRelationId ? relationMap.get(removeRelationId) ?? null : null;
    const allExpandableIds = useMemo(() => new Set(collectExpandableIds(tree)), [tree]);

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('hierarchyVersions.treeView')}
                    description={version.version_name}
                    actions={(
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={route('hierarchy-versions.show', { hierarchyVersion: version.id })}
                                className="rounded-card border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('common.details')}
                            </Link>
                            {can.manageTree && (
                                <Link
                                    href={route('hierarchy-versions.tree.edit', { hierarchyVersion: version.id })}
                                    className="rounded-card bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    {t('hierarchyVersions.editTree')}
                                </Link>
                            )}
                        </div>
                    )}
                />
            )}
        >
            <Head title={t('hierarchyVersions.treeView')} />

            <div className="space-y-6">
                <HierarchyVersionSummary version={version} summary={summary} editable={can.manageTree} />

                <TreeToolbar
                    query={query}
                    onQueryChange={setQuery}
                    onExpandAll={() => expandAll(new Set(allExpandableIds))}
                    onCollapseAll={collapseAll}
                    onAddRelation={() => setRelationModal({ mode: 'create', relationId: null, parentId: '' })}
                    canAddRelation={can.createEdge}
                />

                <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                                {t('hierarchyVersions.treePreview')}
                            </h3>
                            <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                {can.manageTree
                                    ? t('hierarchyVersions.editableDraftVersion')
                                    : t('hierarchyVersions.readOnlyVersion')}
                            </p>
                        </div>
                        <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                            {summary.total_organizations} {t('hierarchyVersions.totalOrganizations').toLowerCase()}
                        </span>
                    </div>

                    <HierarchyTree
                        nodes={tree}
                        query={query}
                        expandedIds={expandedIds}
                        onToggle={toggleNode}
                        onEditRelation={(relationId) => setRelationModal({ mode: 'edit', relationId, parentId: '' })}
                        onRemoveRelation={setRemoveRelationId}
                        onAddChild={(parentOrganizationId) => setRelationModal({ mode: 'create', relationId: null, parentId: parentOrganizationId })}
                    />
                </section>
            </div>

            <HierarchyRelationModal
                show={relationModal !== null}
                mode={relationModal?.mode ?? 'create'}
                hierarchyVersionId={version.id}
                relationshipTypes={relationTypes.length > 0 ? relationTypes : ['reports_to', 'geographically_under', 'service_scope', 'oversight']}
                organizationOptions={organizationOptions}
                relation={selectedRelation}
                initialParentId={relationModal?.parentId ?? ''}
                onClose={() => setRelationModal(null)}
            />

            <RemoveRelationDialog
                show={removeRelationId !== null}
                hierarchyVersionId={version.id}
                relation={relationToRemove}
                onClose={() => setRemoveRelationId(null)}
            />
        </AuthenticatedLayout>
    );
}
