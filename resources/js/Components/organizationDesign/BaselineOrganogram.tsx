import { Button, Card, CardHeader, CardTitle, Select, badgeClassName } from '@/Components/ui';
import { useEffect, useMemo, useState } from 'react';
import { useDesignText } from './ui';

type SnapshotUnit = { id: string; code: string; name_en: string; name_am: string | null; unit_type: string | null; parent_unit_id: string | null; status: string };
type SnapshotPosition = { id: string; code: string; title_en: string; title_am: string | null; unit_id: string | null };
type Snapshot = { units: SnapshotUnit[]; positions: SnapshotPosition[]; taken_at: string };
type Node = SnapshotUnit & { children: Node[]; positions: number };

type Diff = { added: { code: string; name: string | null }[]; removed: { code: string; name: string | null }[]; changed: { code: string; name: string | null; changes: Record<string, { from: unknown; to: unknown }> }[] };
type Comparison = { older: { reference_number: string; version_no: number }; newer: { reference_number: string; version_no: number }; units: Diff; positions: Diff; relationships: { added: string[]; removed: string[] } };

/**
 * The organogram as it was frozen in a published baseline, derived from the
 * snapshot (never kept by hand), with expand/collapse and print, and a
 * comparison with another published baseline of the same organization.
 * Loaded on demand so the baseline page stays light.
 */
