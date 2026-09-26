import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head } from '@inertiajs/react';
import { Card } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { ActionButton, Money, StatusPill, useNames, type NamePair } from '@/Components/Cafeteria/PolicyUi';
import type { SettlementSummary } from './Index';

type Line = { id: string; employee_organization: NamePair; cafeteria: NamePair & { location_type?: string | null }; transaction_count: number; subsidy_amount: string; employee_amount: string; provider_amount: string };
type OrgTotal = { employee_organization: NamePair; transaction_count: number; subsidy_amount: string; employee_amount: string; provider_amount: string };

export default function SettlementShow({ settlement, lines, byOrganization, can }: {
    settlement: SettlementSummary & { notes: string | null; generated_at: string | null; finalized_at: string | null };
    lines: Line[];
    byOrganization: OrgTotal[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const c = settlement.currency_code;

    const Th = ({ children, end = false }: { children: React.ReactNode; end?: boolean }) => <th className={`px-3 py-2 ${end ? 'text-end' : 'text-start'}`}>{children}</th>;
    const Td = ({ children, end = false, strong = false }: { children: React.ReactNode; end?: boolean; strong?: boolean }) =>
        <td className={`px-3 py-2 ${end ? 'text-end' : ''} ${strong ? 'font-medium' : ''}`}>{children}</td>;

    return (
        <AuthenticatedLayout header={<PageHeader title={`${tt('settlement')} ${settlement.settlement_number}`} description={label(settlement.provider)}
            backHref={route('cafeteria.settlements.index')}
            actions={can.manage && settlement.status === 'draft' ? (
                <div className="flex flex-wrap gap-2">
                    <ActionButton primary label={tt('finalize')} description={tt('finalizeWarning')} url={route('cafeteria.settlements.finalize', settlement.id)} />
                    <ActionButton destructive label={tt('cancelSettlement')} url={route('cafeteria.settlements.cancel', settlement.id)} />
                </div>
            ) : undefined} />}>
            <Head title={settlement.settlement_number} />
            <div className="space-y-6">
                <Card className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('period')}</p><p className="font-medium"><LocalizedDateDisplay value={settlement.period_start} /> — <LocalizedDateDisplay value={settlement.period_end} /></p></div>
                    <div><p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('status')}</p><StatusPill status={settlement.status} /></div>
                    <div><p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('subsidyAmount')}</p><p className="font-medium"><Money value={settlement.total_subsidy_amount} currency={c} /></p></div>
                    <div><p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('providerAmount')}</p><p className="text-lg font-semibold"><Money value={settlement.total_provider_amount} currency={c} /></p></div>
                </Card>

                <Card className="overflow-x-auto p-0">
                    <h2 className="px-5 pt-4 text-base font-semibold">{tt('liabilityByOrganization')}</h2>
                    <table className="mt-2 w-full text-sm">
                        <thead className="bg-[color:var(--app-surface-muted)] text-xs"><tr>
                            <Th>{tt('employeeOrganization')}</Th><Th end>{tt('transactions')}</Th><Th end>{tt('subsidyAmount')}</Th><Th end>{tt('employeeAmount')}</Th><Th end>{tt('providerAmount')}</Th>
                        </tr></thead>
                        <tbody>
                            {byOrganization.map((row, index) => (
                                <tr key={index} className="border-t border-[color:var(--app-border)]">
                                    <Td strong>{label(row.employee_organization, tt('unattributed'))}</Td>
                                    <Td end>{row.transaction_count}</Td>
                                    <Td end><Money value={row.subsidy_amount} currency={c} /></Td>
                                    <Td end><Money value={row.employee_amount} currency={c} /></Td>
                                    <Td end strong><Money value={row.provider_amount} currency={c} /></Td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>

                <Card className="overflow-x-auto p-0">
                    <h2 className="px-5 pt-4 text-base font-semibold">{tt('serviceLocation')}</h2>
                    <table className="mt-2 w-full text-sm">
                        <thead className="bg-[color:var(--app-surface-muted)] text-xs"><tr>
                            <Th>{tt('employeeOrganization')}</Th><Th>{tt('cafeteria')}</Th><Th end>{tt('transactions')}</Th><Th end>{tt('subsidyAmount')}</Th><Th end>{tt('employeeAmount')}</Th><Th end>{tt('providerAmount')}</Th>
                        </tr></thead>
                        <tbody>
                            {lines.map((line) => (
                                <tr key={line.id} className="border-t border-[color:var(--app-border)]">
                                    <Td>{label(line.employee_organization, tt('unattributed'))}</Td>
                                    <Td>{label(line.cafeteria)}{line.cafeteria?.location_type && <span className="ms-1 text-xs text-[color:var(--app-muted-foreground)]">{t(`cafeteriaPolicy.locationTypes.${line.cafeteria.location_type}`)}</span>}</Td>
                                    <Td end>{line.transaction_count}</Td>
                                    <Td end><Money value={line.subsidy_amount} currency={c} /></Td>
                                    <Td end><Money value={line.employee_amount} currency={c} /></Td>
                                    <Td end strong><Money value={line.provider_amount} currency={c} /></Td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                {settlement.notes && <p className="text-sm text-[color:var(--app-muted-foreground)]">{settlement.notes}</p>}
            </div>
        </AuthenticatedLayout>
    );
}
