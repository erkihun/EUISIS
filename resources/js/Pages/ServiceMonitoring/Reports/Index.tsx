import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppActionMenu, AppFilterBar, Button, Card, CardDescription, CardHeader, CardTitle, FilterField, FormField, Input, ListPagination, Select, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { SearchableSelect, type SearchableOption } from '@euisis/ui';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { Bilingual, Named, Paginated } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

type ReportStatus = 'draft' | 'submitted' | 'returned' | 'accepted';

type ReportRow = {
    id: string;
    status: ReportStatus;
    form: Bilingual & { id: string; version: number };
    period: Bilingual & { id: string; status: 'open' | 'closed' };
    organization: Bilingual & { id: string; type: Bilingual | null };
    submitted_at: string | null;
    updated_at: string | null;
};

type PeriodOption = Named & { status: 'open' | 'closed' };
type FormOption = Named & { code: string; version: number };

type Props = {
    reports: Paginated<ReportRow>;
    filters: { search?: string; period_id?: string; form_code?: string; status?: string; organization_type_id?: string };
    periods: PeriodOption[];
    openPeriods: PeriodOption[];
    forms: FormOption[];
    organizationTypes: Named[];
    can: { start: boolean };
};

const STATUSES: ReportStatus[] = ['draft', 'submitted', 'returned', 'accepted'];

/**
 * Service Delivery Monitoring › Institution Reports. Lists the reports in the
 * user's scope and opens (or reopens) an institution's report for a form and
 * period. Institutions are offered by the form's organization types only.
 */
