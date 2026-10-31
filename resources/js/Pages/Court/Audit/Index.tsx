import { Head, router } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type LaravelPageMeta,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

export interface AuditEvent {
    id: number;
    source: 'system' | 'case' | 'legacy_model';
    source_label: string;
    occurred_at: string | null;
    action: string | null;
    action_label: string;
    description: string | null;
    actor_type: string;
    actor_name: string | null;
    target_label: string | null;
    outcome: string | null;
    method: string | null;
    route: string | null;
    ip: string | null;
    request_id: string | null;
    response_status: number | null;
    details: Record<string, unknown>;
}

interface Props {
    events: LaravelPageMeta & { data: AuditEvent[] };
    filters: { search?: string; source?: string; outcome?: string; actor_type?: string; method?: string; date_from?: string; date_to?: string };
    options: { sources: string[]; outcomes: string[]; actorTypes: string[]; methods: string[] };
}

const OUTCOME_TONES: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = { success: 'success', failure: 'warning', error: 'danger' };

/** The end-to-end audit trail: system, case and model events in one timeline, filtered and paged. */
export default function AuditIndex({ events, filters, options }: Props) {
    const { t } = useLocale();
    const [form, setForm] = useState({ search: '', source: '', outcome: '', actor_type: '', method: '', date_from: '', date_to: '', ...filters });
    const [open, setOpen] = useState<string | null>(null);
    const set = (key: keyof typeof form) => (value: string) => setForm((current) => ({ ...current, [key]: value }));
    const title = t('court.audit.title');
    const word = (value: string) => value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.admin.audit'), Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')), { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('court.audit.description')} />}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.admin.audit'))} hasActiveFilters={Object.values(filters).some(Boolean)} label={title}>
                    <FilterField label={t('court.audit.search')} className="sm:col-span-2"><Input value={form.search} maxLength={150} onChange={(event) => set('search')(event.target.value)} placeholder={t('court.audit.searchPlaceholder')} /></FilterField>
                    <FilterField label={t('court.audit.source')}>
                        <Select value={form.source} onChange={(event) => set('source')(event.target.value)}>
                            <option value="">{t('court.audit.all')}</option>
                            {options.sources.map((value) => <option key={value} value={value}>{t(`court.audit.sources.${value}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('court.audit.outcome')}>
                        <Select value={form.outcome} onChange={(event) => set('outcome')(event.target.value)}>
                            <option value="">{t('court.audit.all')}</option>
                            {options.outcomes.map((value) => <option key={value} value={value}>{word(value)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('court.audit.actorType')}>
                        <Select value={form.actor_type} onChange={(event) => set('actor_type')(event.target.value)}>
                            <option value="">{t('court.audit.all')}</option>
                            {options.actorTypes.map((value) => <option key={value} value={value}>{word(value)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('court.audit.method')}>
                        <Select value={form.method} onChange={(event) => set('method')(event.target.value)}>
                            <option value="">{t('court.audit.all')}</option>
                            {options.methods.map((value) => <option key={value} value={value}>{value}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('court.audit.dateFrom')}><LocalizedDatePicker value={form.date_from} onChange={set('date_from')} /></FilterField>
                    <FilterField label={t('court.audit.dateTo')}><LocalizedDatePicker value={form.date_to} onChange={set('date_to')} min={form.date_from || undefined} /></FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {['time', 'action', 'actor', 'target', 'request', 'details'].map((key) => (
                                        <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{t(`court.audit.columns.${key}`)}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {events.data.map((event) => {
                                    const key = `${event.source}:${event.id}`;
                                    const details = Object.entries(event.details ?? {});
                                    return (
                                        <Fragment key={key}>
                                            <tr className={tableRowClassName}>
                                                <td className={cx(tableCellClassName, 'whitespace-nowrap align-top')}>
                                                    <div className="text-(--app-foreground)"><LocalizedDateDisplay value={event.occurred_at} withTime /></div>
                                                    <div className="mt-1 text-xs text-(--app-muted-foreground)">{event.source_label}</div>
                                                </td>
                                                <td className={cx(tableCellClassName, 'max-w-sm align-top')}>
                                                    <div className="font-medium text-(--app-foreground)">{event.action_label}</div>
                                                    {event.action && event.action !== event.action_label && <div className="font-mono text-xs text-(--app-muted-foreground)">{event.action}</div>}
                                                    {event.description && <div className="mt-1 text-xs text-(--app-muted-foreground)">{event.description}</div>}
                                                    {event.outcome && <span className={badgeClassName(OUTCOME_TONES[event.outcome] ?? 'neutral', 'mt-1')}>{word(event.outcome)}</span>}
                                                </td>
                                                <td className={cx(tableCellClassName, 'align-top')}>
                                                    <div className="text-(--app-foreground)">{event.actor_name ?? '—'}</div>
                                                    <div className="text-xs text-(--app-muted-foreground)">{word(event.actor_type)}</div>
                                                </td>
                                                <td className={cx(tableCellClassName, 'align-top text-(--app-foreground)')}>{event.target_label ?? '—'}</td>
                                                <td className={cx(tableCellClassName, 'align-top text-xs text-(--app-muted-foreground)')}>
                                                    {event.method && <div><span className="font-mono font-semibold">{event.method}</span> {event.route}</div>}
                                                    {event.response_status && <div>HTTP {event.response_status}</div>}
                                                    {event.ip && <div>{event.ip}</div>}
                                                    {event.request_id && <div className="font-mono">{event.request_id}</div>}
                                                </td>
                                                <td className={cx(tableCellClassName, 'align-top')}>
                                                    {details.length > 0
                                                        ? <button type="button" aria-expanded={open === key} onClick={() => setOpen(open === key ? null : key)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('court.audit.viewDetails')}</button>
                                                        : <span className="text-xs text-(--app-muted-foreground)">{t('court.audit.noDetails')}</span>}
                                                </td>
                                            </tr>
                                            {open === key && (
                                                <tr className="bg-(--app-surface-muted)">
                                                    <td colSpan={6} className="px-4 py-3">
                                                        <dl className="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                                                            {details.map(([name, value]) => (
                                                                <div key={name} className="min-w-0"><dt className="font-mono text-(--app-muted-foreground)">{name}</dt><dd className="break-all text-(--app-foreground)">{typeof value === 'string' ? value : JSON.stringify(value)}</dd></div>
                                                            ))}
                                                        </dl>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {events.data.length === 0 && <EmptyState title={t('court.audit.empty')} />}
                    <ListPagination meta={events} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
