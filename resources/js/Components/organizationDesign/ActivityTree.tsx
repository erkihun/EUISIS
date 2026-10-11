import Modal from '@/Components/Modal';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import WorkStandardForm, { type StandardRow } from './WorkStandardForm';
import { StatusPill, nameOf, useDesignText } from './ui';
import { Button, FieldError, FormField, Input, Select, Textarea, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export type IoRow = { id?: string; kind: string; label_en: string; label_am: string | null; description: string | null; depends_on_activity_id: string | null; sequence?: number };
export type TaskNode = { id: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null; sequence: number; required_output: string | null; is_active: boolean; io: IoRow[]; standards: StandardRow[] };
export type ActivityNode = { id: string; code: string; name_en: string; name_am: string | null; description_en: string | null; description_am: string | null; sequence: number; depth: number; is_active: boolean; parent_activity_id: string | null; io: IoRow[]; standards: StandardRow[]; tasks: TaskNode[]; children: ActivityNode[] };

type Props = {
    processId: string;
    tree: ActivityNode[];
    ioKinds: string[];
    qualitySources: string[];
    can: { update: boolean; manageStandards: boolean; approveStandards: boolean };
};

type ActivityEdit = { kind: 'activity'; record: ActivityNode | null; parentId: string | null };
type TaskEdit = { kind: 'task'; record: TaskNode | null; activityId: string };
type IoEdit = { kind: 'io'; subjectType: 'activity' | 'task'; subjectId: string; rows: IoRow[]; title: string };
type StandardEdit = { kind: 'standard'; subjectType: 'activity' | 'task'; subjectId: string; record: StandardRow | null };
type Editing = ActivityEdit | TaskEdit | IoEdit | StandardEdit | null;

/** Flat list of every activity for the dependency picker. */
function flatten(tree: ActivityNode[], out: ActivityNode[] = []): ActivityNode[] {
    for (const node of tree) { out.push(node); flatten(node.children, out); }
    return out;
}

/**
 * The activity tree of a process with its tasks, typed I/O rows and work
 * standards. All writes go through the design routes; the process decides
 * whether anything may change (draft) — the tree only shows the controls.
 */
export default function ActivityTree({ processId, tree, ioKinds, qualitySources, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Editing>(null);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const allActivities = flatten(tree);

    const toggle = (id: string) => setCollapsed((prev) => { const next = new Set(prev); if (next.has(id)) next.delete(id); else next.add(id); return next; });

    async function removeActivity(node: ActivityNode) {
        const { confirmed } = await confirm({ title: tt('processes.removeActivity'), description: tt('processes.removeActivityConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.activities.destroy', node.id), { preserveScroll: true });
    }
    async function removeTask(task: TaskNode) {
        const { confirmed } = await confirm({ title: tt('processes.removeTask'), description: tt('processes.removeTaskConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.tasks.destroy', task.id), { preserveScroll: true });
    }
    async function approveStandard(s: StandardRow) {
        const { confirmed } = await confirm({ title: tt('processes.approveStandard'), description: tt('processes.approveStandardConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.standards.approve', s.id), {}, { preserveScroll: true });
    }
    async function retireStandard(s: StandardRow) {
        const { confirmed } = await confirm({ title: tt('processes.retireStandard'), description: tt('processes.retireStandardConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.standards.retire', s.id), {}, { preserveScroll: true });
    }
    function removeStandard(s: StandardRow) {
        router.delete(route('organization-design.standards.destroy', s.id), { preserveScroll: true });
    }

    // DECIMAL(14,4) from the server; people read "12", not "12.0000".
    const num = (value: string | number | null) => (value == null ? null : String(Number(value)));

    const ioSummary = (rows: IoRow[]) => rows.length === 0 ? <span className="text-xs text-[color:var(--app-muted-foreground)]">{tt('processes.noIo')}</span> : (
        <ul className="flex flex-wrap gap-1">
            {rows.map((r, i) => <li key={r.id ?? i} className={badgeClassName(r.kind === 'output' ? 'success' : r.kind === 'dependency' ? 'warning' : 'neutral')}>{tt(`processes.kinds.${r.kind}`)}: {(locale === 'am' && r.label_am) || r.label_en}</li>)}
        </ul>
    );

    const standards = (rows: StandardRow[], subjectType: 'activity' | 'task', subjectId: string) => (
        <div className="mt-2 space-y-1">
            {rows.length === 0 && <p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('processes.noStandards')}</p>}
            {rows.map((s) => (
                <div key={s.id} className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="font-medium">v{s.version_no}</span>
                    <StatusPill status={s.status} />
                    <span className="text-[color:var(--app-muted-foreground)]">
                        {s.planned_quantity != null && <>{t('workStandards.quantity')} {num(s.planned_quantity)} {s.quantity_unit} · </>}
                        {s.planned_time_minutes != null && <>{t('workStandards.time')} {num(s.planned_time_minutes)} · </>}
                        {s.planned_quality != null && <>{t('workStandards.quality')} {num(s.planned_quality)} {s.quality_unit} · </>}
                        <LocalizedDateDisplay value={s.effective_from} />{s.effective_to && <> – <LocalizedDateDisplay value={s.effective_to} /></>}
                    </span>
                    {s.status === 'draft' && can.manageStandards && <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'standard', subjectType, subjectId, record: s })}>{t('common.edit')}</button>}
                    {s.status === 'draft' && can.manageStandards && <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeStandard(s)}>{tt('processes.removeStandard')}</button>}
                    {s.status === 'draft' && can.approveStandards && <button type="button" className={actionLinkClassName({ tone: 'success' })} onClick={() => approveStandard(s)}>{tt('processes.approveStandard')}</button>}
                    {s.status === 'approved' && can.approveStandards && <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => retireStandard(s)}>{tt('processes.retireStandard')}</button>}
                </div>
            ))}
            {can.manageStandards && <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'standard', subjectType, subjectId, record: null })}>{tt('processes.addStandard')}</button>}
        </div>
    );

    const renderTask = (task: TaskNode) => (
        <li key={task.id} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-3">
            <div className="flex flex-wrap items-start gap-2">
                <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{task.code}</span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-[color:var(--app-foreground)]">{nameOf(task, locale)}{!task.is_active && <span className={`ml-2 ${badgeClassName('neutral')}`}>{tt('status.inactive')}</span>}</p>
                    {task.required_output && <p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('fields.requiredOutput')}: {task.required_output}</p>}
                    <div className="mt-1">{ioSummary(task.io)}</div>
                    {standards(task.standards, 'task', task.id)}
                </div>
                {can.update && (
                    <span className="flex flex-wrap gap-3">
                        <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'task', record: task, activityId: '' })}>{t('common.edit')}</button>
                        <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ kind: 'io', subjectType: 'task', subjectId: task.id, rows: task.io, title: `${task.code} · ${nameOf(task, locale)}` })}>{tt('processes.editIo')}</button>
                        <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeTask(task)}>{t('common.remove')}</button>
                    </span>
                )}
            </div>
        </li>
    );

    const renderActivity = (node: ActivityNode) => {
        const isCollapsed = collapsed.has(node.id);
        return (
            <li key={node.id}>
                <div className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3" style={{ marginLeft: `${node.depth * 16}px` }}>
                    <div className="flex flex-wrap items-start gap-2">
                        {(node.children.length > 0 || node.tasks.length > 0) && (
                            <button type="button" className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]" aria-expanded={!isCollapsed} onClick={() => toggle(node.id)}>{isCollapsed ? '▸' : '▾'}</button>
                        )}
                        <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{node.code}</span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-[color:var(--app-foreground)]">{nameOf(node, locale)}{!node.is_active && <span className={`ml-2 ${badgeClassName('neutral')}`}>{tt('status.inactive')}</span>}</p>
                            {(locale === 'am' ? node.description_am : node.description_en) && <p className="text-xs text-[color:var(--app-muted-foreground)]">{locale === 'am' ? node.description_am : node.description_en}</p>}
                            <div className="mt-1">{ioSummary(node.io)}</div>
                            {standards(node.standards, 'activity', node.id)}
                        </div>
                        {can.update && (
                            <span className="flex flex-wrap gap-3">
                                <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'activity', record: node, parentId: node.parent_activity_id })}>{t('common.edit')}</button>
                                <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ kind: 'activity', record: null, parentId: node.id })}>{tt('processes.addChildActivity')}</button>
                                <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ kind: 'task', record: null, activityId: node.id })}>{tt('processes.addTask')}</button>
                                <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ kind: 'io', subjectType: 'activity', subjectId: node.id, rows: node.io, title: `${node.code} · ${nameOf(node, locale)}` })}>{tt('processes.editIo')}</button>
                                <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeActivity(node)}>{t('common.remove')}</button>
                            </span>
                        )}
                    </div>
                    {!isCollapsed && node.tasks.length > 0 && <ul className="mt-3 space-y-2 border-l-2 border-[color:var(--app-border)] pl-3">{node.tasks.map(renderTask)}</ul>}
                </div>
                {!isCollapsed && node.children.length > 0 && <ul className="mt-2 space-y-2">{node.children.map(renderActivity)}</ul>}
            </li>
        );
    };

    return (
        <div>
            {tree.length === 0 ? <p className="px-2 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('processes.noActivities')}</p> : <ul className="space-y-2">{tree.map(renderActivity)}</ul>}
            {can.update && <div className="mt-3"><Button size="sm" variant="primary" onClick={() => setEditing({ kind: 'activity', record: null, parentId: null })}>{tt('processes.addActivity')}</Button></div>}

            {editing?.kind === 'activity' && <ActivityForm processId={processId} record={editing.record} parentId={editing.parentId} parents={allActivities} onClose={() => setEditing(null)} />}
            {editing?.kind === 'task' && <TaskForm record={editing.record} activityId={editing.activityId} onClose={() => setEditing(null)} />}
            {editing?.kind === 'io' && <IoForm subjectType={editing.subjectType} subjectId={editing.subjectId} rows={editing.rows} title={editing.title} kinds={ioKinds} activities={allActivities} onClose={() => setEditing(null)} />}
            {editing?.kind === 'standard' && (
                <WorkStandardForm
                    record={editing.record}
                    qualitySources={qualitySources}
                    method={editing.record ? 'put' : 'post'}
                    url={editing.record ? route('organization-design.standards.update', editing.record.id) : editing.subjectType === 'task' ? route('organization-design.tasks.standards.store', editing.subjectId) : route('organization-design.activities.standards.store', editing.subjectId)}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function ActivityForm({ processId, record, parentId, parents, onClose }: { processId: string; record: ActivityNode | null; parentId: string | null; parents: ActivityNode[]; onClose: () => void }) {
    const { locale, tt } = useDesignText();
    const form = useForm({
        code: record?.code ?? '', name_en: record?.name_en ?? '', name_am: record?.name_am ?? '', description_en: record?.description_en ?? '', description_am: record?.description_am ?? '',
        parent_activity_id: parentId ?? '', sequence: String(record?.sequence ?? 0), is_active: record?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    // The activity cannot move under itself or its own subtree.
    const candidates = record ? parents.filter((p) => p.id !== record.id && !isUnder(p, record, parents)) : parents;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (record) form.put(route('organization-design.activities.update', record.id), options);
        else form.post(route('organization-design.processes.activities.store', processId), options);
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{record ? tt('processes.editActivity') : tt('processes.addActivity')}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="act-code" label={tt('fields.code')} required error={errors.code}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}</FormField>
                    <FormField id="act-seq" label={tt('fields.sequence')} error={errors.sequence}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}</FormField>
                    <FormField id="act-name-en" label={tt('fields.nameEn')} required error={errors.name_en}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField id="act-name-am" label={tt('fields.nameAm')} error={errors.name_am}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>
                    <FormField id="act-parent" label={tt('fields.parent')} className="sm:col-span-2" error={errors.parent_activity_id}>
                        {({ id }) => (
                            <Select id={id} value={form.data.parent_activity_id} onChange={(e) => form.setData('parent_activity_id', e.target.value)}>
                                <option value="">{tt('functions.moveToRoot')}</option>
                                {candidates.map((p) => <option key={p.id} value={p.id}>{'— '.repeat(p.depth)}{p.code} · {nameOf(p, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField id="act-desc-en" label={tt('fields.descriptionEn')} className="sm:col-span-2" error={errors.description_en}>{({ id }) => <Textarea id={id} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}</FormField>
                    <FormField id="act-desc-am" label={tt('fields.descriptionAm')} className="sm:col-span-2" error={errors.description_am}>{({ id }) => <Textarea id={id} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}</FormField>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <span>{tt('fields.isActive')}</span>
                    </label>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}

function isUnder(candidate: ActivityNode, root: ActivityNode, all: ActivityNode[]): boolean {
    let current: ActivityNode | undefined = candidate;
    while (current?.parent_activity_id) {
        if (current.parent_activity_id === root.id) return true;
        current = all.find((a) => a.id === current!.parent_activity_id);
    }
    return false;
}

function TaskForm({ record, activityId, onClose }: { record: TaskNode | null; activityId: string; onClose: () => void }) {
    const { tt } = useDesignText();
    const form = useForm({
        code: record?.code ?? '', name_en: record?.name_en ?? '', name_am: record?.name_am ?? '', description_en: record?.description_en ?? '', description_am: record?.description_am ?? '',
        sequence: String(record?.sequence ?? 0), required_output: record?.required_output ?? '', is_active: record?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (record) form.put(route('organization-design.tasks.update', record.id), options);
        else form.post(route('organization-design.activities.tasks.store', activityId), options);
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{record ? tt('processes.editTask') : tt('processes.addTask')}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="task-code" label={tt('fields.code')} required error={errors.code}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}</FormField>
                    <FormField id="task-seq" label={tt('fields.sequence')} error={errors.sequence}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}</FormField>
                    <FormField id="task-name-en" label={tt('fields.nameEn')} required error={errors.name_en}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField id="task-name-am" label={tt('fields.nameAm')} error={errors.name_am}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>
                    <FormField id="task-output" label={tt('fields.requiredOutput')} className="sm:col-span-2" error={errors.required_output}>{({ id }) => <Textarea id={id} rows={2} value={form.data.required_output} onChange={(e) => form.setData('required_output', e.target.value)} />}</FormField>
                    <FormField id="task-desc-en" label={tt('fields.descriptionEn')} className="sm:col-span-2" error={errors.description_en}>{({ id }) => <Textarea id={id} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}</FormField>
                    <FormField id="task-desc-am" label={tt('fields.descriptionAm')} className="sm:col-span-2" error={errors.description_am}>{({ id }) => <Textarea id={id} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}</FormField>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <span>{tt('fields.isActive')}</span>
                    </label>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}

export function IoForm({ subjectType, subjectId, rows, title, kinds, activities, onClose }: { subjectType: 'process' | 'activity' | 'task'; subjectId: string; rows: IoRow[]; title: string; kinds: string[]; activities: ActivityNode[]; onClose: () => void }) {
    const { locale, tt } = useDesignText();
    const form = useForm<{ items: IoRow[] }>({ items: rows.map((r) => ({ kind: r.kind, label_en: r.label_en, label_am: r.label_am ?? '', description: r.description ?? '', depends_on_activity_id: r.depends_on_activity_id ?? '' })) });
    const errors = form.errors as Record<string, string | undefined>;

    const setItem = (index: number, patch: Partial<IoRow>) => form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    const add = () => form.setData('items', [...form.data.items, { kind: kinds[0] ?? 'input', label_en: '', label_am: '', description: '', depends_on_activity_id: '' }]);
    const remove = (index: number) => form.setData('items', form.data.items.filter((_, i) => i !== index));

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ items: data.items.map((item) => ({ ...item, label_am: item.label_am || null, description: item.description || null, depends_on_activity_id: item.kind === 'dependency' && item.depends_on_activity_id ? item.depends_on_activity_id : null })) }));
        form.put(route('organization-design.io.sync', { subjectType, subject: subjectId }), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('processes.editIo')} · {title}</h2>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{tt('processes.ioHelp')}</p>
                <ul className="mt-5 space-y-3">
                    {form.data.items.map((item, index) => (
                        <li key={index} className="grid gap-2 rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-3 sm:grid-cols-[9rem_1fr_1fr_auto]">
                            <FormField id={`io-kind-${index}`} label={tt('fields.kind')} error={errors[`items.${index}.kind`]}>
                                {({ id }) => <Select id={id} value={item.kind} onChange={(e) => setItem(index, { kind: e.target.value })}>{kinds.map((k) => <option key={k} value={k}>{tt(`processes.kinds.${k}`)}</option>)}</Select>}
                            </FormField>
                            <FormField id={`io-en-${index}`} label={tt('fields.labelEn')} required error={errors[`items.${index}.label_en`]}>{({ id }) => <Input id={id} value={item.label_en} maxLength={255} onChange={(e) => setItem(index, { label_en: e.target.value })} />}</FormField>
                            <FormField id={`io-am-${index}`} label={tt('fields.labelAm')} error={errors[`items.${index}.label_am`]}>{({ id }) => <Input id={id} value={item.label_am ?? ''} maxLength={255} onChange={(e) => setItem(index, { label_am: e.target.value })} />}</FormField>
                            <div className="flex items-end"><button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => remove(index)}>{tt('functions.remove')}</button></div>
                            {item.kind === 'dependency' && (
                                <FormField id={`io-dep-${index}`} label={tt('processes.dependsOn')} className="sm:col-span-4" error={errors[`items.${index}.depends_on_activity_id`]}>
                                    {({ id }) => (
                                        <Select id={id} value={item.depends_on_activity_id ?? ''} onChange={(e) => setItem(index, { depends_on_activity_id: e.target.value })}>
                                            <option value="">—</option>
                                            {activities.filter((a) => a.id !== subjectId).map((a) => <option key={a.id} value={a.id}>{a.code} · {nameOf(a, locale)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                            )}
                        </li>
                    ))}
                </ul>
                <div className="mt-3"><Button type="button" size="sm" variant="outline" onClick={add}>{tt('processes.addIoRow')}</Button></div>
                <FieldError>{errors.items ?? errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
