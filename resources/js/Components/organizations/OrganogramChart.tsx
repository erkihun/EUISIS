import { useEffect, useMemo, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { OrganizationStructureTreeData } from '@/Components/organizations/OrganizationStructureTree';
import CompactOrganogram from './organogram/CompactOrganogram';
import HorizontalOrganogram from './organogram/HorizontalOrganogram';
import OrganogramDepthSelector from './organogram/OrganogramDepthSelector';
import OrganogramTypeSelector from './organogram/OrganogramTypeSelector';
import PositionFocusedOrganogram from './organogram/PositionFocusedOrganogram';
import UnitFocusedOrganogram from './organogram/UnitFocusedOrganogram';
import VerticalOrganogram from './organogram/VerticalOrganogram';
import {
    applyDepth,
    collectUnitIds,
    isOrganogramDepth,
    isOrganogramLayout,
    type OrganogramDepth,
    type OrganogramLayout,
} from './organogram/shared';

/**
 * Organogram host: owns the layout choice, expand/collapse state and zoom, then
 * delegates rendering to one of five layout components.
 *
 * All layouts consume the SAME server payload — there is no per-layout fetch
 * and no per-layout backend logic. Node content lives in `organogram/shared`
 * so every layout shows identical fields.
 */

/** Layout persists in the URL so a chosen view survives reload and sharing. */
function readLayoutFromUrl(): OrganogramLayout {
    if (typeof window === 'undefined') {
        return 'vertical';
    }

    const value = new URLSearchParams(window.location.search).get('layout');

    return isOrganogramLayout(value) ? value : 'vertical';
}

/** Depth also persists in the URL, alongside the layout. */
function readDepthFromUrl(): OrganogramDepth {
    if (typeof window === 'undefined') {
        return 'employee';
    }

    const value = new URLSearchParams(window.location.search).get('depth');

    return isOrganogramDepth(value) ? value : 'employee';
}

export default function OrganogramChart({
    tree,
    captureRef,
    toolbarExtra,
}: {
    tree: OrganizationStructureTreeData;
    /** Wraps the chart content only, so exports exclude the toolbar. */
    captureRef?: React.Ref<HTMLDivElement>;
    toolbarExtra?: React.ReactNode;
}) {
    const { t } = useLocale();
    const allUnitIds = useMemo(() => collectUnitIds(tree.units), [tree.units]);

    const [layout, setLayout] = useState<OrganogramLayout>(readLayoutFromUrl);
    const [depth, setDepth] = useState<OrganogramDepth>(readDepthFromUrl);
    const [zoom, setZoom] = useState(1);

    // Prune once, here — every layout below renders this tree, so screen,
    // print, PNG and PDF all reflect the selected depth automatically.
    const visibleTree = useMemo(() => applyDepth(tree, depth), [tree, depth]);

    // Compact is meant for large structures, so it starts fully collapsed;
    // the others open the first level.
    const [expandedIds, setExpandedIds] = useState<Set<string>>(
        () => (readLayoutFromUrl() === 'compact' ? new Set() : new Set(tree.units.map((unit) => unit.id))),
    );

    // Mirror the layout into the URL without adding a history entry.
    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);

        if (url.searchParams.get('layout') !== layout || url.searchParams.get('depth') !== depth) {
            url.searchParams.set('layout', layout);
            url.searchParams.set('depth', depth);
            window.history.replaceState({}, '', url);
        }
    }, [layout, depth]);

    function changeLayout(next: OrganogramLayout) {
        setLayout(next);
        setExpandedIds(next === 'compact' ? new Set() : new Set(tree.units.map((unit) => unit.id)));
    }

    function toggle(id: string) {
        setExpandedIds((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    const hasStructure = tree.units.length > 0 || tree.direct_positions.length > 0;
    // The focused layouts are flat lists — expand/collapse does not apply.
    const supportsExpansion = layout === 'vertical' || layout === 'horizontal' || layout === 'compact';

    const buttonCls =
        'rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800';

    function renderLayout() {
        switch (layout) {
            case 'horizontal':
                return <HorizontalOrganogram tree={visibleTree} expandedIds={expandedIds} onToggle={toggle} />;
            case 'compact':
                return <CompactOrganogram tree={visibleTree} expandedIds={expandedIds} onToggle={toggle} />;
            case 'position-focused':
                return <PositionFocusedOrganogram tree={visibleTree} />;
            case 'unit-focused':
                return <UnitFocusedOrganogram tree={visibleTree} />;
            case 'vertical':
            default:
                return <VerticalOrganogram tree={visibleTree} expandedIds={expandedIds} onToggle={toggle} />;
        }
    }

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <OrganogramTypeSelector value={layout} onChange={changeLayout} />
                <OrganogramDepthSelector value={depth} onChange={setDepth} />

                {supportsExpansion && (
                    <>
                        <span className="mx-1 h-4 w-px bg-gray-200 dark:bg-slate-700" />
                        <button type="button" onClick={() => setExpandedIds(new Set(allUnitIds))} className={buttonCls}>
                            {t('organizations.expandAll')}
                        </button>
                        <button type="button" onClick={() => setExpandedIds(new Set())} className={buttonCls}>
                            {t('organizations.collapseAll')}
                        </button>
                    </>
                )}

                <span className="mx-1 h-4 w-px bg-gray-200 dark:bg-slate-700" />
                <button
                    type="button"
                    onClick={() => setZoom((value) => Math.max(0.5, Number((value - 0.1).toFixed(2))))}
                    className={buttonCls}
                >
                    {t('organizations.zoomOut')}
                </button>
                <span className="text-xs tabular-nums text-gray-500 dark:text-slate-400">{Math.round(zoom * 100)}%</span>
                <button
                    type="button"
                    onClick={() => setZoom((value) => Math.min(1.5, Number((value + 0.1).toFixed(2))))}
                    className={buttonCls}
                >
                    {t('organizations.zoomIn')}
                </button>

                {toolbarExtra}
            </div>

            {!hasStructure ? (
                <p className="py-16 text-center text-sm text-gray-500 dark:text-slate-400">
                    {t('organizations.noStructureFound')}
                </p>
            ) : (
                <div className="overflow-x-auto pb-4">
                    {/* Capture target: whatever layout is selected is what gets
                        exported to PNG/PDF and printed. */}
                    <div
                        ref={captureRef}
                        data-organogram-layout={layout}
                        data-organogram-depth={depth}
                        className="inline-block min-w-full origin-top-left bg-white p-4 dark:bg-slate-900"
                        style={{ transform: `scale(${zoom})`, transformOrigin: 'top left' }}
                    >
                        {renderLayout()}
                    </div>
                </div>
            )}
        </div>
    );
}
