import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HierarchyTree from '@/Components/hierarchy/HierarchyTree';
import HierarchyRelationModal from '@/Components/hierarchy/HierarchyRelationModal';
import HierarchyVersionSummary from '@/Components/hierarchy/HierarchyVersionSummary';
import RemoveRelationDialog from '@/Components/hierarchy/RemoveRelationDialog';
import TreeToolbar from '@/Components/hierarchy/TreeToolbar';
import { collectExpandableIds } from '@/Components/hierarchy/treeUtils';
import { useLocale } from '@/hooks/useLocale';
import { useTreeExpandState } from '@/hooks/useTreeExpandState';
import type { HierarchyEdge, HierarchyTreeNodeData, HierarchyVersionPage, OrganizationOption } from '@/Components/hierarchy/types';

type Props = {
    version: HierarchyVersionPage;
    tree: HierarchyTreeNodeData[];
    edges: HierarchyEdge[];
    organizationOptions: OrganizationOption[];
    relationshipTypes: string[];
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

export default function EditHierarchyVersionTree({
    version,
    tree,
    edges,
    organizationOptions,
    relationshipTypes,
    summary,
    can,
}: Props) {
    const { t } = useLocale();
    const [query, setQuery] = useState('');
    const defaultExpanded = useMemo(() => new Set(collectExpandableIds(tree ?? [])), [tree]);
    const { expandedIds, toggleNode, expandAll, collapseAll } = useTreeExpandState(`edit:${version.id}`, defaultExpanded);
    const [relationModal, setRelationModal] = useState<{
        mode: 'create' | 'edit';
        relationId: string | null;
        parentId: string;
    } | null>(null);
    const [removeRelationId, setRemoveRelationId] = useState<string | null>(null);

    const relationMap = useMemo(
        () => new Map(edges.map((edge) => [edge.id, edge])),
        [edges],
    );
    const allExpandableIds = useMemo(() => new Set(collectExpandableIds(tree)), [tree]);
    const selectedRelation = relationModal?.relationId ? relationMap.get(relationModal.relationId) ?? null : null;
    const relationToRemove = removeRelationId ? relationMap.get(removeRelationId) ?? null : null;

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('hierarchyVersions.editTree')}
                    description={version.version_name}
                    actions={(
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                href={route('hierarchy-versions.tree', { hierarchyVersion: version.id })}
                                className="rounded-card border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('hierarchyVersions.viewTree')}
                            </Link>
                            {can.createEdge && (
                                <button
                                    type="button"
                                    onClick={() => setRelationModal({ mode: 'create', relationId: null, parentId: '' })}
                                    className="rounded-card bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                                >
                                    {t('hierarchyVersions.addRelation')}
                                </button>
                            )}
                        </div>
                    )}
                />
            )}
        >
            <Head title={t('hierarchyVersions.editTree')} />

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

                <section className="space-y-4">
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                                    {t('hierarchyVersions.treeEditor')}
                                </h3>
                                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                    {t('hierarchyVersions.editableDraftVersion')}
                                </p>
                            </div>
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
                    </div>
                </section>
            </div>

            <HierarchyRelationModal
                show={relationModal !== null}
                mode={relationModal?.mode ?? 'create'}
                hierarchyVersionId={version.id}
                relationshipTypes={relationshipTypes}
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
