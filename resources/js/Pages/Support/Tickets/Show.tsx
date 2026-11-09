import type { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, CardTitle, FormField, Input, Select, Textarea, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SupportContextKey, SupportTicketDetail, SupportTicketEvent, SupportTicketStatus } from '@/types/support';
import { SUPPORT_PRIORITY_TONE, SUPPORT_STATUS_TONE } from '@/types/support';

type Props = {
    ticket: SupportTicketDetail;
    events: SupportTicketEvent[];
    options: { statuses: SupportTicketStatus[] };
    assignees: Array<{ id: number; name: string }>;
    can: { manage: boolean };
};

const CONTEXT_ORDER: SupportContextKey[] = ['route', 'path', 'module', 'locale', 'browser', 'os', 'device', 'viewport', 'error_reference', 'incident_reference', 'timestamp'];

function Section({ title, children, aside }: { title: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <Card className="p-0">
            <div className="flex items-center justify-between gap-3 border-b border-(--app-border) px-(--card-padding) py-3">
                <CardTitle>{title}</CardTitle>
                {aside}
            </div>
            <div className="p-(--card-padding)">{children}</div>
        </Card>
    );
}

/** One ticket: summary, the reporter's description, the safe context, the timeline and — for managers — status, assignment and notes. */
export default function SupportTicketShow({ ticket, events, options, assignees, can }: PageProps<Props>) {
    const { t } = useLocale();
    const title = `${ticket.reference} · ${ticket.title}`;
    const context = CONTEXT_ORDER.filter((key) => ticket.context[key]);

    const form = useForm({ status: ticket.status, assigned_user_id: ticket.assigned_user_id === null ? '' : String(ticket.assigned_user_id), assigned_team: ticket.assigned_team ?? '', note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(route('support.tickets.update', ticket.id), { preserveScroll: true, onSuccess: () => form.setData('note', '') });
    };

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={title}
                    backHref={route('support.tickets.index')}
                    actions={
                        <div className="flex flex-wrap gap-1.5">
                            <span className={badgeClassName(SUPPORT_PRIORITY_TONE[ticket.priority])}>{t(`support.priorities.${ticket.priority}`)}</span>
                            <span className={badgeClassName(SUPPORT_STATUS_TONE[ticket.status])}>{t(`support.statuses.${ticket.status}`)}</span>
                        </div>
                    }
                />
            }
        >
            <Head title={`${ticket.reference} · ${t('support.title')}`} />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <Section title={t('support.show.summary')}>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                            {[
                                [t('support.index.category'), t(`support.categories.${ticket.category}`)],
                                [t('support.show.reporter'), ticket.reporter ?? '—'],
                                [t('support.show.team'), ticket.assigned_team ?? '—'],
                                [t('support.show.assignee'), ticket.assignee ?? t('support.show.unassigned')],
                                [t('support.show.module'), ticket.module ?? '—'],
                                [t('support.show.errorReference'), ticket.error_reference ?? '—'],
                            ].map(([label, value]) => (
                                <div key={label}><dt className="text-(--app-muted-foreground)">{label}</dt><dd className="font-medium text-(--app-foreground)">{value}</dd></div>
                            ))}
                            {([['created_at', 'created'], ['updated_at', 'updated'], ['resolved_at', 'resolved'], ['closed_at', 'closed']] as const).map(([field, key]) => (
                                <div key={field}><dt className="text-(--app-muted-foreground)">{t(`support.show.${key}`)}</dt><dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={ticket[field]} withTime /></dd></div>
                            ))}
                            {ticket.incident && (
                                <div><dt className="text-(--app-muted-foreground)">{t('support.show.incident')}</dt><dd className="font-mono text-xs font-medium text-(--app-foreground)">{ticket.incident.reference} · {ticket.incident.severity} · {ticket.incident.status}</dd></div>
                            )}
                        </dl>
                    </Section>

                    <Section title={t('support.show.description')}>
                        <p className="whitespace-pre-line text-sm text-(--app-foreground)">{ticket.description}</p>
                    </Section>

                    <Section title={t('support.show.context')}>
                        <p className="mb-3 text-xs text-(--app-muted-foreground)">{t('support.show.contextHint')}</p>
                        {context.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{t('support.show.noContext')}</p>
                        ) : (
                            <dl className="divide-y divide-(--app-border) rounded-control border border-(--app-border) text-sm">
                                {context.map((key) => (
                                    <div key={key} className="flex items-baseline justify-between gap-3 px-3 py-1.5">
                                        <dt className="text-(--app-muted-foreground)">{t(`support.context.${key}`)}</dt>
                                        <dd className="min-w-0 truncate font-mono text-xs font-medium text-(--app-foreground)">{ticket.context[key]}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </Section>
                </div>

                <div className="space-y-5">
                    {can.manage && (
                        <Section title={t('support.show.manage')}>
                            <form onSubmit={submit} className="space-y-4">
                                <FormField id="ticket-status" label={t('support.form.status')} error={form.errors.status}>
                                    {({ id }) => (
                                        <Select id={id} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as SupportTicketStatus)}>
                                            {options.statuses.map((s) => <option key={s} value={s}>{t(`support.statuses.${s}`)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField id="ticket-assignee" label={t('support.form.assignee')} error={form.errors.assigned_user_id}>
                                    {({ id }) => (
                                        <Select id={id} value={form.data.assigned_user_id} onChange={(e) => form.setData('assigned_user_id', e.target.value)}>
                                            <option value="">{t('support.show.unassigned')}</option>
                                            {assignees.map((a) => <option key={a.id} value={String(a.id)}>{a.name}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField id="ticket-team" label={t('support.form.team')} error={form.errors.assigned_team}>
                                    {({ id }) => <Input id={id} maxLength={60} value={form.data.assigned_team} onChange={(e) => form.setData('assigned_team', e.target.value)} />}
                                </FormField>
                                <FormField id="ticket-note" label={t('support.form.note')} description={t('support.form.noteHint')} error={form.errors.note}>
                                    {({ id }) => <Textarea id={id} rows={4} maxLength={4000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />}
                                </FormField>
                                <Button type="submit" loading={form.processing}>{t('support.show.save')}</Button>
                            </form>
                        </Section>
                    )}

                    <Section title={t('support.show.timeline')}>
                        <ol className="space-y-3 text-sm">
                            {events.map((event) => (
                                <li key={event.id} className="flex gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-(--app-foreground)">{t(`support.events.${event.event}`)}</span>
                                            {event.event === 'status_changed' && event.to && <span className={badgeClassName(SUPPORT_STATUS_TONE[event.to as SupportTicketStatus] ?? 'neutral')}>{t(`support.statuses.${event.to}`)}</span>}
                                            {event.event === 'assigned' && <span className={badgeClassName('neutral')}>{event.to ?? t('support.show.unassigned')}</span>}
                                        </div>
                                        <div className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={event.occurred_at} withTime />{` · ${event.actor ?? t('support.show.system')}`}</div>
                                        {event.note && <p className="mt-0.5 whitespace-pre-line text-(--app-foreground)">{event.note}</p>}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
