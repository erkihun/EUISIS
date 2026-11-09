import type { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { FilterChips } from '@/Components/intelligence/StatusBadges';
import { Button, Card, CardTitle, FormField, Input, ListPagination, Select, TBody, THead, Table, Td, Textarea, Th, Tr, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { SUPPORT_PRIORITY_TONE, SUPPORT_STATUS_TONE } from '@/types/support';
import type { SupportPaginated, SupportTicketCategory, SupportTicketPriority, SupportTicketRow, SupportTicketStatus } from '@/types/support';

type Props = {
    filters: { status: string; category: string };
    options: { statuses: SupportTicketStatus[]; categories: SupportTicketCategory[]; priorities: SupportTicketPriority[] };
    tickets: SupportPaginated<SupportTicketRow>;
    can: { view_all: boolean; manage: boolean };
};


/** Own tickets for everyone; the whole queue for `support-tickets.view-all`. Filing is a plain form on this page. */
export default function SupportTicketsIndex({ filters, options, tickets, can }: PageProps<Props>) {
    const { t } = useLocale();
    const title = t('support.title');
    const build = (patch: Partial<typeof filters>) => route('support.tickets.index', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v !== '')));

    const form = useForm({ title: '', description: '', category: 'other' as SupportTicketCategory, priority: 'normal' as SupportTicketPriority });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('support.tickets.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={can.view_all ? t('support.index.descriptionAll') : t('support.index.description')} />}>
            <Head title={title} />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div className="space-y-5">
                    <div className="space-y-2">
                        <FilterChips label={t('support.index.filterStatus')} value={filters.status}
                            options={[{ key: 'active', label: t('support.index.active') }, { key: 'all', label: t('support.index.all') }, ...options.statuses.map((key) => ({ key, label: t(`support.statuses.${key}`) }))]}
                            build={(key) => build({ status: key })} />
                        <FilterChips label={t('support.index.filterCategory')} value={filters.category}
                            options={[{ key: '', label: t('support.index.all') }, ...options.categories.map((key) => ({ key, label: t(`support.categories.${key}`) }))]}
                            build={(key) => build({ category: key })} />
                    </div>

                    {tickets.data.length === 0 ? (
                        <Card><p className="text-sm text-(--app-muted-foreground)">{t('support.index.none')}</p></Card>
                    ) : (
                        <Card className="p-0">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('support.index.reference')}</Th>
                                        <Th>{t('support.index.ticket')}</Th>
                                        <Th>{t('support.index.category')}</Th>
                                        <Th>{t('support.index.priority')}</Th>
                                        <Th>{t('support.index.status')}</Th>
                                        {can.view_all && <Th>{t('support.index.reporter')}</Th>}
                                        <Th>{t('support.index.created')}</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {tickets.data.map((ticket) => (
                                        <Tr key={ticket.id}>
                                            <Td><Link href={route('support.tickets.show', ticket.id)} className="font-mono text-xs font-medium text-primary hover:underline">{ticket.reference}</Link></Td>
                                            <Td>
                                                <span className="block font-medium text-(--app-foreground)">{ticket.title}</span>
                                                {(ticket.assigned_team || ticket.assignee) && <span className="block text-xs text-(--app-muted-foreground)">{[ticket.assigned_team, ticket.assignee].filter(Boolean).join(' · ')}</span>}
                                            </Td>
                                            <Td muted>{t(`support.categories.${ticket.category}`)}</Td>
                                            <Td><span className={badgeClassName(SUPPORT_PRIORITY_TONE[ticket.priority])}>{t(`support.priorities.${ticket.priority}`)}</span></Td>
                                            <Td><span className={badgeClassName(SUPPORT_STATUS_TONE[ticket.status])}>{t(`support.statuses.${ticket.status}`)}</span></Td>
                                            {can.view_all && <Td muted>{ticket.reporter ?? '—'}</Td>}
                                            <Td muted><LocalizedDateDisplay value={ticket.created_at} withTime /></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                            <ListPagination meta={tickets} />
                        </Card>
                    )}
                </div>

                <Card className="space-y-4 self-start">
                    <div>
                        <CardTitle>{t('support.index.newTicket')}</CardTitle>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('support.index.newTicketHint')}</p>
                    </div>
                    <form onSubmit={submit} className="space-y-4">
                        <FormField id="ticket-description" label={t('support.form.description')} required error={form.errors.description}>
                            {({ id }) => <Textarea id={id} rows={5} maxLength={4000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />}
                        </FormField>
                        <FormField id="ticket-category" label={t('support.form.category')} error={form.errors.category}>
                            {({ id }) => (
                                <Select id={id} value={form.data.category} onChange={(e) => form.setData('category', e.target.value as SupportTicketCategory)}>
                                    {options.categories.map((c) => <option key={c} value={c}>{t(`support.categories.${c}`)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="ticket-priority" label={t('support.form.priority')} error={form.errors.priority}>
                            {({ id }) => (
                                <Select id={id} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value as SupportTicketPriority)}>
                                    {options.priorities.map((p) => <option key={p} value={p}>{t(`support.priorities.${p}`)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField id="ticket-title" label={t('support.form.title')} description={t('support.form.titleHint')} error={form.errors.title}>
                            {({ id }) => <Input id={id} maxLength={190} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />}
                        </FormField>
                        <Button type="submit" loading={form.processing}>{t('support.index.submit')}</Button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
