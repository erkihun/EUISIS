import { ChevronRight, ChevronDown, Building2 } from '@/Components/Icons';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { OrganizationTreeNode } from '@/types/organizationUnit';

import { cx } from '@/Components/ui';
interface Props {
    node: OrganizationTreeNode;
    expandedIds: Set<string>;
    selectedId: string | null;
    onToggle: (id: string) => void;
    onSelect: (node: OrganizationTreeNode) => void;
}

/**
 * One organization in the structure panel, drawn like the organization rows
 * of the positions page: chevron, logo or building icon, name, code and
 * status, with the unit and child counts underneath.
 */
export default function OrganizationTreePreviewNode({ node, expandedIds, selectedId, onToggle, onSelect }: Props) {
    const { locale, t } = useLocale();
    const hasChildren = node.children && node.children.length > 0;
    const isExpanded = expandedIds.has(node.id);
    const isSelected = selectedId === node.id;

    /** Localized status label, falling back to StatusBadge's own default when a key is missing. */
    const statusLabel = (status: string): string | undefined => {
        const key = `common.${status}`;
        const translated = t(key);
        return translated === key ? undefined : translated;
    };

    return (
        <div role="treeitem" aria-expanded={hasChildren ? isExpanded : undefined} aria-selected={isSelected}>
            <div
                className={cx(
                    'flex items-center gap-2 rounded-lg py-1.5 pr-2 text-sm transition-colors',
                    isSelected ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200' : 'text-(--app-foreground) hover:bg-(--app-surface-muted)',
                )}
                style={{ paddingLeft: `${8 + node.depth * 16}px` }}
            >
                <button
                    type="button"
                    className="flex h-5 w-5 shrink-0 items-center justify-center text-(--app-muted-foreground)"
                    onClick={() => { if (hasChildren) onToggle(node.id); }}
                    aria-label={isExpanded ? t('organizationUnits.collapseOrganization') : t('organizationUnits.expandOrganization')}
                    tabIndex={hasChildren ? 0 : -1}
                >
                    {hasChildren ? (isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />) : <span className="h-3.5 w-3.5" />}
                </button>

                {node.has_logo && node.logo_url ? (
                    <img src={node.logo_url} alt="" width={20} height={20} loading="lazy" decoding="async" className="h-5 w-5 shrink-0 rounded-full object-cover" />
                ) : (
                    <Building2 className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
                )}

                <button type="button" onClick={() => onSelect(node)} className="min-w-0 flex-1 text-left">
                    <span className="block truncate font-medium">{localizedName(node.name_en, node.name_am, locale)}</span>
                    <span className="block truncate text-[11px] text-(--app-muted-foreground)">
                        {[
                            node.type ? localizedName(node.type.name_en, node.type.name_am, locale) : null,
                            `${node.organization_units_count ?? 0} ${t('organizationUnits.unitCount').toLowerCase()}`,
                            hasChildren ? `${node.children.length} ${t('organizationUnits.childOrganizations').toLowerCase()}` : null,
                        ].filter(Boolean).join(' · ')}
                    </span>
                </button>
                <span className="font-mono text-[10px] text-(--app-muted-foreground)">{node.code}</span>
                <StatusBadge status={node.status} label={statusLabel(node.status)} className="px-1.5 py-0 text-[10px]" />
            </div>

            {hasChildren && isExpanded && (
                <div role="group">
                    {node.children.map((child) => (
                        <OrganizationTreePreviewNode key={child.id} node={child} expandedIds={expandedIds} selectedId={selectedId} onToggle={onToggle} onSelect={onSelect} />
                    ))}
                </div>
            )}
        </div>
    );
}
