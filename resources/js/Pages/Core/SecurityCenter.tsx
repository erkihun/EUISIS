import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, EmptyState, FormField, Input, Select, Textarea } from '@euisis/ui';

type Grant = {
    id: string;
    status: 'requested' | 'active' | 'rejected' | 'expired' | 'revoked';
    mine: boolean;
    user: string | null;
    permissions: string[];
    scope_type: 'organization' | 'citywide';
    organization: { name_en: string; name_am: string | null } | null;
    reason: string;
    duration_minutes: number;
    requested_at: string | null;
    decided_by: string | null;
    decision_reason: string | null;
    starts_at: string | null;
    expires_at: string | null;
    ended_by: string | null;
    end_reason: string | null;
    decide_url: string | null;
    end_url: string | null;
};

type Integrity = {
    sealed_through: number;
    last_sealed_at: string | null;
    unsealed: number;
    last_verification: { ok: boolean; finished_at: string | null; checked_rows: number; message: string | null; first_bad_sequence: number | null } | null;
    verify_url: string | null;
};

type Dashboard = {
    privileged: number; privileged_with_mfa: number; privileged_without_mfa: string[]; privileged_without_mfa_count: number; mfa_enforced: boolean;
    active_sessions: number | null; failed_sign_ins_24h: number; lockouts_24h: number; break_glass_active: number;
    api_clients: { active: number; revoked: number; expired: number }; webhooks: { failed_deliveries: number; suspended: number };
    recent_access_changes: Array<{ action: string; actor: string | null; subject: string | null; at: string | null }>;
};

type RequestOptions = { url: string; permissions: string[]; max_minutes: number; organizations: Array<{ id: string; name_en: string; name_am: string | null }> };

const TONE: Record<Grant['status'], string> = { requested: 'pending', active: 'warning', rejected: 'inactive', expired: 'inactive', revoked: 'inactive' };

/**
 * Core › Security Center (ADR 0010): emergency (break-glass) access, which
 * someone else approves and which ends by itself; and how far the audit
 * trail is sealed and what its last check found.
 */
