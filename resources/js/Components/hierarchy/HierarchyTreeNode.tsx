import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, EyeIcon, PencilIcon, Plus, TrashIcon } from '@/Components/Icons';
import StatusBadge from '@/Components/StatusBadge';
import RelationshipTypeBadge from '@/Components/hierarchy/RelationshipTypeBadge';
import { useLocale } from '@/hooks/useLocale';
import type { HierarchyTreeNodeData, HierarchyTreeNodeType } from '@/Components/hierarchy/types';

const INDENT_PX = 28;
const GUIDE_X_OFFSET = 10;   // px from indentLeft where the vertical guide sits
const ROW_GUTTER_PX = 28;    // gap between guide line and card left edge

function nodeName(node: HierarchyTreeNodeData, locale: 'en' | 'am'): string {
    if (locale === 'am' && node.name_am) {
        return node.name_am;
    }

    return node.label ?? node.name_en;
}

function nodeTypeName(node: HierarchyTreeNodeData, locale: 'en' | 'am'): string | null {
    // Prefer the unified node_type_label fields
    if (locale === 'am' && node.node_type_label_am) {
        return node.node_type_label_am;
    }
    if (node.node_type_label) {
        return node.node_type_label;
    }
    // Fallback to legacy organization_type
    const orgType = node.organization_type;
    if (!orgType) return null;
    if (locale === 'am' && orgType.name_am) return orgType.name_am;
    return orgType.name_en;
}

function NodeAvatar({ code, logoUrl, nodeType }: { code: string; logoUrl: string | null; nodeType: HierarchyTreeNodeType }) {
    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt=""
                className="h-8 w-8 shrink-0 rounded-xl object-cover ring-1 ring-black/5 dark:ring-white/10"
            />
        );
    }

    if (nodeType === 'organization_unit') {
        return (
            <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-100 dark:ring-emerald-900/40">
                {code ? code.slice(0, 2).toUpperCase() : 'U'}
            </span>
        );
    }

    return (
        <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-[11px] font-bold text-blue-700 ring-1 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-100 dark:ring-blue-900/40">
            {code ? code.slice(0, 2).toUpperCase() : 'O'}
        </span>
    );
}

