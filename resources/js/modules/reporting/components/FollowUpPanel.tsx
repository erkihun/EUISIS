import { cx } from '@/Components/ui';
import { PersonPicker } from '@/modules/reporting/components/Pickers';
import { badge, inputCls, labelCls, mutedCls, primaryBtn, priorityTone, secondaryBtn, sectionTitleCls, textareaCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { FollowUpRow, PersonRef } from '@/modules/reporting/types';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

const STATUS_TONE: Record<string, 'neutral' | 'info' | 'success' | 'warning' | 'danger'> = { open: 'warning', in_progress: 'info', done: 'success', closed: 'neutral', cancelled: 'neutral' };

/** One follow-up action with its progress notes, and the form to add a note. */
export function FollowUpCard({ action, canClose, canUpdate }: { action: FollowUpRow; canClose: boolean; canUpdate: boolean }): JSX.Element {
    const { rt, moment, date } = useReporting();
    const [open, setOpen] = useState(false);
    const form = useForm<{ progress: number; status: string; note: string; evidence: File | null }>({ progress: action.progress, status: action.status === 'open' ? 'in_progress' : action.status, note: '', evidence: null });
    const finished = action.status === 'closed' || action.status === 'cancelled';
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('reporting.follow-ups.update', action.id), { preserveScroll: true, forceFormData: true, onSuccess: () => { form.reset('note', 'evidence'); setOpen(false); } });
    };

    return (
        <article className="rounded-control border border-(--app-border) p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">{action.title}</h3>
                    <p className="mt-0.5 whitespace-pre-line text-sm">{action.description}</p>
                    <p className={cx(mutedCls, 'mt-1 text-xs')}>
                        {rt('followUps.owner')}: {action.owner_name ?? rt('followUps.noOwner')} · {rt('followUps.due')}: {date(action.due_at)} · {rt('followUps.raisedBy')}: {action.created_by_name}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className={badge(priorityTone(action.priority))}>{rt(`followUps.priority.${action.priority}`)}</span>
                    <span className={badge(STATUS_TONE[action.status] ?? 'neutral')}>{rt(`followUps.status.${action.status}`)}</span>
                    {action.overdue && <span className={badge('danger')}>{rt('followUps.overdue')}</span>}
                </div>
            </div>
            <div className="mt-2 flex items-center gap-2">
                <progress className="h-2 w-40" max={100} value={action.progress} aria-label={rt('followUps.progress')} />
                <span className="text-xs tabular-nums">{action.progress}%</span>
            </div>

            {action.updates && action.updates.length > 0 && (
                <ol className="mt-2 space-y-1.5 border-t border-(--app-border) pt-2">
                    {action.updates.map((update) => (
                        <li key={update.id} className="text-sm">
                            <span className={cx(mutedCls, 'text-xs')}>{moment(update.created_at)} · {update.author_name} · {rt(`followUps.status.${update.status}`)} · {update.progress}%</span>
                            <p className="whitespace-pre-line">{update.note}</p>
                            {update.evidence_url && <a href={update.evidence_url} className="text-xs text-primary hover:underline">{rt('followUps.evidence')}: {update.evidence_name}</a>}
                        </li>
                    ))}
                </ol>
            )}

            {!finished && canUpdate && !open && <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => setOpen(true)}>{rt('followUps.update')}</button>}
            {open && (
                <form onSubmit={save} className="mt-3 grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-2">
                    <div>
                        <label htmlFor={`fu-status-${action.id}`} className={labelCls}>{rt('followUps.statusLabel')}</label>
                        <select id={`fu-status-${action.id}`} className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {['open', 'in_progress', 'done', ...(canClose ? ['closed', 'cancelled'] : [])].map((status) => <option key={status} value={status}>{rt(`followUps.status.${status}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor={`fu-progress-${action.id}`} className={labelCls}>{rt('followUps.progress')} (%)</label>
                        <input id={`fu-progress-${action.id}`} type="number" min={0} max={100} step={5} className={inputCls} value={form.data.progress} onChange={(e) => form.setData('progress', Number(e.target.value))} />
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor={`fu-note-${action.id}`} className={labelCls}>{rt('followUps.note')}</label>
                        <textarea id={`fu-note-${action.id}`} className={textareaCls} rows={3} maxLength={5000} required value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                        {form.errors.note && <p className="text-xs text-red-700 dark:text-red-400" role="alert">{form.errors.note}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor={`fu-file-${action.id}`} className={labelCls}>{rt('followUps.evidence')} ({rt('common.optional')})</label>
                        <input id={`fu-file-${action.id}`} type="file" className="text-sm" onChange={(e) => form.setData('evidence', e.target.files?.[0] ?? null)} />
                        {form.progress && <progress className="mt-1 h-2 w-full" max={100} value={form.progress.percentage ?? 0} />}
                    </div>
                    <div className="flex gap-2 sm:col-span-2">
                        <button type="submit" className={primaryBtn} disabled={form.processing || form.data.note.trim() === ''}>{rt('common.save')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setOpen(false)}>{rt('common.cancel')}</button>
                    </div>
                </form>
            )}
        </article>
    );
}

/** The follow-up actions of one report, and the form a reviewer uses to raise a new one. */
export default function FollowUpPanel({ obligationId, actions, canCreate, canUpdate }: { obligationId: string; actions: FollowUpRow[]; canCreate: boolean; canUpdate: boolean }): JSX.Element {
    const { rt } = useReporting();
    const [adding, setAdding] = useState(false);
    const [owner, setOwner] = useState<PersonRef | null>(null);
    const form = useForm({ title: '', description: '', owner_user_id: null as number | null, priority: 'normal', due_at: '' });
    const create = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, owner_user_id: owner?.id ?? null }));
        form.post(route('reporting.follow-ups.store', obligationId), { preserveScroll: true, onSuccess: () => { form.reset(); setOwner(null); setAdding(false); } });
    };

    return (
        <div className="space-y-3">
            <p className={cx(mutedCls, 'text-xs')}>{rt('followUps.separateNote')}</p>
            {actions.length === 0 && <p className={mutedCls}>{rt('followUps.noneForReport')}</p>}
            {actions.map((action) => <FollowUpCard key={action.id} action={action} canClose={canCreate} canUpdate={canUpdate || canCreate} />)}
            {canCreate && !adding && <button type="button" className={secondaryBtn} onClick={() => setAdding(true)}>{rt('followUps.add')}</button>}
            {adding && (
                <form onSubmit={create} className="grid gap-3 rounded-control border border-(--app-border) p-3 sm:grid-cols-2">
                    <h3 className={cx(sectionTitleCls, 'sm:col-span-2')}>{rt('followUps.add')}</h3>
                    <div className="sm:col-span-2">
                        <label htmlFor="fu-title" className={labelCls}>{rt('followUps.titleLabel')}</label>
                        <input id="fu-title" type="text" className={inputCls} maxLength={250} required value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="fu-desc" className={labelCls}>{rt('followUps.description')}</label>
                        <textarea id="fu-desc" className={textareaCls} rows={3} maxLength={5000} required value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </div>
                    <PersonPicker label={rt('followUps.owner')} value={owner} onChange={setOwner} help={rt('followUps.ownerHelp')} error={form.errors.owner_user_id} />
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor="fu-priority" className={labelCls}>{rt('followUps.priorityLabel')}</label>
                            <select id="fu-priority" className={inputCls} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                {['low', 'normal', 'high', 'urgent'].map((priority) => <option key={priority} value={priority}>{rt(`followUps.priority.${priority}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="fu-due" className={labelCls}>{rt('followUps.due')}</label>
                            <input id="fu-due" type="date" className={inputCls} required value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                            {form.errors.due_at && <p className="text-xs text-red-700 dark:text-red-400" role="alert">{form.errors.due_at}</p>}
                        </div>
                    </div>
                    <div className="flex gap-2 sm:col-span-2">
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{rt('common.save')}</button>
                        <button type="button" className={secondaryBtn} onClick={() => setAdding(false)}>{rt('common.cancel')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
