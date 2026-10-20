import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { SeverityBadge } from '@/Components/intelligence/Badges';
import { FilterChips, IncidentStatusBadge } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Card, ListPagination, TBody, THead, Table, TableCard, Td, Th, Tr } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { IncidentSummary, Paginated } from '@/types/intelligenceSystem';

type Props = {
    labels: SrLabels;
    filters: { status: string; severity: string; component: string };
    options: { statuses: string[]; severities: string[]; components: string[] };
    counts: Record<'critical' | 'high' | 'medium' | 'low' | 'info', number>;
    incidents: Paginated<IncidentSummary>;
    can: { manage: boolean; review: boolean; diagnostics: boolean; runbooks: boolean };
};

/**
 * Active incidents first, most severe first. Every row is a measured
 * condition with its evidence one click away; nothing here is an AI verdict.
 */
export default function IncidentsIndex({ filters, options, counts, incidents }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();
    const title = t('intelligence.areas.incidents.title');
    const build = (patch: Partial<typeof filters>) => route('intelligence.incidents.index', Object.fromEntries(Object.entries({ ...filters, ...patch }).filter(([, v]) => v !== '')));

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('intelligence.areas.incidents.description')} backHref={route('intelligence.index')} />}>
            <Head title={`${title} · ${t('intelligence.title')}`} />

            <div className="space-y-5">
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {(['critical', 'high', 'medium', 'low'] as const).map((severity) => (
                        <li key={severity}>
                            <Card className="flex items-center justify-between gap-3 p-4">
                                <SeverityBadge severity={severity} />
                                <span className="text-2xl font-semibold tabular-nums text-(--app-foreground)">{counts[severity]}</span>
                            </Card>
                        </li>
                    ))}
                </ul>

                <div className="space-y-2">
                    <FilterChips
                        label={t('intelligence.incidents.status')}
                        value={filters.status}
                        options={['active', ...options.statuses].map((key) => ({ key, label: key === 'active' ? t('intelligence.incidents.active') : sr(`intelligence.status.${key}`) }))}
                        build={(key) => build({ status: key })}
                    />
                    <FilterChips
                        label={t('intelligence.incidents.severity')}
                        value={filters.severity}
                        options={[{ key: '', label: t('intelligence.incidents.all') }, ...options.severities.map((key) => ({ key, label: sr(`intelligence.severity.${key}`) }))]}
                        build={(key) => build({ severity: key })}
                    />
                </div>

                <TableCard title={t('intelligence.incidents.list')} footer={<ListPagination meta={incidents} />}>
                    {incidents.data.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{t('intelligence.incidents.none')}</p>
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('intelligence.incidents.reference')}</Th>
                                    <Th>{t('intelligence.incidents.incident')}</Th>
                                    <Th>{t('intelligence.incidents.severity')}</Th>
                                    <Th>{t('intelligence.incidents.status')}</Th>
                                    <Th>{t('intelligence.incidents.component')}</Th>
                                    <Th align="right">{t('intelligence.incidents.occurrences')}</Th>
                                    <Th>{t('intelligence.incidents.lastSeen')}</Th>
                                    <Th>{t('intelligence.incidents.owner')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {incidents.data.map((incident) => (
                                    <Tr key={incident.id}>
                                        <Td><Link href={incident.href} className="font-medium text-primary hover:underline">{incident.reference}</Link></Td>
                                        <Td>
                                            <span className="block font-medium text-(--app-foreground)">{sr(incident.title, incident.params as Record<string, string | number>)}</span>
                                            {incident.endpoint && <span className="block text-xs text-(--app-muted-foreground)">{incident.endpoint}</span>}
                                        </Td>
                                        <Td><SeverityBadge severity={incident.severity} /></Td>
                                        <Td><IncidentStatusBadge status={incident.status} /></Td>
                                        <Td>{sr(`intelligence.components.${incident.component}`)}</Td>
                                        <Td numeric>{incident.occurrences}</Td>
                                        <Td muted><LocalizedDateDisplay value={incident.last_seen_at} withTime /></Td>
                                        <Td muted>{incident.owner ?? '—'}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </TableCard>
            </div>
        </AuthenticatedLayout>
    );
}
