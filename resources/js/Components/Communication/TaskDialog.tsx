import { useEffect, useState, type FormEvent, type JSX } from 'react';
import { Button } from '@euisis/ui';
import Modal from '@/Components/Modal';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import { dayKey } from '@/lib/communication/format';
import { bodyForEditing } from '@/Components/Communication/MessageText';
import Avatar from '@/Components/Communication/Avatar';
import { SearchIcon } from '@/Components/NavIcons';
import type { ConversationDetail, ConversationMemberRow, MessageItem, TaskPriority } from '@/types/communication';

const PRIORITIES: TaskPriority[] = ['LOW', 'NORMAL', 'HIGH', 'URGENT'];

/** Turn a message into a task for someone in the same conversation. */
export default function TaskDialog({ message, detail, onClose }: { message: MessageItem | null; detail: ConversationDetail | null; onClose: () => void }): JSX.Element {
    const { t } = useLocale();
    const [title, setTitle] = useState('');
    const [notes, setNotes] = useState('');
    const [assignee, setAssignee] = useState<number | null>(null);
    const [priority, setPriority] = useState<TaskPriority>('NORMAL');
    const [due, setDue] = useState('');
    const [q, setQ] = useState('');
    const [people, setPeople] = useState<ConversationMemberRow[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!message) return;
        setTitle('');
        setNotes('');
        setAssignee(null);
        setPriority('NORMAL');
        setDue('');
        setQ('');
        setError(null);
    }, [message]);

    // Small conversations list everyone; large ones are searched.
    useEffect(() => {
        if (!message || !detail) return;
        if (detail.members_complete) { setPeople(detail.members); return; }
        const timer = window.setTimeout(() => {
            communicationApi.members(detail.id, { q: q.trim().length >= 2 ? q.trim() : null }).then((page) => setPeople(page.data)).catch(() => setPeople([]));
        }, q ? 250 : 0);
        return () => window.clearTimeout(timer);
    }, [message, detail, q]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!message || assignee === null) return;
        setSaving(true);
        setError(null);
        try {
            await communicationApi.createTask(message.id, {
                assigned_to: assignee, title: title.trim() || null, description: notes.trim() || null, priority,
                due_at: due ? `${due} 17:00:00` : null,
            });
            toast.success(t('communication.tasks.create.done'));
            onClose();
        } catch (reason) {
            setError(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    const field = 'w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15';
    const preview = message?.body ? bodyForEditing(message).text : '';
    const visible = detail?.members_complete && q.trim().length >= 2
        ? people.filter((p) => p.name.toLowerCase().includes(q.trim().toLowerCase()))
        : people;

    return (
        <Modal show={message !== null} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="flex max-h-[85vh] flex-col">
                <div className="border-b border-[color:var(--app-border)] px-5 py-4">
                    <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('communication.tasks.create.title')}</h2>
                    {preview && <p className="mt-1 line-clamp-2 text-xs text-[color:var(--app-muted-foreground)]">“{preview}”</p>}
                </div>
                <div className="comm-scroll min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.tasks.create.taskTitle')}</span>
                        <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} placeholder={preview.slice(0, 120)} className={`${field} h-9`} />
                        <span className="block text-[11px] text-[color:var(--app-muted-foreground)]">{t('communication.tasks.create.taskTitleHelp')}</span>
                    </label>

                    <fieldset className="space-y-1.5">
                        <legend className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.tasks.create.assignee')}</legend>
                        <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{t('communication.tasks.create.assigneeHelp')}</p>
                        <label className="relative block">
                            <span className="sr-only">{t('communication.tasks.create.searchMembers')}</span>
                            <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                            <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('communication.tasks.create.searchMembers')} className={`${field} h-8 ps-8 text-xs`} />
                        </label>
                        <ul className="comm-scroll max-h-48 overflow-y-auto rounded-control border border-[color:var(--app-border)]">
                            {visible.map((person) => (
                                <li key={person.id}>
                                    <label className={`flex cursor-pointer items-center gap-2.5 px-2.5 py-1.5 ${assignee === person.id ? 'bg-[color:color-mix(in_srgb,var(--color-primary)_10%,var(--app-surface))]' : 'hover:bg-[color:var(--app-surface-muted)]'}`}>
                                        <input type="radio" name="assignee" checked={assignee === person.id} onChange={() => setAssignee(person.id)} className="h-4 w-4 text-[color:var(--color-primary)]" />
                                        <Avatar name={person.name} userId={person.id} hasPhoto={person.has_photo} size="sm" />
                                        <span className="min-w-0 flex-1 truncate text-sm text-[color:var(--app-foreground)]">{person.name}</span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="block space-y-1">
                            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.tasks.create.priority')}</span>
                            <select value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)} className={`${field} h-9`}>
                                {PRIORITIES.map((p) => <option key={p} value={p}>{t(`communication.tasks.priorities.${p}`)}</option>)}
                            </select>
                        </label>
                        <div className="space-y-1">
                            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.tasks.create.due')}</span>
                            <LocalizedDatePicker value={due} onChange={setDue} min={dayKey(new Date().toISOString())} />
                        </div>
                    </div>

                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.tasks.create.notes')}</span>
                        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} rows={3} className={`${field} resize-none py-2`} />
                    </label>
                    {error && <p role="alert" className="text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
                </div>
                <div className="flex justify-end gap-2 border-t border-[color:var(--app-border)] px-5 py-3">
                    <button type="button" onClick={onClose} className="h-9 rounded-control px-3 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">{t('common.cancel')}</button>
                    <Button type="submit" variant="primary" disabled={saving || assignee === null} loading={saving}>
                        {t('communication.tasks.create.submit')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
