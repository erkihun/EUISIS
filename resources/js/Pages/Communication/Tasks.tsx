import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import { dateTime, tr } from '@/lib/communication/format';
import { ChatIcon, TaskIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { CommunicationTaskItem, TaskPriority, TaskStatus } from '@/types/communication';

type Props = PageProps<{ initial: { data: CommunicationTaskItem[] }; focus: string | null }>;
type View = 'assigned' | 'created';
type StatusFilter = 'open' | 'done' | 'all';

const PRIORITY_TONE: Record<TaskPriority, string> = {
    LOW: 'text-[color:var(--app-muted-foreground)]',
    NORMAL: 'text-[color:var(--app-muted-foreground)]',
    HIGH: 'text-amber-700 dark:text-amber-300',
    URGENT: 'text-[color:var(--color-danger,#b42318)]',
};

export default function Tasks({ initial, focus }: Props): JSX.Element {
    const { t } = useLocale();
    const [view, setView] = useState<View>('assigned');
    const [status, setStatus] = useState<StatusFilter>('open');
    const [rows, setRows] = useState(initial.data);
    const [loading, setLoading] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) { first.current = false; return; }
        setLoading(true);
        communicationApi.tasks(view, status).then(setRows).catch(() => undefined).finally(() => setLoading(false));
    }, [view, status]);

    useEffect(() => {
        if (focus) document.getElementById(`task-${focus}`)?.scrollIntoView({ block: 'center' });
    }, [focus]);

    const replace = (task: CommunicationTaskItem) => setRows((current) => current.map((x) => (x.id === task.id ? task : x)));

    return (
        <AuthenticatedLayout header={<PageHeader title={t('communication.tasks.title')} description={t('communication.tasks.description')} backHref={route('communication.index')} />}>
            <Head title={t('communication.tasks.title')} />
            <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] p-3 sm:flex-row sm:items-center">
                    <div role="tablist" aria-label={t('communication.tasks.title')} className="flex rounded-control bg-[color:var(--app-surface-muted)] p-0.5">
                        {(['assigned', 'created'] as const).map((value) => (
                            <button key={value} type="button" role="tab" aria-selected={view === value} onClick={() => setView(value)}
                                className={`h-8 rounded-[calc(var(--radius-control)-2px)] px-3 text-xs font-medium ${view === value ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm' : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]'}`}>
                                {t(`communication.tasks.views.${value}`)}
                            </button>
                        ))}
                    </div>
                    <div className="flex gap-1 sm:ms-auto">
                        {(['open', 'done', 'all'] as const).map((value) => (
                            <button key={value} type="button" aria-pressed={status === value} onClick={() => setStatus(value)}
                                className={`h-7 rounded-full px-3 text-xs font-medium ${status === value ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'}`}>
                                {t(`communication.tasks.statusFilter.${value}`)}
                            </button>
                        ))}
                    </div>
                </div>
                <div aria-busy={loading}>
                    {rows.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                            <TaskIcon className="h-6 w-6 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                            <p className="max-w-sm text-sm text-[color:var(--app-muted-foreground)]">{status === 'done' ? t('communication.tasks.emptyDone') : t('communication.tasks.empty')}</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {rows.map((task) => <Row key={task.id} task={task} view={view} focused={task.id === focus} onChanged={replace} />)}
                        </ul>
                    )}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Row({ task, view, focused, onChanged }: { task: CommunicationTaskItem; view: View; focused: boolean; onChanged: (task: CommunicationTaskItem) => void }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [busy, setBusy] = useState(false);
    const open = task.status === 'TODO' || task.status === 'IN_PROGRESS';
    const overdue = open && task.due_at !== null && new Date(task.due_at).getTime() < Date.now();

    const move = async (payload: Record<string, unknown>) => {
        setBusy(true);
        try {
            onChanged(await communicationApi.updateTask(task.id, payload));
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setBusy(false);
        }
    };
    const next: [TaskStatus, string][] = task.status === 'TODO' ? [['IN_PROGRESS', t('communication.tasks.start')], ['COMPLETED', t('communication.tasks.complete')]]
        : task.status === 'IN_PROGRESS' ? [['COMPLETED', t('communication.tasks.complete')]]
            : task.status === 'COMPLETED' ? [['TODO', t('communication.tasks.reopen')]] : [];

    return (
        <li id={`task-${task.id}`} className={`flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start ${focused ? 'comm-flash' : ''}`}>
            <div className="min-w-0 flex-1">
                <p className={`text-sm font-medium ${task.status === 'COMPLETED' || task.status === 'CANCELLED' ? 'text-[color:var(--app-muted-foreground)] line-through decoration-1' : 'text-[color:var(--app-foreground)]'}`}>{task.title}</p>
                {task.description && <p className="mt-0.5 line-clamp-2 text-xs text-[color:var(--app-muted-foreground)]">{task.description}</p>}
                <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[color:var(--app-muted-foreground)]">
                    <span className="font-medium text-[color:var(--app-foreground)]">{t(`communication.tasks.statuses.${task.status}`)}</span>
                    {task.priority !== 'NORMAL' && <span className={`font-medium ${PRIORITY_TONE[task.priority]}`}>{t(`communication.tasks.priorities.${task.priority}`)}</span>}
                    {task.due_at && <span className={overdue ? 'font-semibold text-[color:var(--color-danger,#b42318)]' : ''}>{overdue ? `${t('communication.tasks.overdue')} · ` : ''}{tr(t('communication.tasks.due'), { date: dateTime(task.due_at, locale, system) })}</span>}
                    <span>{view === 'assigned' ? tr(t('communication.tasks.from'), { name: task.creator?.name ?? '—' }) : tr(t('communication.tasks.to'), { name: task.assignee?.name ?? '—' })}</span>
                    {task.source && (
                        <Link href={`${route('communication.messages.show', task.source.conversation_id)}${task.source.message_id ? `?message=${task.source.message_id}` : ''}`}
                            className="inline-flex items-center gap-1 font-medium text-[color:var(--color-primary)] hover:underline">
                            <ChatIcon className="h-3.5 w-3.5" aria-hidden="true" />{task.source.title ? `${task.source.title} — ` : ''}{t('communication.tasks.openMessage')}
                        </Link>
                    )}
                </p>
            </div>
            <div className="flex shrink-0 flex-wrap gap-2">
                {task.can.updateStatus && next.map(([status, label]) => (
                    <button key={status} type="button" disabled={busy} onClick={() => void move({ status })}
                        className={buttonClassName({ variant: status === 'COMPLETED' ? 'primary' : 'secondary', size: 'sm' })}>{label}</button>
                ))}
                {task.can.manage && open && (
                    <button type="button" disabled={busy} onClick={() => void move({ status: 'CANCELLED' })} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('communication.tasks.cancel')}</button>
                )}
            </div>
        </li>
    );
}
