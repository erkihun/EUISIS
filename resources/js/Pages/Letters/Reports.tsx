import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import PageHeader from '@/Components/PageHeader';
import { inputCls, labelCls, mutedCls, panelCls, primaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { AppMetricCard, TBody, Table, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Named, OrganizationOption } from '@/types/letters';
import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    filters: { from: string; to: string; organization_id: string };
    organizations: OrganizationOption[];
    totals: { by_direction: Record<string, number>; by_status: Record<string, number>; overdue: number; avg_days_to_close: number | null; closed: number; on_time_rate: number | null; escalated: number };
    by_institution: { organization: Named; incoming: number; outgoing: number; internal: number; open: number; closed: number }[];
    by_month: { month: string; incoming: number; outgoing: number; internal: number }[];
    routing_by_unit: { unit: Named | null; open: number; overdue: number }[];
}

/** Counts and handling times for the institutions in scope. Counts only. */
export default function Reports({ filters, organizations, totals, by_institution, by_month, routing_by_unit }: Props): JSX.Element {
    const { lt, name, pick } = useLetters();
    const [form, setForm] = useState(filters);
    const total = Object.values(totals.by_direction).reduce((a, b) => a + b, 0);

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get(route('letters.reports'), Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('reports.title')} description={lt('reports.description')} backHref={route('letters.dashboard')} />}>
            <Head title={lt('reports.title')} />
            <div className="space-y-5">
                <form onSubmit={apply} className={cx(panelCls, 'grid gap-3 p-4 sm:grid-cols-4')} aria-label={lt('common.filter')}>
                    <div>
                        <label htmlFor="rp-from" className={labelCls}>{lt('common.from')}</label>
                        <LocalizedDatePicker id="rp-from" className={inputCls} value={form.from} onChange={(iso) => setForm({ ...form, from: iso })} />
                    </div>
                    <div>
                        <label htmlFor="rp-to" className={labelCls}>{lt('common.to')}</label>
                        <LocalizedDatePicker id="rp-to" className={inputCls} value={form.to} onChange={(iso) => setForm({ ...form, to: iso })} />
                    </div>
                    {organizations.length > 1 && (
                        <div>
                            <label htmlFor="rp-org" className={labelCls}>{lt('common.institution')}</label>
                            <select id="rp-org" className={inputCls} value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })}>
                                <option value="">{lt('common.all')}</option>
                                {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                            </select>
                        </div>
                    )}
                    <div className="flex items-end"><button type="submit" className={primaryBtn}>{lt('reports.apply')}</button></div>
                </form>

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6" aria-label={lt('reports.title')}>
                    <AppMetricCard label={lt('reports.total')} value={total} variant="primary" />
                    <AppMetricCard label={lt('direction.incoming')} value={totals.by_direction.incoming ?? 0} variant="neutral" />
                    <AppMetricCard label={lt('direction.outgoing')} value={totals.by_direction.outgoing ?? 0} variant="neutral" />
                    <AppMetricCard label={lt('reports.overdue')} value={totals.overdue} variant={totals.overdue > 0 ? 'danger' : 'neutral'} detail={lt('reports.escalated', { count: totals.escalated })} />
                    <AppMetricCard label={lt('reports.avgDays')} value={totals.avg_days_to_close ?? '—'} variant="success" detail={lt('reports.closed', { count: totals.closed })} />
                    <AppMetricCard label={lt('reports.onTime')} value={totals.on_time_rate === null ? '—' : `${totals.on_time_rate}%`} variant={totals.on_time_rate !== null && totals.on_time_rate < 80 ? 'warning' : 'success'} />
                </section>

                <div className="grid gap-5 lg:grid-cols-2">
                    <section className={cx(panelCls, 'overflow-x-auto')} aria-labelledby="rp-status">
                        <h2 id="rp-status" className={cx(sectionTitleCls, 'p-4 pb-2')}>{lt('reports.byStatus')}</h2>
                        <Table>
                            <THead><Tr><Th>{lt('common.status')}</Th><Th>{lt('reports.total')}</Th></Tr></THead>
                            <TBody>
                                {Object.entries(totals.by_status).map(([status, count]) => <Tr key={status}><Td>{lt(`registrationStatus.${status}`)}</Td><Td>{count}</Td></Tr>)}
                                {Object.keys(totals.by_status).length === 0 && <Tr><Td colSpan={2} className={mutedCls}>{lt('reports.empty')}</Td></Tr>}
                            </TBody>
                        </Table>
                    </section>

                    <section className={cx(panelCls, 'overflow-x-auto')} aria-labelledby="rp-month">
                        <h2 id="rp-month" className={cx(sectionTitleCls, 'p-4 pb-2')}>{lt('reports.byMonth')}</h2>
                        <Table>
                            <THead><Tr><Th>{lt('reports.month')}</Th><Th>{lt('direction.incoming')}</Th><Th>{lt('direction.outgoing')}</Th><Th>{lt('direction.internal')}</Th></Tr></THead>
                            <TBody>
                                {by_month.map((m) => <Tr key={m.month}><Td>{m.month}</Td><Td>{m.incoming}</Td><Td>{m.outgoing}</Td><Td>{m.internal}</Td></Tr>)}
                                {by_month.length === 0 && <Tr><Td colSpan={4} className={mutedCls}>{lt('reports.empty')}</Td></Tr>}
                            </TBody>
                        </Table>
                    </section>
                </div>

                <section className={cx(panelCls, 'overflow-x-auto')} aria-labelledby="rp-inst">
                    <h2 id="rp-inst" className={cx(sectionTitleCls, 'p-4 pb-2')}>{lt('reports.byInstitution')}</h2>
                    <Table>
                        <THead><Tr><Th>{lt('common.institution')}</Th><Th>{lt('direction.incoming')}</Th><Th>{lt('direction.outgoing')}</Th><Th>{lt('direction.internal')}</Th><Th>{lt('reports.open')}</Th><Th>{lt('registrationStatus.closed')}</Th></Tr></THead>
                        <TBody>
                            {by_institution.map((r, i) => <Tr key={i}><Td>{name(r.organization)}</Td><Td>{r.incoming}</Td><Td>{r.outgoing}</Td><Td>{r.internal}</Td><Td>{r.open}</Td><Td>{r.closed}</Td></Tr>)}
                            {by_institution.length === 0 && <Tr><Td colSpan={6} className={mutedCls}>{lt('reports.empty')}</Td></Tr>}
                        </TBody>
                    </Table>
                </section>

                <section className={cx(panelCls, 'overflow-x-auto')} aria-labelledby="rp-units">
                    <h2 id="rp-units" className={cx(sectionTitleCls, 'p-4 pb-2')}>{lt('reports.routingByUnit')}</h2>
                    <Table>
                        <THead><Tr><Th>{lt('reports.unit')}</Th><Th>{lt('reports.open')}</Th><Th>{lt('reports.overdue')}</Th></Tr></THead>
                        <TBody>
                            {routing_by_unit.map((r, i) => <Tr key={i}><Td>{r.unit ? name(r.unit) : lt('reports.noUnit')}</Td><Td>{r.open}</Td><Td className={cx(r.overdue > 0 && 'font-semibold text-red-700 dark:text-red-400')}>{r.overdue}</Td></Tr>)}
                            {routing_by_unit.length === 0 && <Tr><Td colSpan={3} className={mutedCls}>{lt('reports.empty')}</Td></Tr>}
                        </TBody>
                    </Table>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