export default function ServiceMonitoringReportsIndex({ reports, filters, periods, openPeriods, forms, organizationTypes, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const start = useForm({ period_id: openPeriods[0]?.id ?? '', form_id: forms[0]?.id ?? '', organization_id: '' });

    async function searchInstitutions(query: string, signal: AbortSignal): Promise<SearchableOption[]> {
        if (!start.data.form_id) return [];
        const response = await fetch(route('service-monitoring.reports.organizations', { form_id: start.data.form_id, search: query }), {
            signal, headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });
        if (!response.ok) return [];
        const body = (await response.json()) as { data: Array<Named & { code: string | null }> };
        return body.data.map((organization) => ({ value: organization.id, label: nameOf(organization, locale), description: organization.code ?? undefined }));
    }

    function submitStart(event: FormEvent) {
        event.preventDefault();
        start.post(route('service-monitoring.reports.start'));
    }

    const canStart = can.start && openPeriods.length > 0 && forms.length > 0;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceMonitoring.reports.title')} description={t('serviceMonitoring.reports.description')} />}>
            <Head title={t('serviceMonitoring.reports.title')} />
            <div className="space-y-6">
                {can.start && (
                    <Card className="space-y-4 p-4">
                        <CardHeader className="p-0">
                            <CardTitle>{t('serviceMonitoring.reports.startTitle')}</CardTitle>
                            <CardDescription>
                                {openPeriods.length === 0 ? t('serviceMonitoring.reports.noOpenPeriods') : forms.length === 0 ? t('serviceMonitoring.reports.noForms') : t('serviceMonitoring.reports.startHelp')}
                            </CardDescription>
                        </CardHeader>
                        {canStart && (
                            <form onSubmit={submitStart} className="grid gap-4 md:grid-cols-[1fr_1fr_2fr_auto] md:items-end">
                                <FormField label={t('serviceMonitoring.reports.period')} required error={start.errors.period_id}>
                                    {({ id }) => (
                                        <Select id={id} className="w-full" value={start.data.period_id} onChange={(e) => start.setData('period_id', e.target.value)}>
                                            {openPeriods.map((period) => <option key={period.id} value={period.id}>{nameOf(period, locale)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField label={t('serviceMonitoring.reports.form')} required error={start.errors.form_id}>
                                    {({ id }) => (
                                        <Select id={id} className="w-full" value={start.data.form_id} onChange={(e) => start.setData((data) => ({ ...data, form_id: e.target.value, organization_id: '' }))}>
                                            {forms.map((form) => <option key={form.id} value={form.id}>{nameOf(form, locale)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField label={t('serviceMonitoring.reports.institution')} required error={start.errors.organization_id}>
                                    {({ id }) => (
                                        <SearchableSelect key={start.data.form_id} id={id} immediate label={t('serviceMonitoring.reports.institution')} value={start.data.organization_id}
                                            loadOptions={searchInstitutions} onChange={(value) => start.setData('organization_id', value)}
                                            placeholder={t('serviceMonitoring.reports.institutionSearch')} emptyText={t('serviceMonitoring.reports.noInstitutions')} />
                                    )}
                                </FormField>
                                <Button type="submit" variant="primary" loading={start.processing} disabled={!start.data.organization_id}>{t('serviceMonitoring.actions.start')}</Button>
                            </form>
                        )}
                    </Card>
                )}

                <AppFilterBar routeName="service-monitoring.reports.index" filters={filters}>
                    <FilterField label={t('serviceMonitoring.reports.search')} wide>
                        <Input name="search" type="search" defaultValue={filters.search ?? ''} />
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.reports.period')}>
                        <Select name="period_id" defaultValue={filters.period_id ?? ''}>
                            <option value="">{t('serviceMonitoring.reports.allPeriods')}</option>
                            {periods.map((period) => <option key={period.id} value={period.id}>{nameOf(period, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.reports.form')}>
                        <Select name="form_code" defaultValue={filters.form_code ?? ''}>
                            <option value="">{t('serviceMonitoring.reports.allForms')}</option>
                            {forms.map((form) => <option key={form.code} value={form.code}>{nameOf(form, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.reports.institutionType')}>
                        <Select name="organization_type_id" defaultValue={filters.organization_type_id ?? ''}>
                            <option value="">{t('serviceMonitoring.reports.allTypes')}</option>
                            {organizationTypes.map((type) => <option key={type.id} value={type.id}>{nameOf(type, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.reports.status')}>
                        <Select name="status" defaultValue={filters.status ?? ''}>
                            <option value="">{t('serviceMonitoring.reports.allStatuses')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{t(`serviceMonitoring.reportStatus.${status}`)}</option>)}
                        </Select>
                    </FilterField>
                </AppFilterBar>

                <TableCard footer={<ListPagination meta={reports} />}>
                    <div className="overflow-x-auto">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceMonitoring.reports.institution')}</Th>
                                    <Th>{t('serviceMonitoring.reports.institutionType')}</Th>
                                    <Th>{t('serviceMonitoring.reports.form')}</Th>
                                    <Th>{t('serviceMonitoring.reports.period')}</Th>
                                    <Th>{t('serviceMonitoring.reports.status')}</Th>
                                    <Th>{t('serviceMonitoring.reports.submittedAt')}</Th>
                                    <Th>{t('serviceMonitoring.reports.updated')}</Th>
                                    <Th><span className="sr-only">{t('common.actions')}</span></Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {reports.data.length === 0 && <Tr><Td colSpan={8} muted>{t('serviceMonitoring.reports.empty')}</Td></Tr>}
                                {reports.data.map((report) => {
                                    const editable = (report.status === 'draft' || report.status === 'returned') && report.period.status === 'open';
                                    return (
                                        <Tr key={report.id}>
                                            <Td><Link href={route('service-monitoring.reports.show', report.id)} className="font-medium text-(--color-primary) hover:underline">{nameOf(report.organization, locale)}</Link></Td>
                                            <Td muted>{nameOf(report.organization.type, locale)}</Td>
                                            <Td>{nameOf(report.form, locale)} <span className="text-xs text-(--app-muted-foreground)">v{report.form.version}</span></Td>
                                            <Td>{nameOf(report.period, locale)}</Td>
                                            <Td><StatusBadge status={report.status} label={t(`serviceMonitoring.reportStatus.${report.status}`)} /></Td>
                                            <Td muted><LocalizedDateDisplay value={report.submitted_at} /></Td>
                                            <Td muted><LocalizedDateDisplay value={report.updated_at} /></Td>
                                            <Td align="right">
                                                <AppActionMenu items={[
                                                    { label: t('common.view'), href: route('service-monitoring.reports.show', report.id) },
                                                    { label: t('common.edit'), href: route('service-monitoring.reports.edit', report.id), show: can.start && editable },
                                                ]} />
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </TBody>
                        </Table>
                    </div>
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
