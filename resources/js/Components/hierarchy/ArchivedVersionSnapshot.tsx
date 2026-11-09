import { Link } from '@inertiajs/react';
import { useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ChevronDown } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

export type SnapshotNode = {
    id?: string;
    code: string;
    name_en: string | null;
    name_am: string | null;
    type_en: string | null;
    type_am: string | null;
    status: string;
    relationship: string | null;
    units: number;
    positions: number;
    employees: number;
    children: SnapshotNode[];
};

export type VersionSnapshot = {
    captured_at: string;
    summary: { organizations: number; relationships: number; roots: number; max_depth: number; units: number; positions: number; employees: number };
    by_type: { name_en: string | null; name_am: string | null; count: number }[];
    tree: SnapshotNode[];
};

function SnapshotBranch({ node, depth }: { node: SnapshotNode; depth: number }) {
    const { locale, t } = useLocale();
    const [open, setOpen] = useState(depth < 1);
    const hasChildren = node.children.length > 0;
    const counts = [
        node.units > 0 && `${t('hierarchyVersions.snapshotUnits')} ${node.units}`,
        node.positions > 0 && `${t('hierarchyVersions.snapshotPositions')} ${node.positions}`,
        node.employees > 0 && `${t('hierarchyVersions.snapshotEmployees')} ${node.employees}`,
    ].filter(Boolean);

    return (
        <li>
            <div className="flex items-start gap-2 rounded-control px-1 py-1.5 hover:bg-(--app-surface-muted)">
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                    className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-sm text-(--app-muted-foreground) ${hasChildren ? '' : 'invisible'}`}
                >
                    <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? '' : '-rotate-90'}`} aria-hidden="true" />
                </button>
                <div className="min-w-0 flex-1">
                    <p className="text-sm text-(--app-foreground)">
                        {node.id
                            ? <Link href={route('organizations.show', node.id)} className="hover:text-primary hover:underline">{localizedName(node.name_en ?? '', node.name_am, locale)}</Link>
                            : localizedName(node.name_en ?? '', node.name_am, locale)}
                    </p>
                    <p className="mt-0.5 flex flex-wrap gap-x-2 text-xs text-(--app-muted-foreground)">
                        <span className="font-mono">{node.code}</span>
                        {(node.type_en || node.type_am) && <span>· {localizedName(node.type_en ?? '', node.type_am, locale)}</span>}
                        {counts.length > 0 && <span>· {counts.join(' · ')}</span>}
                        {hasChildren && <span>· {t('hierarchyVersions.snapshotChildren')} {node.children.length}</span>}
                    </p>
                </div>
            </div>
            {open && hasChildren && (
                <ul className="ml-3 border-l border-(--app-border) pl-2">
                    {node.children.map((child) => <SnapshotBranch key={child.code} node={child} depth={depth + 1} />)}
                </ul>
            )}
        </li>
    );
}

/** A version's structure and counts as recorded when it left service; later renames and transfers do not reach it. */
export default function ArchivedVersionSnapshot({ snapshot }: { snapshot: VersionSnapshot }) {
    const { locale, t } = useLocale();
    const figures: [string, number][] = [
        [t('hierarchyVersions.snapshotOrganizations'), snapshot.summary.organizations],
        [t('hierarchyVersions.snapshotRelationships'), snapshot.summary.relationships],
        [t('hierarchyVersions.snapshotDepth'), snapshot.summary.max_depth],
        [t('hierarchyVersions.snapshotUnits'), snapshot.summary.units],
        [t('hierarchyVersions.snapshotPositions'), snapshot.summary.positions],
        [t('hierarchyVersions.snapshotEmployees'), snapshot.summary.employees],
    ];

    return (
        <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
            <h3 className="font-semibold text-(--app-foreground)">{t('hierarchyVersions.snapshotTitle')}</h3>
            <p className="mt-1 text-xs text-(--app-muted-foreground)">
                {t('hierarchyVersions.snapshotNote')} <LocalizedDateDisplay value={snapshot.captured_at} withTime />
            </p>

            <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                {figures.map(([label, value]) => (
                    <div key={label} className="rounded-card border border-(--app-border) px-3 py-2">
                        <dd className="text-xl font-semibold tabular-nums text-(--app-foreground)">{value}</dd>
                        <dt className="text-xs text-(--app-muted-foreground)">{label}</dt>
                    </div>
                ))}
            </dl>

            {snapshot.by_type.length > 0 && (
                <div className="mt-4">
                    <p className="text-xs font-medium text-(--app-muted-foreground)">{t('hierarchyVersions.snapshotByType')}</p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {snapshot.by_type.map((type, index) => (
                            <span key={index} className="rounded-full bg-(--app-surface-muted) px-2.5 py-0.5 text-xs text-(--app-foreground)">
                                {localizedName(type.name_en ?? '', type.name_am, locale)} <span className="tabular-nums text-(--app-muted-foreground)">{type.count}</span>
                            </span>
                        ))}
                    </div>
                </div>
            )}

            <div className="mt-4 border-t border-(--app-border) pt-3">
                <p className="mb-1 text-xs font-medium text-(--app-muted-foreground)">{t('hierarchyVersions.snapshotStructure')}</p>
                <ul>{snapshot.tree.map((node) => <SnapshotBranch key={node.code} node={node} depth={0} />)}</ul>
            </div>
        </section>
    );
}
