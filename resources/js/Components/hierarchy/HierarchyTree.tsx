import { useMemo } from 'react';
import HierarchyTreeNode from '@/Components/hierarchy/HierarchyTreeNode';
import { useLocale } from '@/hooks/useLocale';
import type { HierarchyTreeNodeData } from '@/Components/hierarchy/types';

function filterTree(nodes: HierarchyTreeNodeData[], query: string): HierarchyTreeNodeData[] {
    if (!Array.isArray(nodes)) return [];
    if (query.trim() === '') {
        return nodes;
    }

    const normalized = query.trim().toLowerCase();

    return nodes
        .map((node) => {
            const children = filterTree(Array.isArray(node.children) ? node.children : [], query);
            const matches = [
                node.code,
                node.label ?? node.name_en,
                node.name_am ?? '',
                node.organization_type?.name_en ?? '',
                node.organization_type?.name_am ?? '',
            ].some((value) => value.toLowerCase().includes(normalized));

            return matches || children.length > 0
                ? { ...node, children }
                : null;
        })
        .filter((node): node is HierarchyTreeNodeData => node !== null);
}

export default function HierarchyTree({
    nodes,
    query,
    expandedIds,
    onToggle,
    onEditRelation,
    onRemoveRelation,
    onAddChild,
}: {
    nodes: HierarchyTreeNodeData[];
    query: string;
    expandedIds: Set<string>;
    onToggle: (organizationId: string) => void;
    onEditRelation: (relationId: string) => void;
    onRemoveRelation: (relationId: string) => void;
    onAddChild: (parentOrganizationId: string) => void;
}) {
    const { t } = useLocale();
    const filteredNodes = useMemo(() => filterTree(Array.isArray(nodes) ? nodes : [], query), [nodes, query]);

    if (filteredNodes.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">
                {query.trim().length > 0
                    ? t('hierarchyVersions.noMatchingOrganizationsFound')
                    : t('hierarchyVersions.noRelationsFound')}
            </div>
        );
    }

    const forceExpanded = query.trim().length > 0;

    return (
        <div
            role="tree"
            aria-label={t('hierarchyVersions.hierarchyTree')}
            className="select-none overflow-x-auto"
        >
            <div className="min-w-0">
                {filteredNodes.map((node, index) => {
                    const nodeKey = node.id ?? node.organization_id;
                    return (
                        <HierarchyTreeNode
                            key={`${nodeKey}-${node.edge_id ?? 'root'}`}
                            node={node}
                            expandedIds={expandedIds}
                            forceExpanded={forceExpanded}
                            onToggle={onToggle}
                            onEditRelation={onEditRelation}
                            onRemoveRelation={onRemoveRelation}
                            onAddChild={onAddChild}
                            position={String(index + 1)}
                            isLast={index === filteredNodes.length - 1}
                        />
                    );
                })}
            </div>
        </div>
    );
}