export default function SecurityCenter({ grants, request, integrity, dashboard }: { grants: Grant[]; request: RequestOptions | null; integrity: Integrity | null; dashboard?: Dashboard | null }) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.security.${key}`);
    const [asking, setAsking] = useState(false);
    const [deciding, setDeciding] = useState<{ grant: Grant; decision: 'approve' | 'reject' | 'end' } | null>(null);
    const ask = useForm({ permissions: [] as string[], scope_type: 'organization', organization_id: '', duration_minutes: 60, reason: '' });
    const decide = useForm({ decision: 'approve', reason: '' });
    const named = (org: Grant['organization']) => (org ? (locale === 'am' && org.name_am ? org.name_am : org.name_en) : label('wholeCity'));

    const submitAsk = (event: FormEvent) => {
        event.preventDefault();
        if (request) ask.post(request.url, { preserveScroll: true, onSuccess: () => setAsking(false) });
    };
    const submitDecision = (event: FormEvent) => {
        event.preventDefault();
        if (!deciding) return;
        const url = deciding.decision === 'end' ? deciding.grant.end_url : deciding.grant.decide_url;
        if (!url) return;
        decide.transform((data) => ({ ...data, decision: deciding.decision }));
        decide.post(url, { preserveScroll: true, onSuccess: () => setDeciding(null) });
    };
    const togglePermission = (permission: string) => ask.setData('permissions', ask.data.permissions.includes(permission)
        ? ask.data.permissions.filter((p) => p !== permission) : [...ask.data.permissions, permission]);

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={request ? <Button variant="primary" onClick={() => { ask.reset(); ask.clearErrors(); setAsking(true); }}>{label('requestAccess')}</Button> : undefined} />}>
            <Head title={label('title')} />
            <div className="space-y-5">
                {dashboard && (
                    <Card className="p-5">
                        <h2 className="font-semibold text-(--app-foreground)">{label('dashboardTitle')}</h2>
                        <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
                            <Figure term={label('mfaCoverage')} value={`${dashboard.privileged_with_mfa}/${dashboard.privileged}`} note={dashboard.mfa_enforced ? label('mfaEnforced') : label('mfaNotEnforced')} />
                            <Figure term={label('withoutMfa')} value={String(dashboard.privileged_without_mfa_count)} note={dashboard.privileged_without_mfa.join(', ')} />
                            <Figure term={label('activeSessions')} value={dashboard.active_sessions === null ? '—' : String(dashboard.active_sessions)} />
                            <Figure term={label('failedSignIns')} value={String(dashboard.failed_sign_ins_24h)} note={`${label('lockouts')}: ${dashboard.lockouts_24h}`} />
                            <Figure term={label('breakGlassActive')} value={String(dashboard.break_glass_active)} />
                            <Figure term={label('apiClients')} value={String(dashboard.api_clients.active)} note={`${label('revoked')}: ${dashboard.api_clients.revoked} · ${label('expired')}: ${dashboard.api_clients.expired}`} />
                            <Figure term={label('webhookFailures')} value={String(dashboard.webhooks.failed_deliveries)} note={`${label('suspended')}: ${dashboard.webhooks.suspended}`} />
                        </dl>
                        <h3 className="mt-5 text-sm font-semibold text-(--app-foreground)">{label('recentAccessChanges')}</h3>
                        {dashboard.recent_access_changes.length === 0 ? <p className="mt-1 text-sm text-(--app-muted-foreground)">{label('none')}</p> : (
                            <ul className="mt-2 space-y-1 text-xs">
                                {dashboard.recent_access_changes.map((change, index) => (
                                    <li key={index}><span className="font-mono">{change.action}</span> · {change.actor ?? '—'} · <LocalizedDateDisplay value={change.at} withTime /></li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
                {integrity && (
                    <Card className="p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold text-(--app-foreground)">{label('integrityTitle')}</h2>
                                <p className="mt-1 text-sm text-(--app-muted-foreground)">{label('integrityDescription')}</p>
                            </div>
                            {integrity.verify_url && <Button variant="outline" onClick={() => router.post(integrity.verify_url as string, {}, { preserveScroll: true })}>{label('verifyNow')}</Button>}
                        </div>
                        <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                            <div><dt className="text-xs text-(--app-muted-foreground)">{label('sealedThrough')}</dt><dd className="mt-1 tabular-nums">{integrity.sealed_through}</dd></div>
                            <div><dt className="text-xs text-(--app-muted-foreground)">{label('lastSealed')}</dt><dd className="mt-1">{integrity.last_sealed_at ? <LocalizedDateDisplay value={integrity.last_sealed_at} withTime /> : '—'}</dd></div>
                            <div><dt className="text-xs text-(--app-muted-foreground)">{label('unsealed')}</dt><dd className="mt-1 tabular-nums">{integrity.unsealed}</dd></div>
                            <div>
                                <dt className="text-xs text-(--app-muted-foreground)">{label('lastCheck')}</dt>
                                <dd className="mt-1">{integrity.last_verification ? (
                                    <>
                                        <StatusBadge status={integrity.last_verification.ok ? 'active' : 'danger'} label={label(integrity.last_verification.ok ? 'checkPassed' : 'checkFailed')} />
                                        <span className="block text-xs text-(--app-muted-foreground)">{integrity.last_verification.finished_at ? <LocalizedDateDisplay value={integrity.last_verification.finished_at} withTime /> : label('running')}</span>
                                        {integrity.last_verification.message && <span className="block text-xs">{integrity.last_verification.message}</span>}
                                    </>
                                ) : label('neverChecked')}</dd>
                            </div>
                        </dl>
                    </Card>
                )}

                <Card>
                    <div className="border-b border-(--app-border) px-5 py-4">
                        <h2 className="font-semibold text-(--app-foreground)">{label('grantsTitle')}</h2>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{label('grantsDescription')}</p>
                    </div>
                    {grants.length === 0 ? <EmptyState title={label('noGrants')} /> : (
                        <ul className="divide-y divide-(--app-border)">
                            {grants.map((grant) => (
                                <li key={grant.id} className="px-5 py-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="font-medium text-(--app-foreground)">
                                                {grant.user ?? '—'} · {named(grant.organization)} · {grant.duration_minutes} {label('minutes')}
                                                <span className="ml-2"><StatusBadge status={TONE[grant.status]} label={label(`statuses.${grant.status}`)} /></span>
                                            </p>
                                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{grant.permissions.join(', ')}</p>
                                            <p className="mt-1 whitespace-pre-wrap text-sm">{grant.reason}</p>
                                            <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                                <LocalizedDateDisplay value={grant.requested_at} withTime />
                                                {grant.decided_by && <> · {label('decidedBy')} {grant.decided_by}</>}
                                                {grant.expires_at && <> · {label('until')} <LocalizedDateDisplay value={grant.expires_at} withTime /></>}
                                                {grant.ended_by && <> · {label('endedBy')} {grant.ended_by}</>}
                                            </p>
                                            {(grant.decision_reason || grant.end_reason) && <p className="mt-1 text-xs">{grant.end_reason ?? grant.decision_reason}</p>}
                                        </div>
                                        <div className="flex gap-2">
                                            {grant.decide_url && <Button size="sm" variant="primary" onClick={() => { decide.reset(); setDeciding({ grant, decision: 'approve' }); }}>{label('approve')}</Button>}
                                            {grant.decide_url && <Button size="sm" variant="outline" onClick={() => { decide.reset(); setDeciding({ grant, decision: 'reject' }); }}>{label('reject')}</Button>}
                                            {grant.end_url && <Button size="sm" variant="outline" onClick={() => { decide.reset(); setDeciding({ grant, decision: 'end' }); }}>{label('end')}</Button>}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {request && (
                <AppDialog open={asking} onClose={() => setAsking(false)} title={label('requestAccess')} description={label('requestDescription')}
                    footer={<><Button variant="outline" onClick={() => setAsking(false)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="break-glass-form" loading={ask.processing}>{label('send')}</Button></>}>
                    <form id="break-glass-form" onSubmit={submitAsk} className="space-y-4">
                        <FormField label={label('scope')} error={ask.errors.scope_type}>
                            {({ id }) => (
                                <Select id={id} value={ask.data.scope_type} onChange={(event) => ask.setData('scope_type', event.target.value)}>
                                    <option value="organization">{label('oneOrganization')}</option>
                                    <option value="citywide">{label('wholeCity')}</option>
                                </Select>
                            )}
                        </FormField>
                        {ask.data.scope_type === 'organization' && (
                            <FormField label={label('organization')} required error={ask.errors.organization_id}>
                                {({ id }) => (
                                    <Select id={id} value={ask.data.organization_id} onChange={(event) => ask.setData('organization_id', event.target.value)}>
                                        <option value="">—</option>
                                        {request.organizations.map((org) => <option key={org.id} value={org.id}>{locale === 'am' && org.name_am ? org.name_am : org.name_en}</option>)}
                                    </Select>
                                )}
                            </FormField>
                        )}
                        <FormField label={`${label('duration')} (≤ ${request.max_minutes})`} required error={ask.errors.duration_minutes}>
                            {({ id }) => <Input id={id} type="number" min={15} max={request.max_minutes} value={ask.data.duration_minutes} onChange={(event) => ask.setData('duration_minutes', Number(event.target.value))} />}
                        </FormField>
                        <fieldset>
                            <legend className="text-sm font-medium text-(--app-foreground)">{label('permissions')}</legend>
                            <div className="mt-2 grid max-h-48 gap-1 overflow-y-auto text-sm sm:grid-cols-2">
                                {request.permissions.map((permission) => (
                                    <label key={permission} className="flex items-center gap-2"><input type="checkbox" checked={ask.data.permissions.includes(permission)} onChange={() => togglePermission(permission)} /><span className="font-mono text-xs">{permission}</span></label>
                                ))}
                            </div>
                            {ask.errors.permissions && <p className="mt-1 text-xs text-red-600">{ask.errors.permissions}</p>}
                        </fieldset>
                        <FormField label={label('reason')} required description={label('reasonHelp')} error={ask.errors.reason}>
                            {({ id }) => <Textarea id={id} rows={3} maxLength={2000} value={ask.data.reason} onChange={(event) => ask.setData('reason', event.target.value)} />}
                        </FormField>
                    </form>
                </AppDialog>
            )}

            <AppDialog open={deciding !== null} onClose={() => setDeciding(null)} title={deciding ? label(deciding.decision) : ''}
                footer={<><Button variant="outline" onClick={() => setDeciding(null)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="break-glass-decision" loading={decide.processing}>{label('confirm')}</Button></>}>
                <form id="break-glass-decision" onSubmit={submitDecision}>
                    {(decide.errors as Record<string, string | undefined>).grant && <p className="mb-2 text-sm text-red-600">{(decide.errors as Record<string, string | undefined>).grant}</p>}
                    <FormField label={label('reason')} required={deciding?.decision !== 'approve'} error={decide.errors.reason}>
                        {({ id }) => <Textarea id={id} rows={3} value={decide.data.reason} onChange={(event) => decide.setData('reason', event.target.value)} />}
                    </FormField>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}

function Figure({ term, value, note }: { term: string; value: string; note?: string }) {
    return (
        <div>
            <dt className="text-xs text-(--app-muted-foreground)">{term}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums text-(--app-foreground)">{value}</dd>
            {note && <dd className="text-xs text-(--app-muted-foreground)">{note}</dd>}
        </div>
    );
}
