import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import PageHeader from '@/Components/PageHeader';

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
            className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200"
            style={{ marginLeft: `${depth * 16}px` }}
        >
            {node.name_en}{' '}
            <span className="text-gray-400 dark:text-slate-500">({node.code})</span>
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
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-medium text-gray-400 dark:text-slate-500">
                                Version
                            </p>
                            <h3 className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">
                                {version.version_name}
                            </h3>
                        </div>
                        <StatusBadge status={version.status} />
                    </div>

                    <p className="mt-4 text-sm text-gray-500 dark:text-slate-400">
                        Approved by:{' '}
                        <span className="text-gray-800 dark:text-slate-200">
                            {version.approver?.name ?? 'Not yet published'}
                        </span>
                    </p>

                    {version.status !== 'published' && (
                        <button
                            type="button"
                            className="mt-6 rounded-card bg-amber-500 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-400"
                            onClick={() => form.post(route('hierarchy-versions.publish', { hierarchyVersion: version.id }))}
                        >
                            Publish Version
                        </button>
                    )}
                </section>

                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">
                        Hierarchy Tree
                    </h3>
                    <div className="mt-4 space-y-2">{renderTree(tree)}</div>
                </section>
            </div>

            <section className="mt-6 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 className="font-semibold text-gray-900 dark:text-slate-100">Edges</h3>
                <div className="mt-4 overflow-hidden rounded-card border border-gray-100 dark:border-slate-800">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {['Parent', 'Child', 'Relationship'].map((h) => (
                                    <th
                                        key={h}
                                        className="px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {edges.map((edge) => (
                                <tr
                                    key={edge.id}
                                    className="border-t border-gray-100 text-gray-700 dark:border-slate-800 dark:text-slate-200"
                                >
                                    <td className="px-4 py-3">
                                        {edge.parent_organization?.name_en ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        {edge.child_organization?.name_en ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">{edge.relationship_type}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
