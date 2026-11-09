import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { Card, DataTable, FilterField, FilterPanel, Select, StatusBadge, buttonClassName, createColumnHelper, type ColumnDef } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from '@/Components/transport/labels';
import { ActionDialogButton, DateText, queryWith } from '@/Components/transport/TransportShared';
import type { Paginated, ProviderOption, ServiceCalendarDay, TransportSchedule } from '@/types/transport';

const helper = createColumnHelper<TransportSchedule>();

export default function Index({ schedules, calendarDays, filters, providers, can }: { schedules: Paginated<TransportSchedule>; calendarDays: ServiceCalendarDay[]; filters: Record<string, string | undefined>; providers: ProviderOption[]; can: { create: boolean; update: boolean; plan: boolean } }) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ provider_id: filters.provider_id ?? '' });
    const apply = (next = draft) => router.get(route('transport.schedules.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true });
    const days = (list: number[]) => list.map((d) => t(`transport.weekdaysShort.${d}`)).join(' ');

    const columns = [
        helper.display({ id: 'route', header: t('transport.route'), cell: (c) => <div><div className="font-medium">{c.row.original.route?.route_code} — {name(c.row.original.route)}</div><div className="text-xs text-(--app-muted-foreground)">{name(c.row.original.provider)}</div></div> }),
        helper.accessor('direction', { header: t('transport.direction'), cell: (c) => label('directions', c.getValue()) }),
        helper.accessor('planned_departure', { header: t('transport.departure'), cell: (c) => <span className="tabular-nums">{c.getValue() ?? '—'}{c.row.original.planned_arrival ? ` → ${c.row.original.planned_arrival}` : ''}</span> }),
        helper.accessor('service_days', { header: t('transport.serviceDays'), cell: (c) => <span className="text-xs">{days(c.getValue())}{c.row.original.skips_public_holidays ? '' : ` · ${t('transport.runsOnHolidays')}`}</span> }),
        helper.display({ id: 'vehicle', header: t('transport.defaultVehicle'), cell: (c) => c.row.original.default_vehicle?.plate_number ?? '—' }),
        helper.display({ id: 'validity', header: t('transport.validity'), cell: (c) => <span className="whitespace-nowrap text-xs"><DateText value={c.row.original.effective_from} /> – {c.row.original.effective_to ? <DateText value={c.row.original.effective_to} /> : '…'}</span> }),
        helper.accessor('is_active', { header: t('transport.status'), cell: (c) => <StatusBadge tone={c.getValue() ? 'success' : 'neutral'}>{c.getValue() ? t('transport.active') : t('transport.inactive')}</StatusBadge> }),
        helper.display({ id: 'actions', header: '', cell: (c) => (can.update ? <Link href={route('transport.schedules.edit', c.row.original.id)} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('transport.edit')}</Link> : null) }),
    ] as ColumnDef<TransportSchedule, unknown>[];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.schedules')} description={t('transport.schedulesIntro')} actions={<div className="flex gap-2">
            {can.plan && <ActionDialogButton label={t('transport.planTrips')} title={t('transport.planTrips')} description={t('transport.planTripsHelp')} variant="outline" size="default" url={route('transport.schedules.plan')} fields={[{ name: 'date', label: t('transport.tripDate'), type: 'date', required: true }]} />}
            {can.create && <Link href={route('transport.schedules.create')} className={buttonClassName({ variant: 'primary' })}>{t('transport.newSchedule')}</Link>}
        </div>} />}>
            <Head title={t('transport.schedules')} />
            <div className="space-y-6">
                <FilterPanel onSubmit={() => apply()} onReset={() => apply({ provider_id: '' })} hasActiveFilters={Boolean(filters.provider_id)}>
                    <FilterField label={t('transport.provider')}><Select value={draft.provider_id} onChange={(e) => setDraft({ provider_id: e.target.value })}><option value="">{t('transport.all')}</option>{providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}</Select></FilterField>
                </FilterPanel>
                <DataTable data={schedules.data} columns={columns} emptyTitle={t('transport.noRecords')} pagination={uiMeta(schedules.meta)} onPageChange={(page) => router.get(route('transport.schedules.index'), queryWith({ page }), { preserveState: true })} />
                <Card className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div><h2 className="text-sm font-semibold">{t('transport.serviceCalendar')}</h2><p className="text-sm text-(--app-muted-foreground)">{t('transport.serviceCalendarHelp')}</p></div>
                        {can.update && <ActionDialogButton label={t('transport.addCalendarDay')} title={t('transport.addCalendarDay')} variant="outline" url={route('transport.schedules.calendar-days.store')}
                            fields={[{ name: 'calendar_date', label: t('transport.tripDate'), type: 'date', required: true }, { name: 'kind', label: t('transport.kind'), type: 'select', options: [{ value: 'suspended', label: t('transport.calendarSuspended') }, { value: 'operating', label: t('transport.calendarOperating') }] }, { name: 'provider_id', label: t('transport.provider'), type: 'select', options: [{ value: '', label: t('transport.wholeCity') }, ...providers.map((p) => ({ value: p.id, label: `${p.provider_code} — ${name(p)}` }))] }, { name: 'name_en', label: t('transport.nameEn') }, { name: 'name_am', label: t('transport.nameAm') }]} />}
                    </div>
                    {calendarDays.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{t('transport.noCalendarDays')}</p> : (
                        <ul className="divide-y divide-(--app-border)">
                            {calendarDays.map((day) => (
                                <li key={day.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span><DateText value={day.calendar_date} /> · <StatusBadge tone={day.kind === 'suspended' ? 'danger' : 'success'}>{day.kind === 'suspended' ? t('transport.calendarSuspended') : t('transport.calendarOperating')}</StatusBadge> {name(day)} <span className="text-(--app-muted-foreground)">{day.route ? `${day.route.route_code}` : day.provider ? name(day.provider) : t('transport.wholeCity')}</span></span>
                                    {can.update && <ActionDialogButton label={t('transport.delete')} destructive size="xs" method="delete" url={route('transport.schedules.calendar-days.destroy', day.id)} description={t('transport.removeCalendarDayHelp')} />}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
