import { useState, useMemo, useEffect } from 'react';
import { SearchIcon, Building2 } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import OrganizationTreePreviewNode from '@/Components/organization-units/OrganizationTreePreviewNode';
import type { OrganizationTreeNode } from '@/types/organizationUnit';

import { Alert, FormField, Select, buttonClassName, cx, inputClassName } from '@/Components/ui';
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

/**
 * The organization structure panel of the units page, laid out like the one
 * on the positions page: a search box, then the hierarchy as rows to pick an
 * organization from. The version selector and the draft/flat notices sit
 * above the search.
 */
export default function OrganizationTreePreview({
    tree,
    selectedId,
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

    const filteredTree = useMemo(() => filterTree(tree, search.trim().toLowerCase()), [tree, search]);

    // While searching, every node that contains a match is open.
    const effectiveExpandedIds = useMemo(
        () => (search.trim() ? new Set(collectAllIds(filteredTree)) : expandedIds),
        [search, filteredTree, expandedIds],
    );

    function toggleNode(id: string) {
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }

    return (
        <div className="flex h-full flex-col rounded-panel border border-(--app-border) bg-(--app-surface)">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-3">
                <h2 className="text-sm font-semibold text-(--app-foreground)">{t('organizationUnits.organizationStructure')}</h2>
                <div className="flex items-center gap-1">
                    <button type="button" onClick={() => setExpandedIds(new Set(allIds))} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>
                        {t('organizationUnits.expandAll')}
                    </button>
                    <button type="button" onClick={() => setExpandedIds(new Set())} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>
                        {t('organizationUnits.collapseAll')}
                    </button>
                </div>
            </div>

            <div className="space-y-3 px-3 pt-3">
                {usingDraftFallback && selectedVersion && (
                    <Alert tone="warning"><span className="font-medium">{selectedVersion.name}</span> — {t('organizationUnits.showingDraftHierarchy')}</Alert>
                )}
                {usingFlatFallback && <Alert tone="warning">{t('organizationUnits.noHierarchyVersionFoundFlatList')}</Alert>}

                {availableVersions.length > 1 && onVersionChange && (
                    <FormField id="organization-units-version" label={t('hierarchyVersions.selectedHierarchyVersion')}>
                        {({ id }) => (
                            <Select id={id} value={selectedVersion?.id ?? ''} onChange={(e) => onVersionChange(e.target.value)}>
                                {availableVersions.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.name} ({v.is_draft ? t('hierarchyVersions.draft') : t('hierarchyVersions.published')})
                                    </option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                )}

                <div className="relative">
                    <SearchIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden="true" />
                    <input
                        type="search"
                        className={cx(inputClassName, 'w-full pl-9 pr-3')}
                        placeholder={t('organizationUnits.searchOrganizations')}
                        aria-label={t('organizationUnits.searchOrganizations')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>

            <div className="flex-1 overflow-y-auto px-2 py-3" role="tree" aria-label={t('organizationUnits.organizationStructure')}>
                {filteredTree.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-8 text-center">
                        <Building2 className="h-8 w-8 text-(--app-muted-foreground)" aria-hidden="true" />
                        <p className="mt-2 text-xs text-(--app-muted-foreground)">{t('organizationUnits.noOrganizationsFound')}</p>
                    </div>
                ) : (
                    filteredTree.map((node) => (
                        <OrganizationTreePreviewNode key={node.id} node={node} expandedIds={effectiveExpandedIds} selectedId={selectedId} onToggle={toggleNode} onSelect={onSelect} />
                    ))
                )}
            </div>
        </div>
    );
}
