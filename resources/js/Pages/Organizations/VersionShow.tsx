import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import PageHeader from '@/Components/PageHeader';

import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type TreeNode = {
    id: string;
    code: string;
    name_en: string;
    children: TreeNode[];
};

function renderTree(nodes: TreeNode[], depth = 0): JSX.Element[] {
    return nodes.flatMap((node) => [
        <div
            key={node.id}
            className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) px-3 py-2 text-sm text-(--app-foreground)"
            style={{ marginLeft: `${depth * 16}px` }}
        >
            {node.name_en}{' '}
            <span className="text-(--app-muted-foreground)">({node.code})</span>
        </div>,
        ...renderTree(node.children, depth + 1),
    ]);
}

export default function VersionShow({
    version,
    edges,
    tree,
}: {
    version: {
        id: string;
        version_name: string;
        status: string;
        approver?: { name: string } | null;
    };
    edges: Array<{
        id: string;
        relationship_type: string;
        parent_organization?: { name_en: string; code: string };
        child_organization?: { name_en: string; code: string };
    }>;
    tree: TreeNode[];
}) {
    const form = useForm({});

    return (
        <AuthenticatedLayout
            header={
                <PageHeader title="Hierarchy Version" description={version.version_name} />
            }
        >
            <Head title={version.version_name} />

            <div className="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium text-(--app-muted-foreground)">
                                Version
                            </p>
                            <h3 className="mt-1 text-2xl font-semibold text-(--app-foreground)">
                                {version.version_name}
                            </h3>
                        </div>
                        <StatusBadge status={version.status} />
                    </div>

                    <p className="mt-4 text-sm text-(--app-muted-foreground)">
                        Approved by:{' '}
                        <span className="text-(--app-foreground)">
                            {version.approver?.name ?? 'Not yet published'}
                        </span>
                    </p>

                    {version.status !== 'published' && (
                        <button
                            type="button"
                            className="mt-6 rounded-card bg-amber-500 px-4 py-2 text-sm font-medium text-(--app-foreground) hover:bg-amber-600 focus:outline-hidden focus:ring-2 focus:ring-amber-400"
                            onClick={() => form.post(route('hierarchy-versions.publish', { hierarchyVersion: version.id }))}
                        >
                            Publish Version
                        </button>
                    )}
                </section>

                <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                    <h3 className="font-semibold text-(--app-foreground)">
                        Hierarchy Tree
                    </h3>
                    <div className="mt-4 space-y-2">{renderTree(tree)}</div>
                </section>
            </div>

            <section className="mt-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                <h3 className="font-semibold text-(--app-foreground)">Edges</h3>
                <div className="mt-4 overflow-hidden rounded-card border border-(--app-border)">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {['Parent', 'Child', 'Relationship'].map((h) => (
                                    <th
                                        key={h}
                                        className={cx(tableHeaderCellClassName, 'text-left')}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {edges.map((edge) => (
                                <tr
                                    key={edge.id}
                                    className={tableRowClassName}
                                >
                                    <td className={tableCellClassName}>
                                        {edge.parent_organization?.name_en ?? '—'}
                                    </td>
                                    <td className={tableCellClassName}>
                                        {edge.child_organization?.name_en ?? '—'}
                                    </td>
                                    <td className={tableCellClassName}>{edge.relationship_type}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
