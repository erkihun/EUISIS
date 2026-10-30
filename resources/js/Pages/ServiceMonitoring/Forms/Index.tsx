import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppActionMenu, AppFilterBar, Button, FilterField, Input, ListPagination, Select, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { Named, Paginated } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

type FormRow = {
    id: string;
    code: string;
    version: number;
    name_am: string;
    name_en: string | null;
    status: 'draft' | 'published' | 'superseded' | 'archived';
    updated_at: string | null;
    organization_types: Named[];
    submissions_count: number;
};

const STATUSES = ['draft', 'published', 'superseded', 'archived'] as const;

/** Service Delivery Monitoring › Monitoring Forms: every version of every form. */
export default function ServiceMonitoringFormsIndex({ forms, filters }: { forms: Paginated<FormRow>; filters: { search?: string; status?: string } }): JSX.Element {
    const { t, locale } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceMonitoring.forms.title')} description={t('serviceMonitoring.forms.description')}
            actions={<Button as={Link} href={route('service-monitoring.forms.create')} variant="primary">{t('serviceMonitoring.actions.create')}</Button>} />}>
            <Head title={t('serviceMonitoring.forms.title')} />
            <div className="space-y-6">
                <AppFilterBar routeName="service-monitoring.forms.index" filters={filters}>
                    <FilterField label={t('serviceMonitoring.forms.search')} wide>
                        <Input name="search" type="search" defaultValue={filters.search ?? ''} />
                    </FilterField>
                    <FilterField label={t('serviceMonitoring.forms.status')}>
                        <Select name="status" defaultValue={filters.status ?? ''}>
                            <option value="">{t('serviceMonitoring.forms.allStatuses')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{t(`serviceMonitoring.formStatus.${status}`)}</option>)}
                        </Select>
                    </FilterField>
                </AppFilterBar>

                <TableCard footer={<ListPagination meta={forms} />}>
                    <div className="overflow-x-auto">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceMonitoring.forms.nameAm')}</Th>
                                    <Th>{t('serviceMonitoring.forms.code')}</Th>
                                    <Th>{t('serviceMonitoring.forms.version')}</Th>
                                    <Th>{t('serviceMonitoring.forms.organizationTypes')}</Th>
                                    <Th>{t('serviceMonitoring.forms.status')}</Th>
                                    <Th align="right">{t('serviceMonitoring.forms.reports')}</Th>
                                    <Th>{t('serviceMonitoring.forms.updated')}</Th>
                                    <Th><span className="sr-only">{t('common.actions')}</span></Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {forms.data.length === 0 && <Tr><Td colSpan={8} muted>{t('serviceMonitoring.forms.empty')}</Td></Tr>}
                                {forms.data.map((form) => (
                                    <Tr key={form.id}>
                                        <Td><Link href={route('service-monitoring.forms.show', form.id)} className="font-medium text-(--color-primary) hover:underline">{nameOf(form, locale)}</Link></Td>
                                        <Td muted>{form.code}</Td>
                                        <Td numeric>{form.version}</Td>
                                        <Td>{form.organization_types.length === 0 ? <span className="text-(--app-muted-foreground)">{t('serviceMonitoring.forms.noTypes')}</span> : form.organization_types.map((type) => nameOf(type, locale)).join('، ')}</Td>
                                        <Td><StatusBadge status={form.status} label={t(`serviceMonitoring.formStatus.${form.status}`)} /></Td>
                                        <Td numeric>{form.submissions_count}</Td>
                                        <Td muted><LocalizedDateDisplay value={form.updated_at} /></Td>
                                        <Td align="right">
                                            <AppActionMenu items={[
                                                { label: t('common.view'), href: route('service-monitoring.forms.show', form.id) },
                                                { label: t('common.edit'), href: route('service-monitoring.forms.edit', form.id), show: form.status === 'draft' },
                                            ]} />
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
