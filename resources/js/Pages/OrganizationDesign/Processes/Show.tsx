import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import ActivityTree, { IoForm, type ActivityNode, type IoRow } from '@/Components/organizationDesign/ActivityTree';
import type { RequirementCatalog } from '@/Components/organizationDesign/RequirementsForm';
import ProcessForm, { type Option, type ProcessFormValues, type ServiceOption } from '@/Components/organizationDesign/ProcessForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ProcessSummary } from './Index';

type Props = {
    process: ProcessSummary & {
        description_en: string | null; description_am: string | null; owner_organization_unit_id: string | null;
        approved_by: string | null; approved_at: string | null; created_by: string | null; io: IoRow[];
    };
    activities: ActivityNode[];
    versions: { id: string; version_no: number; status: string; effective_from: string | null; effective_to: string | null }[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    services: ServiceOption[];
    organizationUnits: Option[];
    processTypes: string[];
    ioKinds: string[];
    qualitySources: string[];
    catalog: RequirementCatalog;
    can: { update: boolean; publish: boolean; version: boolean; manageStandards: boolean; approveStandards: boolean };
};

export default function ProcessShow({ process, activities, versions, history, services, organizationUnits, processTypes, ioKinds, qualitySources, catalog, can }: Props) {
    const { locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [versioning, setVersioning] = useState(false);
    const [editingIo, setEditingIo] = useState(false);

    const values: ProcessFormValues = {
        organization_id: process.organization_id, service_id: process.service_id, code: process.code, name_en: process.name_en, name_am: process.name_am ?? '',
        description_en: process.description_en ?? '', description_am: process.description_am ?? '', process_type: process.process_type,
        owner_organization_unit_id: process.owner_organization_unit_id ?? '', effective_from: process.effective_from ?? '', effective_to: process.effective_to ?? '',
    };

    async function publish() {
        const { confirmed } = await confirm({ title: tt('processes.publish'), description: tt('processes.publishConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.processes.publish', process.id), {}, { preserveScroll: true });
    }
    async function cancel() {
        const { confirmed } = await confirm({ title: tt('processes.cancel'), description: tt('processes.cancelConfirm'), variant: 'danger' });
        if (confirmed) router.post(route('organization-design.processes.cancel', process.id));
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.processes.index')}
                title={`${process.code} · ${nameOf(process, locale)}`}
                description={`${nameOf(process.organization, locale)} · ${tt('processes.version')} ${process.version_no}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('processes.edit')}</Button>}
                        {can.update && <Button variant="destructiveOutline" onClick={cancel}>{tt('processes.cancel')}</Button>}
                        {can.version && <Button variant="outline" onClick={() => setVersioning(true)}>{tt('processes.newVersion')}</Button>}
                        {can.publish && <Button variant="primary" onClick={publish}>{tt('processes.publish')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${process.code} · ${nameOf(process, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('processes.details')}</CardTitle>
                            <StatusPill status={process.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.service'), process.service ? `${process.service.code} · ${nameOf(process.service, locale)}` : null)}
                            {detail(tt('fields.processType'), tt(`processes.types.${process.process_type}`))}
                            {detail(tt('fields.ownerUnit'), nameOf(process.owner_unit, locale))}
                            {detail(tt('fields.effectiveFrom'), <LocalizedDateDisplay value={process.effective_from} />)}
                            {detail(tt('fields.effectiveTo'), process.effective_to ? <LocalizedDateDisplay value={process.effective_to} /> : null)}
                            {detail(tt('processes.draftedBy'), process.created_by)}
                            {detail(tt('processes.publishedBy'), process.approved_by)}
                            {detail(tt('fields.descriptionEn'), process.description_en)}
                            {detail(tt('fields.descriptionAm'), process.description_am)}
                        </dl>
                        <div className="border-t border-(--app-border) px-5 py-3">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-xs font-medium text-(--app-muted-foreground)">{tt('processes.io')}</p>
                                {can.update && <button type="button" className={actionLinkClassName()} onClick={() => setEditingIo(true)}>{tt('processes.editIo')}</button>}
                            </div>
                            {process.io.length === 0 ? <p className="mt-1 text-xs text-(--app-muted-foreground)">{tt('processes.noIo')}</p> : (
                                <ul className="mt-1 flex flex-wrap gap-1">
                                    {process.io.map((r, i) => <li key={r.id ?? i} className={badgeClassName(r.kind === 'output' ? 'success' : r.kind === 'dependency' ? 'warning' : 'neutral')}>{tt(`processes.kinds.${r.kind}`)}: {(locale === 'am' && r.label_am) || r.label_en}</li>)}
                                </ul>
                            )}
                        </div>
                        {process.status === 'draft' && !can.publish && can.update && (
                            <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('processes.publishByOther')}</p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('processes.tree')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('processes.treeHelp')}</p></CardHeader>
                        <div className="p-3">
                            <ActivityTree processId={process.id} tree={activities} ioKinds={ioKinds} qualitySources={qualitySources} catalog={catalog} can={{ update: can.update, manageStandards: can.manageStandards && process.status !== 'cancelled', approveStandards: can.approveStandards }} />
                        </div>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('processes.versions')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <div>
                                        {v.id === process.id ? <span className="font-semibold">v{v.version_no}</span> : <Link href={route('organization-design.processes.show', v.id)} className={actionLinkClassName({ size: 'sm' })}>v{v.version_no}</Link>}
                                        <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={v.effective_from} />{v.effective_to && <> – <LocalizedDateDisplay value={v.effective_to} /></>}</p>
                                    </div>
                                    <StatusPill status={v.status} />
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('processes.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.update && <ProcessForm show={editing} onClose={() => setEditing(false)} title={tt('processes.edit')} initial={values} url={route('organization-design.processes.update', process.id)} method="put" services={services} units={organizationUnits} processTypes={processTypes} />}
            {can.version && <ProcessForm show={versioning} onClose={() => setVersioning(false)} title={tt('processes.newVersion')} help={tt('processes.newVersionHelp')} initial={{ ...values, effective_from: '', effective_to: '' }} url={route('organization-design.processes.versions.store', process.id)} method="post" services={services} units={organizationUnits} processTypes={processTypes} lockCode />}
            {editingIo && <IoForm subjectType="process" subjectId={process.id} rows={process.io} title={process.code} kinds={ioKinds} activities={flattenTree(activities)} onClose={() => setEditingIo(false)} />}
        </AuthenticatedLayout>
    );
}

function flattenTree(tree: ActivityNode[], out: ActivityNode[] = []): ActivityNode[] {
    for (const node of tree) { out.push(node); flattenTree(node.children, out); }
    return out;
}
