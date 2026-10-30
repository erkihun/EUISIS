import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppActionMenu, Button, ListPagination, Table, TableCard, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import type { Period } from '@/Components/serviceMonitoring/PeriodForm';
import { nameOf } from '@/Components/serviceMonitoring/helpers';
import type { Paginated } from '@/Components/serviceMonitoring/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

/** Service Delivery Monitoring › Reporting Periods. */
export default function ServiceMonitoringPeriodsIndex({ periods }: { periods: Paginated<Period & { submissions_count: number }> }): JSX.Element {
    const { t, locale } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceMonitoring.periods.title')} description={t('serviceMonitoring.periods.description')}
            actions={<Button as={Link} href={route('service-monitoring.periods.create')} variant="primary">{t('serviceMonitoring.actions.newPeriod')}</Button>} />}>
            <Head title={t('serviceMonitoring.periods.title')} />
            <TableCard footer={<ListPagination meta={periods} />}>
                <div className="overflow-x-auto">
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('serviceMonitoring.periods.nameAm')}</Th>
                                <Th>{t('serviceMonitoring.periods.fiscalYear')}</Th>
                                <Th>{t('serviceMonitoring.periods.periodType')}</Th>
                                <Th>{t('serviceMonitoring.periods.dueDate')}</Th>
                                <Th>{t('serviceMonitoring.periods.status')}</Th>
                                <Th align="right">{t('serviceMonitoring.periods.reports')}</Th>
                                <Th><span className="sr-only">{t('common.actions')}</span></Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {periods.data.length === 0 && <Tr><Td colSpan={7} muted>{t('serviceMonitoring.periods.empty')}</Td></Tr>}
                            {periods.data.map((period) => (
                                <Tr key={period.id}>
                                    <Td className="font-medium">{nameOf(period, locale)}</Td>
                                    <Td numeric>{period.fiscal_year}</Td>
                                    <Td>{t(`serviceMonitoring.periodTypes.${period.period_type}`)}</Td>
                                    <Td muted><LocalizedDateDisplay value={period.due_date} /></Td>
                                    <Td><StatusBadge status={period.status} label={t(`serviceMonitoring.periodStatus.${period.status}`)} /></Td>
                                    <Td numeric>{period.submissions_count}</Td>
                                    <Td align="right"><AppActionMenu items={[{ label: t('common.edit'), href: route('service-monitoring.periods.edit', period.id) }]} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </div>
            </TableCard>
        </AuthenticatedLayout>
    );
}
