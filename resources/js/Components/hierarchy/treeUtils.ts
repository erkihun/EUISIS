import type { HierarchyTreeNodeData } from '@/Components/hierarchy/types';

export function collectExpandableIds(nodes: HierarchyTreeNodeData[]): string[] {
    if (!Array.isArray(nodes)) {
        return [];
    }

    return nodes.flatMap((node) => {
        const children = Array.isArray(node.children) ? node.children : [];
        const nodeKey = node.id ?? node.organization_id;

        return [
            ...(children.length > 0 ? [nodeKey] : []),
            ...collectExpandableIds(children),
        ];
    });
}

export function collectExpandedIdsToDepth(
    nodes: HierarchyTreeNodeData[],
    maxExpandedDepth: number,
): string[] {
    if (!Array.isArray(nodes)) {
        return [];
    }

    return nodes.flatMap((node) => {
        const children = Array.isArray(node.children) ? node.children : [];
        const nodeDepth = typeof node.depth === 'number' ? node.depth : 0;
        const shouldExpandNode = children.length > 0 && nodeDepth <= maxExpandedDepth;
        const nodeKey = node.id ?? node.organization_id;

        return [
            ...(shouldExpandNode ? [nodeKey] : []),
            ...collectExpandedIdsToDepth(children, maxExpandedDepth),
        ];
    });
}
