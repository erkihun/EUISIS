import { Link, router } from '@inertiajs/react';
import { Card, EmptyState, FilterField, FilterPanel, Input, Pagination, Select, StatusBadge, TableCard, buttonClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableBodyClassName, tableRowClassName, tableCellClassName } from '@euisis/ui';
import { useState } from 'react';
import { uiMeta, useTransportLabels } from './labels';
import type { Paginated, ProviderOption, ReportBucket, ReportFilters, TransportReport, TransportTransaction } from '@/types/transport';

/** Date window (and, on the admin pages, provider and status) filters, applied on Filter or Enter. */
export function TransportFilters({ routeName, filters, providers, withStatus = true, exportHref }: {
    routeName: string;
    filters: ReportFilters;
    providers?: ProviderOption[];
    withStatus?: boolean;
    exportHref?: string;
}) {
    const { t, name, label } = useTransportLabels();
    const [draft, setDraft] = useState({ date_from: filters.date_from, date_to: filters.date_to, provider_id: filters.provider_id ?? '', status: filters.status ?? '' });

    function apply(next = draft) {
        const query = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== null));
        router.get(route(routeName), query, { preserveScroll: true, preserveState: true });
    }

    return (
        <FilterPanel
            onSubmit={() => apply()}
            onReset={() => apply({ date_from: '', date_to: '', provider_id: '', status: '' })}
            hasActiveFilters={Boolean(filters.provider_id || filters.status)}
            actions={exportHref ? <a href={exportHref} className={buttonClassName({ variant: 'outline' })}>{t('transport.export')}</a> : undefined}
        >
            <FilterField label={t('transport.dateFrom')}><Input type="date" value={draft.date_from} onChange={(e) => setDraft({ ...draft, date_from: e.target.value })} /></FilterField>
            <FilterField label={t('transport.dateTo')}><Input type="date" value={draft.date_to} onChange={(e) => setDraft({ ...draft, date_to: e.target.value })} /></FilterField>
            {providers && (
                <FilterField label={t('transport.provider')}>
                    <Select value={draft.provider_id} onChange={(e) => setDraft({ ...draft, provider_id: e.target.value })}>
                        <option value="">{t('transport.all')}</option>
                        {providers.map((p) => <option key={p.id} value={p.id}>{p.provider_code} — {name(p)}</option>)}
                    </Select>
                </FilterField>
            )}
            {withStatus && (
                <FilterField label={t('transport.status')}>
                    <Select value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                        <option value="">{t('transport.all')}</option>
                        <option value="accepted">{label('statuses', 'accepted')}</option>
                        <option value="rejected">{label('statuses', 'rejected')}</option>
                    </Select>
                </FilterField>
            )}
        </FilterPanel>
    );
}

