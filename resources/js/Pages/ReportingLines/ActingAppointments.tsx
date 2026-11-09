import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { ActingAppointmentRow, LookupEmployee, LookupPosition, PageMeta } from '@/Components/ReportingLines/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { localizedName } from '@/utils/localizedName';
import { Button, FilterField, FilterPanel, FormField, Input, ListPagination, Select, Textarea, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useEffect, useState } from 'react';

type OrganizationOption = { id: string; name_en: string; name_am: string | null };

export default function ActingAppointmentsPage({ organizations, filters, appointments, meta, can }: {
    organizations: OrganizationOption[];
    filters: { organization_id: string | null; status: string };
    appointments: ActingAppointmentRow[];
    meta: PageMeta;
    can: { create: boolean; manage: boolean };
}) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [organizationId, setOrganizationId] = useState(filters.organization_id ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [showForm, setShowForm] = useState(false);
    const [positions, setPositions] = useState<LookupPosition[]>([]);
    const [employeeTerm, setEmployeeTerm] = useState('');
    const [employees, setEmployees] = useState<LookupEmployee[]>([]);
    const form = useForm({ position_id: '', employee_id: '', starts_on: new Date().toISOString().slice(0, 10), ends_on: '', reason: '' });
    const name = (en: string | null, am: string | null): string => (locale === 'am' ? (am ?? en ?? '') : (en ?? ''));

    function submitFilters() {
        router.get(route('reporting-lines.acting.index'), { organization_id: organizationId, status }, { preserveState: true, preserveScroll: true });
    }

    useEffect(() => {
        if (!showForm || !organizationId) return;
        fetch(route('reporting-lines.lookup.positions', { organization_id: organizationId }), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((json: { data: LookupPosition[] }) => setPositions(json.data))
            .catch(() => setPositions([]));
    }, [showForm, organizationId]);

    useEffect(() => {
        if (!showForm || !organizationId) return;
        const handle = window.setTimeout(() => {
            fetch(route('reporting-lines.lookup.employees', { organization_id: organizationId, q: employeeTerm }), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((json: { data: LookupEmployee[] }) => setEmployees(json.data))
                .catch(() => setEmployees([]));
        }, 250);
        return () => window.clearTimeout(handle);
    }, [showForm, organizationId, employeeTerm]);

    function save() {
        form.post(route('reporting-lines.acting.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setShowForm(false); } });
    }

    async function revoke(id: string) {
        const { confirmed, reason } = await confirm({
            title: t('reportingLines.revokeTitle'),
            description: t('reportingLines.revokeDescription'),
            confirmLabel: t('reportingLines.revoke'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
            requireReason: true,
            reasonLabel: t('reportingLines.revokeReasonLabel'),
        });
        if (confirmed) router.post(route('reporting-lines.acting.revoke', id), { reason: reason ?? '' }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader backHref={route('reporting-lines.overview', { organization_id: organizationId })} title={t('reportingLines.actingAppointments')} description={t('reportingLines.actingDescription')} actions={can.create ? <Button type="button" onClick={() => setShowForm((v) => !v)}>{t('reportingLines.newActingAppointment')}</Button> : undefined} />}>
            <Head title={t('reportingLines.actingAppointments')} />
            <div className="space-y-6">
                <FilterPanel onSubmit={submitFilters} hasActiveFilters={status !== ''} onReset={() => { setStatus(''); router.get(route('reporting-lines.acting.index'), { organization_id: organizationId }); }}>
                    <FilterField label={t('reportingLines.organization')} wide>
                        <Select value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{localizedName(o.name_en, o.name_am, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('common.status')}>
                        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="active">{t('reportingLines.active')}</option>
                            <option value="revoked">{t('reportingLines.revoked')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                {showForm && can.create && (
                    <form className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5" onSubmit={(e) => { e.preventDefault(); save(); }}>
                        <h3 className="mb-4 text-sm font-semibold text-(--app-foreground)">{t('reportingLines.newActingAppointment')}</h3>
                        <div className="grid gap-4 md:grid-cols-2">
                            <FormField label={t('reportingLines.position')} error={form.errors.position_id} required>
                                <Select value={form.data.position_id} onChange={(e) => form.setData('position_id', e.target.value)} required>
                                    <option value="">—</option>
                                    {positions.map((p) => (
                                        <option key={p.id} value={p.id}>{localizedName(p.title_en ?? '', p.title_am, locale)}{p.unit_name_en ? ` · ${localizedName(p.unit_name_en, p.unit_name_am, locale)}` : ''}{p.is_unit_head ? ` (${t('positions.unitHead')})` : ''}</option>
                                    ))}
                                </Select>
                            </FormField>
                            <FormField label={t('reportingLines.employee')} error={form.errors.employee_id} required>
                                <Input type="search" value={employeeTerm} placeholder={t('reportingLines.searchEmployee')} onChange={(e) => setEmployeeTerm(e.target.value)} />
                                <Select className="mt-2" value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)} required>
                                    <option value="">—</option>
                                    {employees.map((em) => <option key={em.id} value={em.id}>{name(em.full_name, em.name_am)} · {em.employee_number}{em.position_title_en ? ` · ${localizedName(em.position_title_en, em.position_title_am, locale)}` : ''}</option>)}
                                </Select>
                            </FormField>
                            <FormField label={t('reportingLines.startsOn')} error={form.errors.starts_on} required>
                                <Input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} required />
                            </FormField>
                            <FormField label={t('reportingLines.endsOn')} error={form.errors.ends_on}>
                                <Input type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                            </FormField>
                            <FormField label={t('reportingLines.reason')} error={form.errors.reason} className="md:col-span-2">
                                <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} rows={2} />
                            </FormField>
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button type="submit" disabled={form.processing}>{t('reportingLines.create')}</Button>
                            <Button type="button" variant="ghost" onClick={() => setShowForm(false)}>{t('confirmations.cancel')}</Button>
                        </div>
                    </form>
                )}

                <section className={tableContainerClassName}>
                    {appointments.length === 0 ? (
                        <EmptyState title={t('reportingLines.noActingAppointments')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        {[t('reportingLines.position'), t('reportingLines.actingEmployee'), t('reportingLines.period'), t('reportingLines.reason'), t('common.status'), ''].map((h, i) => (
                                            <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {appointments.map((a) => (
                                        <tr key={a.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-medium')}>
                                                {localizedName(a.position_title_en ?? '', a.position_title_am, locale)}
                                                {a.unit_name_en && <div className="text-xs font-normal text-(--app-muted-foreground)">{localizedName(a.unit_name_en, a.unit_name_am, locale)}</div>}
                                            </td>
                                            <td className={tableCellClassName}>
                                                <Link href={route('reporting-lines.employees.show', a.employee_id)} className="text-primary hover:underline">{name(a.employee_name, a.employee_name_am)}</Link>
                                                <span className="ml-2 text-xs text-(--app-muted-foreground)">{a.employee_number}</span>
                                            </td>
                                            <td className={tableCellClassName}>
                                                {a.starts_on && <LocalizedDateDisplay value={a.starts_on} />} – {a.ends_on ? <LocalizedDateDisplay value={a.ends_on} /> : t('reportingLines.openEnded')}
                                            </td>
                                            <td className={cx(tableCellClassName, 'max-w-xs text-xs text-(--app-muted-foreground)')}>
                                                {a.reason}
                                                {a.status === 'revoked' && <div className="mt-1">{t('reportingLines.revokedBy')}: {a.revoked_by} · {a.revocation_reason}</div>}
                                            </td>
                                            <td className={tableCellClassName}><span className={badgeClassName(a.in_force ? 'success' : a.status === 'revoked' ? 'danger' : 'neutral')}>{a.in_force ? t('reportingLines.inForce') : a.status === 'active' ? t('reportingLines.notInForce') : a.status_label}</span></td>
                                            <td className={tableCellClassName}>
                                                {can.manage && a.status === 'active' && (
                                                    <button type="button" onClick={() => revoke(a.id)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>{t('reportingLines.revoke')}</button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <ListPagination meta={meta} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