export default function HierarchyTreeNode({
    node,
    expandedIds,
    forceExpanded,
    onToggle,
    onEditRelation,
    onRemoveRelation,
    onAddChild,
    position,
    isLast,
}: {
    node: HierarchyTreeNodeData;
    expandedIds: Set<string>;
    forceExpanded: boolean;
    onToggle: (organizationId: string) => void;
    onEditRelation: (relationId: string) => void;
    onRemoveRelation: (relationId: string) => void;
    onAddChild: (parentOrganizationId: string) => void;
    position: string;
    isLast: boolean;
}) {
    const { locale, t } = useLocale();
    const children = Array.isArray(node.children) ? node.children : [];
    const hasChildren = children.length > 0;
    const nodeKey = node.id ?? node.organization_id;
    const isExpanded = forceExpanded || expandedIds.has(nodeKey);
    const depth = typeof node.depth === 'number' ? node.depth : 0;
    const indentLeft = depth * INDENT_PX;
    // The vertical guide for this node's incoming connector
    const guideLeft = indentLeft + GUIDE_X_OFFSET;
    // Card is pushed right so the guide + horizontal arm visually reach it
    const rowMarginLeft = depth > 0 ? guideLeft + ROW_GUTTER_PX : 0;
    const typeName = nodeTypeName(node, locale);
    const nodeType: HierarchyTreeNodeType = node.type ?? 'organization';
    const isOrgUnit = nodeType === 'organization_unit';
    const displayName = nodeName(node, locale);

    return (
        <div className="relative">
            {depth > 0 && (
                <>
                    {/* Vertical bar — runs from top to toggle-centre for last child, full height for others */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-0 z-10 w-px bg-gray-300 dark:bg-slate-600"
                        style={{
                            left: `${guideLeft}px`,
                            ...(isLast ? { height: '1.75rem' } : { bottom: 0 }),
                        }}
                    />
                    {/* Horizontal arm — at toggle-centre height, reaching the card left edge */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute z-10 h-px bg-gray-300 dark:bg-slate-600"
                        style={{
                            left: `${guideLeft}px`,
                            top: '1.75rem',
                            width: `${ROW_GUTTER_PX}px`,
                        }}
                    />
                </>
            )}

            <div
                role="treeitem"
                aria-expanded={hasChildren ? isExpanded : undefined}
                className="group relative rounded-2xl"
            >
                <div
                    className="relative z-20 flex min-h-[4.25rem] flex-col gap-3 rounded-2xl border border-transparent bg-white px-3 py-3 transition hover:border-gray-200 hover:bg-gray-50/80 dark:bg-slate-900 dark:hover:border-slate-700 dark:hover:bg-slate-800/60 lg:flex-row lg:items-center"
                    style={{ marginLeft: `${rowMarginLeft}px` }}
                >
                    <div className="flex min-w-0 flex-1 items-start gap-3">
                        <span className="mt-1 flex h-6 w-6 shrink-0 items-center justify-center">
                            {hasChildren ? (
                                <button
                                    type="button"
                                    onClick={() => onToggle(nodeKey)}
                                    aria-label={isExpanded ? t('hierarchyVersions.collapseNode') : t('hierarchyVersions.expandNode')}
                                    title={isExpanded ? t('hierarchyVersions.hideChildren') : t('hierarchyVersions.showChildren')}
                                    className="flex h-6 w-6 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-blue-900/40 dark:hover:bg-blue-950/30 dark:hover:text-blue-200"
                                >
                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </button>
                            ) : (
                                <span className="block h-2 w-2 rounded-full bg-gray-300 dark:bg-slate-600" />
                            )}
                        </span>

                        <NodeAvatar code={node.code} logoUrl={node.logo_url} nodeType={nodeType} />

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={`rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold ${isOrgUnit ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-200' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-200'}`}>
                                    {position}
                                </span>
                                <p className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">
                                    {displayName}
                                </p>
                                {node.code && (
                                    <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        {node.code}
                                    </span>
                                )}
                            </div>

                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                {isOrgUnit ? (
                                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">
                                        {t('hierarchyVersions.organizationUnitNode')}
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-200">
                                        {t('hierarchyVersions.organizationNode')}
                                    </span>
                                )}
                                {typeName && (
                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                                        {typeName}
                                    </span>
                                )}
                                <StatusBadge status={node.status} label={node.status_label ?? undefined} />
                                {node.relationship_type && <RelationshipTypeBadge type={node.relationship_type} />}
                                <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
                                    {t('hierarchyVersions.hierarchyLevel')}: {depth}
                                </span>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    {t('hierarchyVersions.childCount')}: {node.child_count}
                                </span>
                                {node.meta?.position_count !== undefined && node.meta.position_count > 0 && (
                                    <span className="rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-950/30 dark:text-violet-200">
                                        {t('hierarchyVersions.positionCount')}: {node.meta.position_count}
                                    </span>
                                )}
                                {node.meta?.employee_count !== undefined && node.meta.employee_count > 0 && (
                                    <span className="rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-medium text-teal-700 dark:bg-teal-950/30 dark:text-teal-200">
                                        {t('hierarchyVersions.employeeCount')}: {node.meta.employee_count}
                                    </span>
                                )}
                                {(node.effective_from || node.effective_to) && (
                                    <span className="text-[11px] text-gray-500 dark:text-slate-400">
                                        {node.effective_from ?? '-'}
                                        {node.effective_from && node.effective_to ? ' - ' : ''}
                                        {node.effective_to ?? ''}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 opacity-100 transition md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
                        {isOrgUnit ? (
                            <Link
                                href={route('organization-units.show', { organizationUnit: node.organization_id })}
                                aria-label={t('common.details')}
                                title={t('common.details')}
                                className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-emerald-300 px-2.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                            >
                                <EyeIcon className="h-3.5 w-3.5" />
                                <span>{t('common.details')}</span>
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={route('organizations.show', { organization: node.organization_id })}
                                    aria-label={t('common.details')}
                                    title={t('common.details')}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-gray-300 px-2.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    <EyeIcon className="h-3.5 w-3.5" />
                                    <span>{t('common.details')}</span>
                                </Link>

                                {node.can.addChild && (
                                    <button
                                        type="button"
                                        onClick={() => onAddChild(node.organization_id)}
                                        aria-label={t('hierarchyVersions.addRelation')}
                                        title={t('hierarchyVersions.addRelation')}
                                        className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-blue-200 px-2.5 text-xs font-medium text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-blue-900/50 dark:text-blue-300 dark:hover:bg-blue-950/30"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        <span>{t('hierarchyVersions.addRelation')}</span>
                                    </button>
                                )}

                                {node.edge_id && node.can.edit && (
                                    <button
                                        type="button"
                                        onClick={() => onEditRelation(node.edge_id!)}
                                        aria-label={t('hierarchyVersions.editRelation')}
                                        title={t('hierarchyVersions.editRelation')}
                                        className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-gray-300 px-2.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                    >
                                        <PencilIcon className="h-3.5 w-3.5" />
                                        <span>{t('hierarchyVersions.editRelation')}</span>
                                    </button>
                                )}

                                {node.edge_id && node.can.remove && (
                                    <button
                                        type="button"
                                        onClick={() => onRemoveRelation(node.edge_id!)}
                                        aria-label={t('hierarchyVersions.removeRelation')}
                                        title={t('hierarchyVersions.removeRelation')}
                                        className="inline-flex h-8 items-center gap-1.5 rounded-xl border border-red-200 px-2.5 text-xs font-medium text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500/20 dark:border-red-900/50 dark:text-red-300 dark:hover:bg-red-950/30"
                                    >
                                        <TrashIcon className="h-3.5 w-3.5" />
                                        <span>{t('hierarchyVersions.removeRelation')}</span>
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>

            {hasChildren && isExpanded && (
                <div role="group" className="relative">
                    {children.map((child, index) => {
                        const childKey = child.id ?? child.organization_id;
                        return (
                            <HierarchyTreeNode
                                key={`${childKey}-${child.edge_id ?? child.type ?? 'node'}`}
                                node={child}
                                expandedIds={expandedIds}
                                forceExpanded={forceExpanded}
                                onToggle={onToggle}
                                onEditRelation={onEditRelation}
                                onRemoveRelation={onRemoveRelation}
                                onAddChild={onAddChild}
                                position={`${position}.${index + 1}`}
                                isLast={index === children.length - 1}
                            />
                        );
                    })}
                </div>
            )}
        </div>
    );
}