/** Boarding scans, newest first; the admin list adds the provider column. */
export function TransactionsTable({ transactions, routeName, showProvider = false, showHref }: {
    transactions: Paginated<TransportTransaction>;
    routeName: string;
    showProvider?: boolean;
    showHref?: (transaction: TransportTransaction) => string;
}) {
    const { t, name, label, tone, locale } = useTransportLabels();
    const rows = transactions.data;
    const time = (value: string | null) => (value ? new Date(value).toLocaleString(locale === 'am' ? 'am-ET' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

    return (
        <TableCard footer={<Pagination meta={uiMeta(transactions.meta)} onPageChange={(page) => router.get(route(routeName), { ...Object.fromEntries(new URLSearchParams(window.location.search)), page }, { preserveScroll: true, preserveState: true })} />}>
            {rows.length === 0 ? <EmptyState title={t('transport.noBoardings')} /> : (
                <div className="relative overflow-x-auto">
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={tableHeaderCellClassName}>{t('transport.scannedAt')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.employee')}</th>
                                {showProvider && <th className={tableHeaderCellClassName}>{t('transport.provider')}</th>}
                                <th className={tableHeaderCellClassName}>{t('transport.route')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.status')}</th>
                                <th className={tableHeaderCellClassName}>{t('transport.result')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {rows.map((row) => (
                                <tr key={row.id} className={tableRowClassName}>
                                    <td className={`${tableCellClassName} whitespace-nowrap`}>{showHref ? <Link href={showHref(row)} className="underline-offset-2 hover:underline">{time(row.scanned_at)}</Link> : time(row.scanned_at)}</td>
                                    <td className={tableCellClassName}>
                                        <div className="font-medium">{row.employee?.full_name ?? '—'}</div>
                                        <div className="text-xs text-(--app-muted-foreground)">{row.employee?.employee_number}{row.employee?.organization ? ` · ${locale === 'am' && row.employee.organization_am ? row.employee.organization_am : row.employee.organization}` : ''}</div>
                                    </td>
                                    {showProvider && <td className={tableCellClassName}>{row.provider ? name(row.provider) : '—'}</td>}
                                    <td className={tableCellClassName}>{row.route ? `${row.route.route_code} — ${name(row.route)}` : '—'}{row.trip ? <div className="text-xs text-(--app-muted-foreground)">{row.trip.trip_number}</div> : null}</td>
                                    <td className={tableCellClassName}><StatusBadge tone={tone(row.status)}>{label('statuses', row.status)}</StatusBadge></td>
                                    <td className={tableCellClassName}>{label('reasons', row.result_code)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </TableCard>
    );
}

function Stat({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
    return (
        <Card className="p-4">
            <p className="text-xs font-medium text-(--app-muted-foreground)">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{hint}</p>}
        </Card>
    );
}

function BucketTable({ title, rows, codeKey }: { title: string; rows: ReportBucket[]; codeKey: 'route_code' | 'provider_code' }) {
    const { t, name, label } = useTransportLabels();
    if (rows.length === 0) return null;
    return (
        <TableCard title={title}>
            <div className="relative overflow-x-auto">
                <table className={tableClassName}>
                    <thead className={tableHeadClassName}>
                        <tr>
                            <th className={tableHeaderCellClassName}>{codeKey === 'route_code' ? t('transport.route') : t('transport.provider')}</th>
                            <th className={`${tableHeaderCellClassName} text-right`}>{label('statuses', 'accepted')}</th>
                            <th className={`${tableHeaderCellClassName} text-right`}>{label('statuses', 'rejected')}</th>
                        </tr>
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {rows.map((row) => (
                            <tr key={row.id} className={tableRowClassName}>
                                <td className={tableCellClassName}>{row[codeKey]} — {name(row)}</td>
                                <td className={`${tableCellClassName} text-right tabular-nums`}>{row.accepted}</td>
                                <td className={`${tableCellClassName} text-right tabular-nums`}>{row.rejected}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </TableCard>
    );
}

/** The boarding report shared by the admin and portal report pages. */
export function ReportView({ report, transactionsHref }: { report: TransportReport; transactionsHref?: string }) {
    const { t, label } = useTransportLabels();
    const { summary } = report;
    const rate = summary.total > 0 ? `${Math.round((summary.accepted / summary.total) * 100)}%` : '—';
    const peak = Math.max(1, ...report.daily.map((d) => d.accepted + d.rejected));

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label={t('transport.reportTotal')} value={summary.total} />
                <Stat label={label('statuses', 'accepted')} value={summary.accepted} hint={rate} />
                <Stat label={label('statuses', 'rejected')} value={summary.rejected} />
                <Stat label={t('transport.riders')} value={summary.riders} />
                <Stat label={t('transport.payAsYouGo')} value={summary.pay_as_you_go} />
            </div>

            {summary.total === 0 ? <Card><EmptyState title={t('transport.noBoardings')} /></Card> : (
                <>
                    <TableCard title={t('transport.perDay')} actions={transactionsHref ? <Link href={transactionsHref} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('transport.seeAllBoardings')}</Link> : undefined}>
                        <ul className="divide-y divide-(--app-border)">
                            {report.daily.map((day) => (
                                <li key={day.date} className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 px-4 py-2 text-sm">
                                    <span className="tabular-nums text-(--app-muted-foreground)">{day.date}</span>
                                    <span className="flex h-2.5 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                                        <span className="bg-emerald-600" style={{ width: `${(day.accepted / peak) * 100}%` }} />
                                        <span className="bg-red-500" style={{ width: `${(day.rejected / peak) * 100}%` }} />
                                    </span>
                                    <span className="tabular-nums">{day.accepted} / {day.rejected}</span>
                                </li>
                            ))}
                        </ul>
                    </TableCard>
                    <div className="grid gap-6 lg:grid-cols-2">
                        <BucketTable title={t('transport.byProvider')} rows={report.byProvider} codeKey="provider_code" />
                        <BucketTable title={t('transport.byRoute')} rows={report.byRoute} codeKey="route_code" />
                        {report.rejections.length > 0 && (
                            <TableCard title={t('transport.topRejections')}>
                                <ul className="divide-y divide-(--app-border)">
                                    {report.rejections.map((r) => (
                                        <li key={r.code} className="flex items-center justify-between gap-3 px-4 py-2 text-sm"><span>{label('reasons', r.code)}</span><span className="tabular-nums">{r.count}</span></li>
                                    ))}
                                </ul>
                            </TableCard>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
