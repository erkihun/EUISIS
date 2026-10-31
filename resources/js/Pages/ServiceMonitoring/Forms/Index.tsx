import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppActionMenu, AppFilterBar, Button, FilterField, Input, ListPagination, Select, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { nameOf } from '@/Components/serviceMonitoring/builder/helpers';
import type { Named, Paginated } from '@/Components/serviceMonitoring/builder/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

type Status = 'draft' | 'published' | 'superseded' | 'archived';

type TemplateRow = {
    id: string;
    code: string;
    version: number;
    name_am: string;
    name_en: string | null;
    status: Status;
    updated_at: string | null;
    organization_types: Named[];
    submissions_count: number;
};

const STATUSES: Status[] = ['draft', 'published', 'superseded', 'archived'];

/** Service Delivery Monitoring › Form templates: every version of every template. */
export default function FormTemplatesIndex({ templates, filters }: { templates: Paginated<TemplateRow>; filters: { search?: string; status?: string } }): JSX.Element {
    const { t, locale } = useLocale();
    const tt = (key: string) => t(`serviceMonitoring.templates.${key}`);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('title')} description={tt('description')}
            actions={<Button as={Link} href={route('service-monitoring.forms.create')} variant="primary">{tt('actions.create')}</Button>} />}>
            <Head title={tt('title')} />
            <div className="space-y-6">
                <AppFilterBar routeName="service-monitoring.forms.index" filters={filters}>
                    <FilterField label={tt('search')} wide>
                        <Input name="search" type="search" defaultValue={filters.search ?? ''} />
                    </FilterField>
                    <FilterField label={tt('status')}>
                        <Select name="status" defaultValue={filters.status ?? ''}>
                            <option value="">{tt('allStatuses')}</option>
                            {STATUSES.map((status) => <option key={status} value={status}>{tt(`statuses.${status}`)}</option>)}
                        </Select>
                    </FilterField>
                </AppFilterBar>

                <TableCard footer={<ListPagination meta={templates} />}>
                    <div className="overflow-x-auto">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{tt('nameAm')}</Th>
                                    <Th>{tt('code')}</Th>
                                    <Th>{tt('version')}</Th>
                                    <Th>{tt('organizationTypes')}</Th>
                                    <Th>{tt('status')}</Th>
                                    <Th align="right">{tt('reports')}</Th>
                                    <Th>{tt('updated')}</Th>
                                    <Th><span className="sr-only">{t('common.actions')}</span></Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {templates.data.length === 0 && <Tr><Td colSpan={8} muted>{tt('empty')}</Td></Tr>}
                                {templates.data.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><Link href={route('service-monitoring.forms.show', row.id)} className="font-medium text-(--color-primary) hover:underline">{nameOf(row, locale)}</Link></Td>
                                        <Td muted>{row.code}</Td>
                                        <Td numeric>{row.version}</Td>
                                        <Td>{row.organization_types.length === 0 ? <span className="text-(--app-muted-foreground)">{tt('noTypes')}</span> : row.organization_types.map((type) => nameOf(type, locale)).join('، ')}</Td>
                                        <Td><StatusBadge status={row.status} label={tt(`statuses.${row.status}`)} /></Td>
                                        <Td numeric>{row.submissions_count}</Td>
                                        <Td muted><LocalizedDateDisplay value={row.updated_at} /></Td>
                                        <Td align="right">
                                            <AppActionMenu items={[
                                                { label: t('common.view'), href: route('service-monitoring.forms.show', row.id) },
                                                { label: t('common.edit'), href: route('service-monitoring.forms.edit', row.id), show: row.status === 'draft' },
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
