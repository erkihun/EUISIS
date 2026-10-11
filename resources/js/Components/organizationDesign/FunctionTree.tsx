import Modal from '@/Components/Modal';
import { useConfirm } from '@/hooks/useConfirm';
import { Button, FieldError, FormField, Input, Select, Textarea, actionLinkClassName, cx } from '@/Components/ui';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { StatusPill, titleOf, useDesignText } from './ui';

export type FunctionNode = {
    id: string;
    code: string;
    title_en: string;
    title_am: string | null;
    description_en: string | null;
    description_am: string | null;
    legal_reference: string | null;
    sequence: number;
    status: string;
    depth: number;
    mandate_id: string;
    parent_function_id: string | null;
    power: { id: string; code: string; title_en: string; title_am: string | null } | null;
    effective_from: string | null;
    effective_to: string | null;
    children: FunctionNode[];
};

export type PowerOption = { id: string; code: string; title_en: string; title_am: string | null };

type FormValues = { code: string; title_en: string; title_am: string; description_en: string; description_am: string; legal_reference: string; sequence: string; power_id: string; parent_function_id: string; status: string };

const flatten = (nodes: FunctionNode[], out: FunctionNode[] = []): FunctionNode[] => {
    for (const node of nodes) { out.push(node); flatten(node.children, out); }
    return out;
};

/** Everything under `node`, including itself: these cannot be its new parent. */
const idsUnder = (node: FunctionNode, out = new Set<string>()): Set<string> => {
    out.add(node.id);
    node.children.forEach((child) => idsUnder(child, out));
    return out;
};

/**
 * The function tree under a mandate: expand/collapse, add a sub-function,
 * edit, move (cycle-safe: a node's own branch is never offered as a parent)
 * and remove. Writes go through the function routes; the server re-validates.
 */
export default function FunctionTree({ tree, mandateId, powers = [], canManage, emptyText }: {
    tree: FunctionNode[];
    /** Mandate new functions are created under; when absent, the tree is read-only. */
    mandateId?: string;
    powers?: PowerOption[];
    canManage: boolean;
    emptyText: string;
}) {
    const { locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [editing, setEditing] = useState<{ node: FunctionNode | null; parent: FunctionNode | null } | null>(null);
    const [moving, setMoving] = useState<FunctionNode | null>(null);
    const all = useMemo(() => flatten(tree), [tree]);
    const writable = canManage && Boolean(mandateId);

    const toggle = (id: string) => setCollapsed((set) => { const next = new Set(set); if (next.has(id)) next.delete(id); else next.add(id); return next; });

    async function remove(node: FunctionNode) {
        const { confirmed } = await confirm({ title: tt('functions.remove'), description: tt('functions.removeConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.functions.destroy', node.id), { preserveScroll: true });
    }

    const renderNode = (node: FunctionNode) => {
        const isCollapsed = collapsed.has(node.id);
        return (
            <li key={node.id}>
                <div className={cx('flex flex-wrap items-center gap-2 rounded-[var(--radius-control)] px-2 py-1.5 hover:bg-[color:var(--app-surface-muted)]')} style={{ paddingLeft: `${node.depth * 1.25 + 0.5}rem` }}>
                    <button type="button" onClick={() => toggle(node.id)} aria-expanded={!isCollapsed} aria-label={node.children.length ? (isCollapsed ? tt('functions.expandAll') : tt('functions.collapseAll')) : undefined}
                        className={cx('inline-flex h-5 w-5 items-center justify-center rounded text-xs text-[color:var(--app-muted-foreground)]', node.children.length === 0 && 'invisible')}>
                        {isCollapsed ? '▸' : '▾'}
                    </button>
                    <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{node.code}</span>
                    <span className="text-sm font-medium text-[color:var(--app-foreground)]">{titleOf(node, locale)}</span>
                    {node.power && <span className="text-xs text-[color:var(--app-muted-foreground)]">· {node.power.code}</span>}
                    {node.legal_reference && <span className="text-xs text-[color:var(--app-muted-foreground)]">· {node.legal_reference}</span>}
                    <StatusPill status={node.status} />
                    {writable && (
                        <span className="ml-auto flex items-center gap-3">
                            <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ node: null, parent: node })}>{tt('functions.addChild')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ node, parent: null })}>{tt('functions.edit')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setMoving(node)}>{tt('functions.move')}</button>
                            <button type="button" className={actionLinkClassName({ tone: 'danger' })} disabled={node.children.length > 0} onClick={() => remove(node)}>{tt('functions.remove')}</button>
                        </span>
                    )}
                </div>
                {!isCollapsed && node.children.length > 0 && <ul>{node.children.map(renderNode)}</ul>}
            </li>
        );
    };

    return (
        <div>
            {writable && (
                <div className="mb-3 flex justify-end">
                    <Button size="sm" variant="primary" onClick={() => setEditing({ node: null, parent: null })}>{tt('functions.add')}</Button>
                </div>
            )}
            {tree.length === 0
                ? <p className="px-2 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{emptyText}</p>
                : <ul className="space-y-0.5">{tree.map(renderNode)}</ul>}

            {editing && mandateId && (
                <FunctionForm
                    mandateId={mandateId}
                    node={editing.node}
                    parent={editing.parent}
                    powers={powers}
                    onClose={() => setEditing(null)}
                />
            )}
            {moving && (
                <MoveForm node={moving} candidates={all.filter((n) => !idsUnder(moving).has(n.id))} onClose={() => setMoving(null)} />
            )}
        </div>
    );
}

