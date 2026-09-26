import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { Alert, Button, Card } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { ActionButton, Money, StatusPill, useNames } from '@/Components/Cafeteria/PolicyUi';
import { usageRule, type PolicySummary } from './Index';

type Terms = Record<string, string | number | boolean | null>;
type Side = { id: string; version_no: number; effective_from: string | null; effective_to: string | null; terms: Terms } | null;

export default function PolicyShow({ policy, versions, preview, can }: {
    policy: PolicySummary & { notes: string | null; supersedes: { id: string; version_no: number } | null; cancellation_reason: string | null };
    versions: Array<{ id: string; version_no: number; daily_subsidy_amount: string; effective_from: string | null; effective_to: string | null; status: string }>;
    preview: { current: Side; proposed: Side; changes: Record<string, { from: unknown; to: unknown }>; warnings: string[] };
    can: { edit: boolean; submit: boolean; review: boolean; approve: boolean; activate: boolean; end: boolean; cancel: boolean; newVersion: boolean };
}) {
    const { t } = useLocale();
    const { label } = useNames();
    const tt = (key: string) => t(`cafeteriaPolicy.${key}`);
    const isFinancialLocked = !['draft'].includes(policy.status);
    const show = (value: unknown) => typeof value === 'boolean' ? (value ? tt('yes') : tt('no')) : (value === null || value === '' ? '—' : String(value));

    const Row = ({ name, value }: { name: string; value: React.ReactNode }) => (
        <div className="flex justify-between gap-4 border-b border-[color:var(--app-border)] py-2 text-sm last:border-0">
            <dt className="text-[color:var(--app-muted-foreground)]">{name}</dt>
            <dd className="text-end font-medium">{value}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={`${label(policy.organization)} · v${policy.version_no}`}
            description={`${label(policy.provider)} · ${label(policy.cafeteria ?? policy.network, tt('scopeProvider'))}`}
            backHref={route('cafeteria.policies.index')}
            actions={(
                <div className="flex flex-wrap gap-2">
                    {can.edit && <Button as={Link} href={route('cafeteria.policies.edit', policy.id)} size="sm" variant="outline">{tt('edit')}</Button>}
                    {can.submit && <ActionButton primary label={tt('submitForReview')} url={route('cafeteria.policies.submit', policy.id)} />}
                    {can.review && <ActionButton field="reason" label={tt('returnToDraft')} url={route('cafeteria.policies.return', policy.id)} />}
                    {can.approve && <ActionButton primary label={tt('approve')} url={route('cafeteria.policies.approve', policy.id)} />}
                    {can.activate && <ActionButton label={tt('activate')} url={route('cafeteria.policies.activate', policy.id)} />}
                    {can.newVersion && <Button type="button" size="sm" variant="primary" onClick={() => router.post(route('cafeteria.policies.new-version', policy.id))}>{tt('createNewVersion')}</Button>}
                    {can.end && <ActionButton destructive field="effective_to" label={tt('endPolicy')} url={route('cafeteria.policies.end', policy.id)} />}
                    {can.cancel && <ActionButton destructive field="reason" label={tt('cancelPolicy')} url={route('cafeteria.policies.cancel', policy.id)} />}
                </div>
            )} />}>
            <Head title={`${tt('policy')} v${policy.version_no}`} />
            <div className="space-y-6">
                {isFinancialLocked && <Alert tone="info">{tt('financialLocked')}</Alert>}
                {preview.warnings.length > 0 && (
                    <Alert tone="warning">
                        <ul className="list-inside list-disc">{preview.warnings.map((w) => <li key={w}>{t(`cafeteriaPolicy.warnings.${w}`)}</li>)}</ul>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="p-5">
                        <div className="mb-2 flex items-center justify-between"><h2 className="text-base font-semibold">{tt('policy')}</h2><StatusPill status={policy.status} /></div>
                        <dl>
                            <Row name={tt('dailySubsidy')} value={<Money value={policy.daily_subsidy_amount} currency={policy.currency_code} />} />
                            <Row name={tt('employeeContribution')} value={<Money value={policy.employee_contribution_amount} currency={policy.currency_code} />} />
                            <Row name={tt('providerPrice')} value={<Money value={policy.provider_price} currency={policy.currency_code} />} />
                            <Row name={tt('usageRule')} value={usageRule(policy, t)} />
                            <Row name={tt('extraScanPolicy')} value={t(`cafeteriaPolicy.extraScan.${policy.extra_scan_policy}`)} />
                            <Row name={tt('effectiveFrom')} value={<LocalizedDateDisplay value={policy.effective_from} />} />
                            <Row name={tt('effectiveTo')} value={policy.effective_to ? <LocalizedDateDisplay value={policy.effective_to} /> : tt('openEnded')} />
                            {policy.supersedes && <Row name={tt('version')} value={<Link className="hover:underline" href={route('cafeteria.policies.show', policy.supersedes.id)}>← v{policy.supersedes.version_no}</Link>} />}
                            {policy.cancellation_reason && <Row name={tt('reason')} value={policy.cancellation_reason} />}
                        </dl>
                        {policy.notes && <p className="mt-3 text-sm text-[color:var(--app-muted-foreground)]">{policy.notes}</p>}
                    </Card>

                    <Card className="p-5">
                        <h2 className="mb-2 text-base font-semibold">{tt('preview')}</h2>
                        {!preview.current ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{tt('noCurrentPolicy')}</p> : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-start text-xs text-[color:var(--app-muted-foreground)]">
                                        <th className="py-1 text-start">{tt('changes')}</th>
                                        <th className="py-1 text-start">{tt('currentPolicy')} v{preview.current.version_no}</th>
                                        <th className="py-1 text-start">{tt('newPolicy')} v{preview.proposed?.version_no}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {Object.entries(preview.changes).length === 0 && <tr><td colSpan={3} className="py-2 text-[color:var(--app-muted-foreground)]">—</td></tr>}
                                    {Object.entries(preview.changes).map(([key, change]) => (
                                        <tr key={key} className="border-t border-[color:var(--app-border)]">
                                            <td className="py-1.5">{key.replace(/_/g, ' ')}</td>
                                            <td className="py-1.5">{show(change.from)}</td>
                                            <td className="py-1.5 font-medium">{show(change.to)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Card>
                </div>

                <Card className="p-5">
                    <h2 className="mb-2 text-base font-semibold">{tt('versionHistory')}</h2>
                    <table className="w-full text-sm">
                        <thead><tr className="text-xs text-[color:var(--app-muted-foreground)]">
                            <th className="py-1 text-start">{tt('version')}</th><th className="py-1 text-start">{tt('dailySubsidy')}</th>
                            <th className="py-1 text-start">{tt('effectiveFrom')}</th><th className="py-1 text-start">{tt('effectiveTo')}</th><th className="py-1 text-start">{tt('status')}</th>
                        </tr></thead>
                        <tbody>
                            {versions.map((v) => (
                                <tr key={v.id} className="border-t border-[color:var(--app-border)]">
                                    <td className="py-1.5"><Link className="text-[color:var(--color-primary)] hover:underline" href={route('cafeteria.policies.show', v.id)}>v{v.version_no}</Link></td>
                                    <td className="py-1.5"><Money value={v.daily_subsidy_amount} currency={policy.currency_code} /></td>
                                    <td className="py-1.5"><LocalizedDateDisplay value={v.effective_from} /></td>
                                    <td className="py-1.5">{v.effective_to ? <LocalizedDateDisplay value={v.effective_to} /> : tt('openEnded')}</td>
                                    <td className="py-1.5"><StatusPill status={v.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
