import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, badgeClassName, buttonClassName } from '@/Components/ui';
interface TreeNode {
    id: string;
    office_code: string;
    name_en: string | null;
    name_am: string | null;
    office_level: string;
    status: string;
    is_head_office: boolean;
    parent_office_id: string | null;
    children: TreeNode[];
}

interface Institution {
    id: string;
    name_en: string;
    code: string;
}

interface Props {
    institution: Institution;
    tree: TreeNode[];
    can: { create: boolean };
}

function TreeNodeRow({ node, depth }: { node: TreeNode; depth: number }) {
    const { t } = useLocale();
    const [expanded, setExpanded] = useState(true);
    const hasChildren = node.children.length > 0;

    return (
        <li>
            <div
                className="flex items-center gap-2 rounded-lg px-3 py-2 hover:bg-(--app-surface-muted)"
                style={{ paddingLeft: `${(depth * 20) + 12}px` }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => setExpanded(!expanded)}
                        className="flex h-5 w-5 items-center justify-center rounded-sm text-(--app-muted-foreground) hover:text-(--app-muted-foreground)"
                    >
                        {expanded ? '▾' : '▸'}
                    </button>
                ) : (
                    <span className="h-5 w-5" />
                )}

                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-(--app-muted-foreground)">
                        {node.office_code}
                    </span>
                    <Link
                        href={route('institution-offices.show', node.id)}
                        className={actionLinkClassName({ size: 'sm' })}
                    >
                        {node.name_en ?? node.office_code}
                    </Link>
                    <span className={badgeClassName('info')}>
                        {node.office_level}
                    </span>
                    {node.is_head_office && (
                        <span className={badgeClassName('warning')}>
                            {t('institutionOffices.headOffice')}
                        </span>
                    )}
                    <StatusBadge status={node.status} />
                </div>

                <div className="flex items-center gap-1.5">
                    <Link
                        href={route('institution-offices.show', node.id)}
                        className={actionLinkClassName({ tone: 'neutral' })}
                    >
                        {t('common.view')}
                    </Link>
                    <Link
                        href={route('institution-offices.edit', node.id)}
                        className={actionLinkClassName({ tone: 'neutral' })}
                    >
                        {t('common.edit')}
                    </Link>
                </div>
            </div>

            {expanded && hasChildren && (
                <ul>
                    {node.children.map((child) => (
                        <TreeNodeRow key={child.id} node={child} depth={depth + 1} />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function InstitutionOfficesTree({ institution, tree, can }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('institution-offices.index')}
                    title={`${t('institutionOffices.treeView')} — ${institution.name_en}`}
                    actions={
                        can.create ? (
                            <Link
                                href={`${route('institution-offices.create')}?institution_id=${institution.id}`}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                + {t('institutionOffices.addOffice')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={`${t('institutionOffices.treeView')} — ${institution.name_en}`} />

            <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                {tree.length === 0 ? (
                    <p className="py-8 text-center text-sm text-(--app-muted-foreground)">
                        {t('institutionOffices.noOffices')}
                    </p>
                ) : (
                    <ul className="space-y-0.5">
                        {tree.map((node) => (
                            <TreeNodeRow key={node.id} node={node} depth={0} />
                        ))}
                    </ul>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
