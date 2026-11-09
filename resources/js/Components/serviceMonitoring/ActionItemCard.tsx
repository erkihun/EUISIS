import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useForm } from '@inertiajs/react';
import { useId, type FormEvent, type JSX } from 'react';
import type { ActionItemRow, ActionItemStatus } from '@/types/serviceMonitoring';
import { errorCls, inputCls, labelCls, secondaryBtn, useSm } from './helpers';

const STATUSES: ActionItemStatus[] = ['open', 'in_progress', 'done', 'cancelled'];

/** One action item: status, completion note and evidence for those who may update it. */
export default function ActionItemCard({ item }: { item: ActionItemRow }): JSX.Element {
    const { sm, name } = useSm();
    const id = useId();
    const form = useForm({ status: item.status, completion_note: item.completion_note ?? '' });
    const evidence = useForm<{ file: File | null }>({ file: null });

    function update(event: FormEvent) {
        event.preventDefault();
        form.put(route('service-monitoring.action-items.update', item.id), { preserveScroll: true });
    }

    function upload(event: FormEvent) {
        event.preventDefault();
        evidence.post(route('service-monitoring.action-items.evidence', item.id), { forceFormData: true, preserveScroll: true, onSuccess: () => evidence.reset() });
    }

    return (
        <li className={`space-y-2 px-4 py-3 ${item.overdue ? 'bg-red-50 dark:bg-red-950' : ''}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium text-(--app-foreground)">{item.title}</p>
                <p className="text-xs">
                    {sm(`enums.action_item_status.${item.status}`)} · {sm('common.due')} <LocalizedDateDisplay value={item.due_on} />
                    {item.overdue && <strong className="ms-1 text-red-700 dark:text-red-300">{sm('actionItems.overdue')}</strong>}
                </p>
            </div>
            <p className="text-xs text-(--app-muted-foreground)">{name(item.owner_organization)}{item.owner_user ? ` · ${item.owner_user}` : ''}</p>
            {item.completion_note && <p className="text-sm">{item.completion_note}</p>}
            {item.evidence.length > 0 && (
                <ul className="text-xs">{item.evidence.map((e) => <li key={e.id}><a className="underline" href={e.download_url}>{e.original_name}</a></li>)}</ul>
            )}
            {item.can_update && (
                <div className="grid gap-2 sm:grid-cols-2">
                    <form onSubmit={update} className="grid gap-2 sm:grid-cols-2">
                        <div>
                            <label htmlFor={`${id}-st`} className={labelCls}>{sm('common.status')}</label>
                            <select id={`${id}-st`} className={inputCls} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ActionItemStatus)}>
                                {STATUSES.map((s) => <option key={s} value={s}>{sm(`enums.action_item_status.${s}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor={`${id}-note`} className={labelCls}>{sm('actionItems.completionNote')}</label>
                            <input id={`${id}-note`} className={inputCls} value={form.data.completion_note} required={form.data.status === 'done'} onChange={(e) => form.setData('completion_note', e.target.value)} />
                            {form.errors.completion_note && <p className={errorCls}>{form.errors.completion_note}</p>}
                        </div>
                        <button type="submit" className={secondaryBtn} disabled={form.processing}>{sm('actionItems.update')}</button>
                    </form>
                    <form onSubmit={upload} className="flex flex-wrap items-end gap-2">
                        <div>
                            <label htmlFor={`${id}-file`} className={labelCls}>{sm('actionItems.addEvidence')}</label>
                            <input id={`${id}-file`} type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => evidence.setData('file', e.target.files?.[0] ?? null)} />
                        </div>
                        <button type="submit" className={secondaryBtn} disabled={!evidence.data.file || evidence.processing}>{sm('edit.upload')}</button>
                        {evidence.errors.file && <p className={errorCls}>{evidence.errors.file}</p>}
                    </form>
                </div>
            )}
        </li>
    );
}