export default function BaselineOrganogram({ snapshotUrl, compareUrl, comparable }: {
    snapshotUrl: string;
    /** Builds the comparison URL for another baseline id. */
    compareUrl: (otherId: string) => string;
    comparable: { id: string; reference_number: string; version_no: number }[];
}) {
    const { locale, tt } = useDesignText();
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [failed, setFailed] = useState(false);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [other, setOther] = useState('');
    const [comparison, setComparison] = useState<Comparison | null>(null);

    useEffect(() => {
        let alive = true;
        fetch(snapshotUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data: Snapshot) => alive && setSnapshot(data))
            .catch(() => alive && setFailed(true));
        return () => { alive = false; };
    }, [snapshotUrl]);

    const roots = useMemo<Node[]>(() => {
        if (!snapshot) return [];
        const counts = new Map<string, number>();
        snapshot.positions.forEach((p) => p.unit_id && counts.set(p.unit_id, (counts.get(p.unit_id) ?? 0) + 1));
        const nodes = new Map<string, Node>(snapshot.units.map((u) => [u.id, { ...u, children: [], positions: counts.get(u.id) ?? 0 }]));
        const top: Node[] = [];
        nodes.forEach((n) => {
            const parent = n.parent_unit_id ? nodes.get(n.parent_unit_id) : undefined;
            if (parent) parent.children.push(n);
            else top.push(n);
        });
        return top;
    }, [snapshot]);

    function toggle(id: string) {
        setCollapsed((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function compare(id: string) {
        setOther(id);
        setComparison(null);
        if (!id) return;
        fetch(compareUrl(id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data: Comparison) => setComparison(data))
            .catch(() => setFailed(true));
    }

    const render = (node: Node) => {
        const open = !collapsed.has(node.id);
        return (
            <li key={node.id} className="mt-1">
                <div className="flex items-center gap-2">
                    {node.children.length > 0 ? (
                        <button type="button" onClick={() => toggle(node.id)} aria-expanded={open} className="grid h-6 w-6 place-items-center rounded text-xs text-(--app-muted-foreground) hover:bg-(--app-surface-muted) print:hidden">
                            {open ? '−' : '+'}<span className="sr-only">{node.name_en}</span>
                        </button>
                    ) : <span className="inline-block w-6" aria-hidden="true" />}
                    <span className="rounded-md border border-(--app-border) bg-(--app-surface) px-2 py-1 text-sm">
                        <span className="font-mono text-xs text-(--app-muted-foreground)">{node.code}</span> <span className="font-medium text-(--app-foreground)">{locale === 'am' && node.name_am ? node.name_am : node.name_en}</span>
                        {node.positions > 0 && <span className="ms-2 text-xs tabular-nums text-(--app-muted-foreground)">{node.positions} {tt('legacyBaselines.organogramPositions')}</span>}
                    </span>
                </div>
                {open && node.children.length > 0 && <ul className="ms-6 border-s border-(--app-border) ps-3">{node.children.map(render)}</ul>}
            </li>
        );
    };

    const diffList = (label: string, diff: Diff) => (
        <div>
            <h4 className="text-sm font-semibold text-(--app-foreground)">{label}</h4>
            <ul className="mt-1 space-y-0.5 text-sm">
                {diff.added.map((r) => <li key={`a-${r.code}`}><span className={badgeClassName('success')}>{tt('legacyBaselines.diffAdded')}</span> {r.code} · {r.name}</li>)}
                {diff.removed.map((r) => <li key={`r-${r.code}`}><span className={badgeClassName('danger')}>{tt('legacyBaselines.diffRemoved')}</span> {r.code} · {r.name}</li>)}
                {diff.changed.map((r) => <li key={`c-${r.code}`}><span className={badgeClassName('warning')}>{tt('legacyBaselines.diffChanged')}</span> {r.code} · {Object.entries(r.changes).map(([f, c]) => `${f}: ${String(c.from ?? '—')} → ${String(c.to ?? '—')}`).join('; ')}</li>)}
                {diff.added.length + diff.removed.length + diff.changed.length === 0 && <li className="text-(--app-muted-foreground)">{tt('legacyBaselines.diffNone')}</li>}
            </ul>
        </div>
    );

    return (
        <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <CardTitle>{tt('legacyBaselines.organogram')}</CardTitle>
                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('legacyBaselines.organogramHelp')}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2 print:hidden">
                    <Button size="sm" variant="ghost" onClick={() => setCollapsed(new Set())}>{tt('legacyBaselines.expandAll')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => setCollapsed(new Set((snapshot?.units ?? []).map((u) => u.id)))}>{tt('legacyBaselines.collapseAll')}</Button>
                    <Button size="sm" variant="outline" onClick={() => window.print()}>{tt('legacyBaselines.print')}</Button>
                </div>
            </CardHeader>
            <div className="p-5">
                {failed && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{tt('legacyBaselines.organogramFailed')}</p>}
                {!snapshot && !failed && <p className="text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.loading')}</p>}
                {snapshot && (roots.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{tt('legacyBaselines.organogramEmpty')}</p> : <ul>{roots.map(render)}</ul>)}
            </div>
            {comparable.length > 0 && (
                <div className="space-y-3 border-t border-(--app-border) p-5 print:hidden">
                    <label htmlFor="baseline-compare" className="block text-sm font-semibold text-(--app-foreground)">{tt('legacyBaselines.compareWith')}</label>
                    <Select id="baseline-compare" value={other} onChange={(e) => compare(e.target.value)}>
                        <option value="">—</option>
                        {comparable.map((c) => <option key={c.id} value={c.id}>{c.reference_number} · v{c.version_no}</option>)}
                    </Select>
                    {comparison && (
                        <div className="space-y-3">
                            <p className="text-xs text-(--app-muted-foreground)">{comparison.older.reference_number} v{comparison.older.version_no} → {comparison.newer.reference_number} v{comparison.newer.version_no}</p>
                            {diffList(tt('legacyBaselines.diffUnits'), comparison.units)}
                            {diffList(tt('legacyBaselines.diffPositions'), comparison.positions)}
                            <div>
                                <h4 className="text-sm font-semibold text-(--app-foreground)">{tt('legacyBaselines.diffRelationships')}</h4>
                                <ul className="mt-1 space-y-0.5 text-sm">
                                    {comparison.relationships.added.map((r) => <li key={`ra-${r}`}><span className={badgeClassName('success')}>{tt('legacyBaselines.diffAdded')}</span> {r}</li>)}
                                    {comparison.relationships.removed.map((r) => <li key={`rr-${r}`}><span className={badgeClassName('danger')}>{tt('legacyBaselines.diffRemoved')}</span> {r}</li>)}
                                    {comparison.relationships.added.length + comparison.relationships.removed.length === 0 && <li className="text-(--app-muted-foreground)">{tt('legacyBaselines.diffNone')}</li>}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}
