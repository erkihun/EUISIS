import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { SeverityBadge } from '@/Components/intelligence/Badges';
import { IncidentStatusBadge } from '@/Components/intelligence/StatusBadges';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import { Alert, Card, CardTitle, TBody, THead, Table, Td, Th, Tr, badgeClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { SrLabels } from '@/types/situationRoom';
import type { IncidentStatus, Runbook } from '@/types/intelligenceSystem';
import type { InsightSeverity } from '@/types/intelligence';

type Props = {
    labels: SrLabels;
    runbook: Runbook;
    incidents: Array<{ id: string; reference: string; severity: InsightSeverity; status: IncidentStatus; component: string; last_seen_at: string; href: string }>;
    execution_enabled: boolean;
};

export default function RunbookShow({ runbook, incidents, execution_enabled }: PageProps<Props>) {
    const { t } = useLocale();
    const { sr } = useSituationLabels();

    return (
        <AuthenticatedLayout header={<PageHeader title={sr(runbook.title)} description={sr(runbook.summary)} backHref={route('intelligence.runbooks.index')} />}>
            <Head title={`${sr(runbook.title)} · ${t('intelligence.title')}`} />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <Card className="p-0">
                    <div className="border-b border-(--app-border) px-(--card-padding) py-3"><CardTitle>{t('intelligence.runbooks.steps')}</CardTitle></div>
                    <ol className="divide-y divide-(--app-border)">
                        {runbook.steps.map((step, index) => (
                            <li key={step} className="flex gap-4 px-(--card-padding) py-3 text-sm">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 font-semibold text-primary">{index + 1}</span>
                                <p className="text-(--app-foreground)">{sr(step)}</p>
                            </li>
                        ))}
                    </ol>
                    {!execution_enabled && <div className="border-t border-(--app-border) px-(--card-padding) py-3"><Alert tone="info">{t('intelligence.runbooks.readOnly')}</Alert></div>}
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardTitle>{t('intelligence.runbooks.covers')}</CardTitle>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {runbook.rules.map((rule) => <span key={rule} className={badgeClassName('neutral')}>{rule}</span>)}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {runbook.components.map((c) => <span key={c} className={badgeClassName('info')}>{sr(`intelligence.components.${c}`)}</span>)}
                        </div>
                    </Card>
                    <Card className="p-0">
                        <div className="border-b border-(--app-border) px-(--card-padding) py-3"><CardTitle>{t('intelligence.runbooks.activeIncidentsTitle')}</CardTitle></div>
                        {incidents.length === 0 ? (
                            <p className="px-(--card-padding) py-4 text-sm text-(--app-muted-foreground)">{t('intelligence.runbooks.noActive')}</p>
                        ) : (
                            <Table>
                                <THead><Tr><Th>{t('intelligence.incidents.reference')}</Th><Th>{t('intelligence.incidents.severity')}</Th><Th>{t('intelligence.incidents.status')}</Th><Th>{t('intelligence.incidents.lastSeen')}</Th></Tr></THead>
                                <TBody>
                                    {incidents.map((i) => (
                                        <Tr key={i.id}>
                                            <Td><Link href={i.href} className="font-medium text-primary hover:underline">{i.reference}</Link></Td>
                                            <Td><SeverityBadge severity={i.severity} /></Td>
                                            <Td><IncidentStatusBadge status={i.status} /></Td>
                                            <Td muted><LocalizedDateDisplay value={i.last_seen_at} withTime /></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