function FunctionForm({ mandateId, node, parent, powers, onClose }: { mandateId: string; node: FunctionNode | null; parent: FunctionNode | null; powers: PowerOption[]; onClose: () => void }) {
    const { locale, tt } = useDesignText();
    const form = useForm<FormValues>({
        code: node?.code ?? '', title_en: node?.title_en ?? '', title_am: node?.title_am ?? '',
        description_en: node?.description_en ?? '', description_am: node?.description_am ?? '',
        legal_reference: node?.legal_reference ?? '', sequence: String(node?.sequence ?? 0),
        power_id: node?.power?.id ?? '', parent_function_id: parent?.id ?? node?.parent_function_id ?? '', status: node?.status ?? 'active',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (node) form.put(route('organization-design.functions.update', node.id), options);
        else form.post(route('organization-design.mandates.functions.store', mandateId), options);
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{node ? tt('functions.edit') : parent ? tt('functions.addChild') : tt('functions.add')}</h2>
                {parent && <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{tt('fields.parent')}: {parent.code} · {titleOf(parent, locale)}</p>}
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="fn-code" label={tt('fields.code')} required error={form.errors.code}>
                        {({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField id="fn-sequence" label={tt('fields.sequence')} error={form.errors.sequence}>
                        {({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}
                    </FormField>
                    <FormField id="fn-title-en" label={tt('fields.titleEn')} required error={form.errors.title_en}>
                        {({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}
                    </FormField>
                    <FormField id="fn-title-am" label={tt('fields.titleAm')} error={form.errors.title_am}>
                        {({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}
                    </FormField>
                    <FormField id="fn-power" label={tt('fields.power')} error={form.errors.power_id}>
                        {({ id }) => (
                            <Select id={id} value={form.data.power_id} onChange={(e) => form.setData('power_id', e.target.value)}>
                                <option value="">—</option>
                                {powers.map((p) => <option key={p.id} value={p.id}>{p.code} · {titleOf(p, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField id="fn-legal" label={tt('fields.legalReference')} error={form.errors.legal_reference}>
                        {({ id }) => <Input id={id} value={form.data.legal_reference} onChange={(e) => form.setData('legal_reference', e.target.value)} />}
                    </FormField>
                    <FormField id="fn-status" label={tt('fields.status')} error={form.errors.status}>
                        {({ id }) => (
                            <Select id={id} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="active">{tt('status.active')}</option>
                                <option value="inactive">{tt('status.inactive')}</option>
                            </Select>
                        )}
                    </FormField>
                    <FormField id="fn-desc-en" label={tt('fields.descriptionEn')} className="sm:col-span-2" error={form.errors.description_en}>
                        {({ id }) => <Textarea id={id} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
                    </FormField>
                    <FormField id="fn-desc-am" label={tt('fields.descriptionAm')} className="sm:col-span-2" error={form.errors.description_am}>
                        {({ id }) => <Textarea id={id} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
                    </FormField>
                </div>
                <FieldError>{form.errors.parent_function_id ?? (form.errors as Record<string, string | undefined>).mandate_id}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}

function MoveForm({ node, candidates, onClose }: { node: FunctionNode; candidates: FunctionNode[]; onClose: () => void }) {
    const { locale, tt } = useDesignText();
    const form = useForm({ parent_function_id: node.parent_function_id ?? '' });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ parent_function_id: data.parent_function_id || null }));
        form.post(route('organization-design.functions.move', node.id), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('functions.move')}: {node.code} · {titleOf(node, locale)}</h2>
                <div className="mt-5">
                    <FormField id="move-parent" label={tt('functions.moveTo')} error={form.errors.parent_function_id}>
                        {({ id }) => (
                            <Select id={id} value={form.data.parent_function_id} onChange={(e) => form.setData('parent_function_id', e.target.value)}>
                                <option value="">{tt('functions.moveToRoot')}</option>
                                {candidates.map((c) => <option key={c.id} value={c.id}>{'— '.repeat(c.depth)}{c.code} · {titleOf(c, locale)}</option>)}
                            </Select>
                        )}
                    </FormField>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('functions.move')}</Button>
                </div>
            </form>
        </Modal>
    );
}
