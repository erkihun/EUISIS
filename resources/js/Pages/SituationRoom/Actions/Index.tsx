import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { FilterField, FilterPanel, Input, Select, StatusBadge, TBody, THead, Table, TableCard, Td, Th, Tr, buttonClassName, type Tone } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import ListPagination, { type LaravelPageMeta } from '@/Components/ui/ListPagination';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { useLocale } from '@/hooks/useLocale';
import type { SrLabels } from '@/types/situationRoom';
import type { SituationActionRow, SituationActionOptions } from './types';

type Props = {
    labels: SrLabels;
    actions: { data: SituationActionRow[] } & LaravelPageMeta;
    filters: { status?: string; category?: string; search?: string };
    options: SituationActionOptions;
    can: { manage: boolean };
};

const STATUS_TONE: Record<string, Tone> = { open: 'info', in_progress: 'warning', done: 'success', cancelled: 'neutral' };
const PRIORITY_TONE: Record<string, Tone> = { low: 'neutral', normal: 'info', high: 'warning', urgent: 'danger' };

/** The Executive Action Register: actions of organizations in the viewer's scope, open ones first, soonest due first. */
export default function SituationActionsIndex({ actions, filters, options, can }: Props) {
    const { sr, label, locale } = useSituationLabels();
    const { t } = useLocale();
    const system = useCalendarSystem();
    const [draft, setDraft] = useState(filters);
    const apply = () => router.get(route('situation-room.actions.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveScroll: true });
    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={sr('actions.title')} description={sr('actions.intro')} backHref={route('situation-room.index')} actions={can.manage ? <Link href={route('situation-room.actions.create')} className={buttonClassName({ variant: 'primary' })}>{sr('actions.new')}</Link> : undefined} />}>
            <Head title={sr('actions.title')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={apply} onReset={() => router.get(route('situation-room.actions.index'))} hasActiveFilters={hasFilters} label={sr('ui.filters')} submitLabel={sr('ui.apply')} resetLabel={sr('ui.reset')}>
                    <FilterField label={sr('actions.search')} htmlFor="sa-search" wide>
                        <Input id="sa-search" value={draft.search ?? ''} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} maxLength={100} />
                    </FilterField>
                    <FilterField label={sr('actions.fields.status')} htmlFor="sa-status">
                        <Select id="sa-status" value={draft.status ?? ''} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
                            <option value="">{sr('actions.all_statuses')}</option>
                            {options.statuses.map((s) => <option key={s} value={s}>{sr(`values.action_status.${s}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={sr('actions.fields.category')} htmlFor="sa-category">
                        <Select id="sa-category" value={draft.category ?? ''} onChange={(e) => setDraft((d) => ({ ...d, category: e.target.value }))}>
                            <option value="">{sr('ui.all_categories')}</option>
                            {options.categories.map((c) => <option key={c} value={c}>{sr(`categories.${c}.title`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <TableCard footer={<ListPagination meta={actions} bare />}>
                    {actions.data.length === 0 ? (
                        <p className="p-6 text-sm text-[color:var(--app-muted-foreground)]">{sr('actions.empty')}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{sr('columns.reference')}</Th>
                                        <Th>{sr('columns.title')}</Th>
                                        <Th>{sr('columns.organization')}</Th>
                                        <Th>{sr('columns.priority')}</Th>
                                        <Th>{sr('actions.fields.status')}</Th>
                                        <Th>{sr('columns.due_date')}</Th>
                                        {can.manage && <Th><span className="sr-only">{sr('ui.open')}</span></Th>}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {actions.data.map((a) => (
                                        <Tr key={a.id}>
                                            <Td className="whitespace-nowrap font-mono text-xs">{a.reference_number}</Td>
                                            <Td>
                                                <span className="font-medium">{a.title}</span>
                                                <span className="block text-xs text-[color:var(--app-muted-foreground)]">{sr(`categories.${a.category}.title`)}</span>
                                            </Td>
                                            <Td>{label(a.organization)}</Td>
                                            <Td><StatusBadge tone={PRIORITY_TONE[a.priority] ?? 'neutral'}>{sr(`values.priority.${a.priority}`)}</StatusBadge></Td>
                                            <Td><StatusBadge tone={STATUS_TONE[a.status] ?? 'neutral'}>{sr(`values.action_status.${a.status}`)}</StatusBadge></Td>
                                            <Td className="whitespace-nowrap">
                                                {a.due_date ? formatDateDisplay(a.due_date, system, locale) : '—'}
                                                {a.overdue && <StatusBadge tone="danger" className="ml-2">{sr('actions.overdue')}</StatusBadge>}
                                            </Td>
                                            {can.manage && (
                                                <Td align="right">
                                                    <AppActionMenu items={[{ label: t('common.edit'), href: route('situation-room.actions.edit', a.id) }]} />
                                                </Td>
                                            )}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    )}
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
