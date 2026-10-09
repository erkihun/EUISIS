import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { Button, Card, EmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { StatusPill, useNames, type NamePair } from '@/Components/Cafeteria/PolicyUi';

type Node = {
    id: string; code: string; name_en: string; name_am: string | null;
    location_type: 'main' | 'branch' | 'service_point'; operational_status: string; is_active: boolean;
    opening_time: string | null; closing_time: string | null; children: Node[];
};

type Access = {
    id: string; organization: NamePair; primary_cafeteria: NamePair; allow_cross_location_usage: boolean;
    effective_from: string | null; effective_to: string | null; status: string;
};

export default function NetworkShow({ network, tree, access, can }: {
    network: { id: string; code: string; name_en: string; name_am: string | null; description: string | null; status: string; provider: NamePair };
    tree: Node[];
    access: Access[];
    can: { manage: boolean; manageAccess: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const hasMain = tree.some((n) => n.location_type === 'main');
    const addLocation = (type: 'main' | 'branch' | 'service_point') => route('cafeteria.providers.create', { network_id: network.id, location_type: type });

    const Branch = ({ node, depth }: { node: Node; depth: number }) => (
        <li>
            <div className="flex flex-wrap items-center gap-2 rounded-[var(--radius-control)] px-2 py-1.5 hover:bg-[color:var(--app-surface-muted)]" style={{ marginInlineStart: depth * 20 }}>
                <span aria-hidden="true" className="text-[color:var(--app-muted-foreground)]">{depth === 0 ? '■' : '└'}</span>
                <Link href={route('cafeteria.providers.show', node.id)} className="font-medium hover:underline">{label(node)}</Link>
                <span className="text-xs text-[color:var(--app-muted-foreground)]">{t(`cafeteriaPolicy.locationTypes.${node.location_type}`)} · {node.code}</span>
                {node.opening_time && <span className="text-xs text-[color:var(--app-muted-foreground)]">{node.opening_time}–{node.closing_time}</span>}
                <StatusPill status={node.is_active ? node.operational_status : 'inactive'} />
            </div>
            {node.children.length > 0 && <ul>{node.children.map((child) => <Branch key={child.id} node={child} depth={depth + 1} />)}</ul>}
        </li>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={label(network)} description={`${label(network.provider)} · ${network.code}`} backHref={route('cafeteria.networks.index')}
            actions={can.manage ? <Button as={Link} href={route('cafeteria.networks.edit', network.id)} size="sm" variant="outline">{t('cafeteriaPolicy.edit')}</Button> : undefined} />}>
            <Head title={label(network)} />
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="p-5">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">{t('cafeteriaPolicy.networkTree')}</h2>
                        <StatusPill status={network.status} />
                    </div>
                    {network.description && <p className="mb-3 text-sm text-[color:var(--app-muted-foreground)]">{network.description}</p>}
                    {tree.length === 0 ? <EmptyState title={t('cafeteriaPolicy.noLocations')} /> : <ul className="space-y-0.5">{tree.map((node) => <Branch key={node.id} node={node} depth={0} />)}</ul>}
                    {can.manage && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {!hasMain && <Button as={Link} href={addLocation('main')} size="sm" variant="primary">{t('cafeteriaPolicy.addMainCafeteria')}</Button>}
                            <Button as={Link} href={addLocation('branch')} size="sm" variant="outline">{t('cafeteriaPolicy.addBranch')}</Button>
                            <Button as={Link} href={addLocation('service_point')} size="sm" variant="outline">{t('cafeteriaPolicy.addServicePoint')}</Button>
                        </div>
                    )}
                </Card>

                <Card className="p-5">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">{t('cafeteriaPolicy.organizationsWithAccess')}</h2>
                        {can.manageAccess && <Button as={Link} href={route('cafeteria.access.create', { network_id: network.id })} size="sm" variant="outline">{t('cafeteriaPolicy.grantAccess')}</Button>}
                    </div>
                    {access.length === 0 ? <EmptyState title={t('cafeteriaPolicy.noResults')} /> : (
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {access.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <div>
                                        <p className="font-medium">{label(a.organization)}</p>
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                            {t('cafeteriaPolicy.primaryCafeteria')}: {label(a.primary_cafeteria)} · {t('cafeteriaPolicy.crossLocationUsage')}: {a.allow_cross_location_usage ? t('cafeteriaPolicy.yes') : t('cafeteriaPolicy.no')}
                                        </p>
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                            <LocalizedDateDisplay value={a.effective_from} /> — {a.effective_to ? <LocalizedDateDisplay value={a.effective_to} /> : t('cafeteriaPolicy.openEnded')}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusPill status={a.status} />
                                        {can.manageAccess && <Link href={route('cafeteria.access.edit', a.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">{t('cafeteriaPolicy.edit')}</Link>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
