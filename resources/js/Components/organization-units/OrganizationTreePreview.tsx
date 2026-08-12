import { useState, useMemo, useEffect } from 'react';
import { SearchIcon, Building2 } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import OrganizationTreePreviewNode from '@/Components/organization-units/OrganizationTreePreviewNode';
import type { OrganizationTreeNode } from '@/types/organizationUnit';

interface SelectedVersion {
    id: string;
    name: string;
    status: string;
    is_draft: boolean;
    effective_from: string | null;
}

interface AvailableVersion {
    id: string;
    name: string;
    status: string;
    is_draft: boolean;
    effective_from: string | null;
}

interface Props {
    tree: OrganizationTreeNode[];
    selectedId: string | null;
    hasPublishedHierarchy: boolean;
    usingDraftFallback?: boolean;
    usingFlatFallback?: boolean;
    selectedVersion?: SelectedVersion | null;
    availableVersions?: AvailableVersion[];
    onSelect: (node: OrganizationTreeNode) => void;
    onVersionChange?: (versionId: string) => void;
}

/** Returns the IDs of all nodes on the path from a root to the target (inclusive). */
function findPathToNode(nodes: OrganizationTreeNode[], targetId: string): string[] | null {
    for (const node of nodes) {
        if (node.id === targetId) return [node.id];
        const childPath = findPathToNode(node.children, targetId);
        if (childPath !== null) return [node.id, ...childPath];
    }
    return null;
}

function collectAllIds(nodes: OrganizationTreeNode[]): string[] {
    const ids: string[] = [];
    for (const node of nodes) {
        ids.push(node.id);
        if (node.children.length > 0) {
            ids.push(...collectAllIds(node.children));
        }
    }
    return ids;
}

function matchesSearch(node: OrganizationTreeNode, q: string): boolean {
    return (
        node.name_en.toLowerCase().includes(q) ||
        (node.name_am ?? '').includes(q) ||
        node.code.toLowerCase().includes(q)
    );
}

function filterTree(nodes: OrganizationTreeNode[], q: string): OrganizationTreeNode[] {
    if (!q) return nodes;
    return nodes
        .map((node) => {
            const filteredChildren = filterTree(node.children, q);
            if (matchesSearch(node, q) || filteredChildren.length > 0) {
                return { ...node, children: filteredChildren };
            }
            return null;
        })
        .filter((n): n is OrganizationTreeNode => n !== null);
}

function collectMatchIds(nodes: OrganizationTreeNode[]): string[] {
    const ids: string[] = [];
    for (const node of nodes) {
        ids.push(node.id);
        if (node.children.length > 0) {
            ids.push(...collectMatchIds(node.children));
        }
    }
    return ids;
}

export default function OrganizationTreePreview({
    tree,
    selectedId,
    hasPublishedHierarchy,
    usingDraftFallback = false,
    usingFlatFallback = false,
    selectedVersion = null,
    availableVersions = [],
    onSelect,
    onVersionChange,
}: Props) {
    const { t } = useLocale();
    const [search, setSearch] = useState('');
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => {
        const initial = new Set(tree.map((n) => n.id));
        if (selectedId) {
            const path = findPathToNode(tree, selectedId);
            if (path) path.forEach((id) => initial.add(id));
        }
        return initial;
    });

    // When selectedId changes (e.g. after navigating back), expand its ancestors
    useEffect(() => {
        if (!selectedId) return;
        const path = findPathToNode(tree, selectedId);
        if (!path) return;
        setExpandedIds((prev) => {
            const next = new Set(prev);
            path.forEach((id) => next.add(id));
            return next;
        });
    }, [selectedId, tree]);

    const allIds = useMemo(() => collectAllIds(tree), [tree]);

    const filteredTree = useMemo(() => {
        const q = search.trim().toLowerCase();
        return filterTree(tree, q);
    }, [tree, search]);

    // Auto-expand ancestors when searching
    const effectiveExpandedIds = useMemo(() => {
        if (!search.trim()) return expandedIds;
        // When searching, expand all nodes that contain matches
        return new Set(collectMatchIds(filteredTree));
    }, [search, filteredTree, expandedIds]);

    function toggleNode(id: string) {
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function expandAll() {
        setExpandedIds(new Set(allIds));
    }

    function collapseAll() {
        setExpandedIds(new Set());
    }

    return (
        <div className="flex h-full flex-col rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationUnits.organizationTreePreview')}
                </h2>
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={expandAll}
                        className="rounded px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    >
                        {t('organizationUnits.expandAll')}
                    </button>
                    <button
                        type="button"
                        onClick={collapseAll}
                        className="rounded px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    >
                        {t('organizationUnits.collapseAll')}
                    </button>
                </div>
            </div>

            {/* Draft hierarchy warning */}
            {usingDraftFallback && selectedVersion && (
                <div className="mx-3 mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                    <span className="font-medium">{selectedVersion.name}</span>
                    {' — '}
                    {t('organizationUnits.showingDraftHierarchy')}
                </div>
            )}

            {/* Flat fallback warning — only when no version exists at all */}
            {usingFlatFallback && (
                <div className="mx-3 mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                    {t('organizationUnits.noHierarchyVersionFoundFlatList')}
                </div>
            )}

            {/* Version selector */}
            {availableVersions.length > 1 && onVersionChange && (
                <div className="px-3 pt-2">
                    <select
                        className="w-full rounded-lg border border-gray-300 bg-white py-1.5 px-2 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        value={selectedVersion?.id ?? ''}
                        onChange={(e) => onVersionChange(e.target.value)}
                        aria-label={t('hierarchyVersions.selectedHierarchyVersion')}
                    >
                        {availableVersions.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.name}
                                {v.is_draft ? ` (${t('hierarchyVersions.draft')})` : ` (${t('hierarchyVersions.published')})`}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {/* Search */}
            <div className="px-3 py-2">
                <div className="relative">
                    <SearchIcon className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400 dark:text-slate-500" />
                    <input
                        type="text"
                        className="w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-8 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                        placeholder={t('organizationUnits.searchOrganizations')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>

            {/* Tree */}
            <div className="flex-1 overflow-y-auto px-2 pb-3" role="tree">
                {filteredTree.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Building2 className="h-8 w-8 text-gray-300 dark:text-slate-600" />
                        <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">
                            {t('organizationUnits.noOrganizationsFound')}
                        </p>
                    </div>
                ) : (
                    filteredTree.map((node, index) => (
                        <OrganizationTreePreviewNode
                            key={node.id}
                            node={node}
                            hierarchyNumber={`${index + 1}`}
                            expandedIds={effectiveExpandedIds}
                            selectedId={selectedId}
                            onToggle={toggleNode}
                            onSelect={onSelect}
                        />
                    ))
                )}
            </div>
        </div>
    );
}
