import { useLocale } from '@/hooks/useLocale';

type TreeNode = {
    id?: string;
    organization_id?: string;
    code: string;
    name_en: string;
    name_am?: string | null;
    children: TreeNode[];
};

const INDENT_PX = 28;
const GUIDE_X_OFFSET = 10;
const ROW_GUTTER_PX = 28;

function PreviewNode({
    node,
    depth,
    isLast,
    locale,
}: {
    node: TreeNode;
    depth: number;
    isLast: boolean;
    locale: 'en' | 'am';
}) {
    const children = Array.isArray(node.children) ? node.children : [];
    const hasChildren = children.length > 0;
    const guideLeft = depth * INDENT_PX + GUIDE_X_OFFSET;
    const childGuideLeft = (depth + 1) * INDENT_PX + GUIDE_X_OFFSET;
    const rowMarginLeft = depth > 0 ? guideLeft + ROW_GUTTER_PX : 0;
    const name = locale === 'am' && node.name_am ? node.name_am : node.name_en;

    return (
        <div className="relative">
            {depth > 0 && (
                <>
                    {/* Vertical bar */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-0 z-10 w-px bg-gray-300 dark:bg-slate-600"
                        style={{
                            left: `${guideLeft}px`,
                            ...(isLast ? { height: '1.125rem' } : { bottom: 0 }),
                        }}
                    />
                    {/* Horizontal arm */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute z-10 h-px bg-gray-300 dark:bg-slate-600"
                        style={{
                            left: `${guideLeft}px`,
                            top: '1.125rem',
                            width: `${ROW_GUTTER_PX}px`,
                        }}
                    />
                </>
            )}

            <div
                className="relative z-20 flex min-h-[2.25rem] items-center gap-2 rounded-lg px-2.5 py-1.5"
                style={{ marginLeft: `${rowMarginLeft}px` }}
            >
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                    {hasChildren
                        ? <span className="block h-1.5 w-1.5 rounded-sm bg-blue-400 dark:bg-blue-500" />
                        : <span className="block h-1.5 w-1.5 rounded-full bg-gray-300 dark:bg-slate-600" />}
                </span>
                <span className="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-slate-200">
                    {name}
                </span>
                <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    {node.code}
                </span>
            </div>

            {hasChildren && (
                <div className="relative">
                    {children.map((child, index) => (
                        <PreviewNode
                            key={child.organization_id ?? child.id ?? `${child.code}-${depth}-${index}`}
                            node={child}
                            depth={depth + 1}
                            isLast={index === children.length - 1}
                            locale={locale}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function HierarchyTreePreview({
    nodes,
    emptyMessage,
}: {
    nodes: TreeNode[];
    emptyMessage: string;
}) {
    const { locale } = useLocale();

    if (!Array.isArray(nodes) || nodes.length === 0) {
        return <p className="text-sm text-gray-500 dark:text-slate-400">{emptyMessage}</p>;
    }

    return (
        <div className="space-y-0.5">
            {nodes.map((node, index) => (
                <PreviewNode
                    key={node.organization_id ?? node.id ?? `${node.code}-0-${index}`}
                    node={node}
                    depth={0}
                    isLast={index === nodes.length - 1}
                    locale={locale}
                />
            ))}
        </div>
    );
}
