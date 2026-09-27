import { ClipboardListIcon } from '@/Components/Icons';
import { useConfirm } from '@/hooks/useConfirm';
import { Alert, Button, Card, EmptyState, FormField, Input, Select, StatusBadge, Textarea, cx } from '@euisis/ui';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toneClasses, toneFor, useBackupText, useTime, type Recovery, type RequestPermissions } from './backupUi';

// The happy path, with who moves each step. Terminal side exits: REJECTED, CANCELLED, FAILED.
const STAGES = [
    { status: 'REQUESTED', role: 'requester' },
    { status: 'UNDER_REVIEW', role: 'reviewer' },
    { status: 'APPROVED', role: 'approver' },
    { status: 'TEST_RESTORE_RUNNING', role: 'operator' },
    { status: 'TEST_RESTORE_VERIFIED', role: 'operator' },
    { status: 'PRODUCTION_RESTORE_AUTHORIZED', role: 'approver' },
    { status: 'RESTORING', role: 'operator' },
    { status: 'COMPLETED', role: 'operator' },
] as const;
const OPERATOR_STATUSES = ['APPROVED', 'TEST_RESTORE_RUNNING', 'PRODUCTION_RESTORE_AUTHORIZED', 'RESTORING'];
export const OPEN_STATUSES = ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_RUNNING', 'TEST_RESTORE_VERIFIED', 'PRODUCTION_RESTORE_AUTHORIZED', 'RESTORING'];

function WorkflowGuide() {
    const { t } = useBackupText();
    return (
        <div>
            <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('backup.workflowTitle')}</h3>
            <p className="mt-1 max-w-3xl text-sm text-[color:var(--app-muted-foreground)]">{t('backup.requestsHelp')}</p>
            <ol className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8">
                {STAGES.map(({ status, role }, index) => (
                    <li key={status} className="rounded-[var(--radius-control)] border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-3 py-2">
                        <span className="text-xs font-semibold tabular-nums text-[color:var(--color-primary)]">{index + 1}</span>
                        <p className="text-xs font-medium leading-snug text-[color:var(--app-foreground)]">{t(`backup.statuses.${status}`)}</p>
                        <p className="mt-0.5 text-[11px] text-[color:var(--app-muted-foreground)]">{t(`backup.roles.${role}`)}</p>
                    </li>
                ))}
            </ol>
        </div>
    );
}

function StageProgress({ status }: { status: string }) {
    const { t } = useBackupText();
    const index = STAGES.findIndex((stage) => stage.status === status);
    const fill = status === 'COMPLETED' ? toneClasses.success.solid : 'bg-[color:var(--color-primary)]';

    return (
        <div>
            <ol className="flex gap-1" aria-label={t('backup.stage')}>
                {STAGES.map((stage, position) => (
                    <li key={stage.status} title={t(`backup.statuses.${stage.status}`)}
                        className={cx('h-1.5 flex-1 rounded-full', index >= position ? fill : 'bg-[color:var(--app-surface-muted)]')} />
                ))}
            </ol>
            <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">
                {index >= 0 ? `${t('backup.stage')} ${index + 1} / ${STAGES.length}` : t(`backup.statuses.${status}`)}
            </p>
        </div>
    );
}

export function RequestForm() {
    const { t } = useBackupText();
    const form = useForm({ restore_type: 'POINT_IN_TIME', incident_reference: '', reason: '', target_time: '', backup_reference: '' });
    const submit = () => form.post(route('backups.requests.store'), { preserveScroll: true, preserveState: true, onSuccess: () => form.reset() });

    return (
        <Card className="p-5">
            <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('backup.newRequest')}</h3>
            <form className="mt-4 grid gap-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); submit(); }}>
                <FormField label={t('backup.restoreType')} required error={form.errors.restore_type}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.restore_type}
                            onChange={(event) => form.setData({ ...form.data, restore_type: event.target.value, target_time: '', backup_reference: '' })}>
                            {['POINT_IN_TIME', 'BACKUP_SET', 'LATEST'].map((type) => <option key={type} value={type}>{t(`backup.types.${type}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={t('backup.incidentTicket')} required error={form.errors.incident_reference}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} required maxLength={80} placeholder="INC-2026-0042"
                            value={form.data.incident_reference} onChange={(event) => form.setData('incident_reference', event.target.value)} />
                    )}
                </FormField>
                {form.data.restore_type === 'POINT_IN_TIME' && (
                    <FormField label={t('backup.recoveryTarget')} required description={t('backup.recoveryTargetHelp')} error={form.errors.target_time}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} required className="font-mono" placeholder="2026-09-27T10:43:19+03:00"
                                value={form.data.target_time} onChange={(event) => form.setData('target_time', event.target.value)} />
                        )}
                    </FormField>
                )}
                {form.data.restore_type === 'BACKUP_SET' && (
                    <FormField label={t('backup.backupLabel')} required error={form.errors.backup_reference}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} required maxLength={40} className="font-mono" placeholder="20260927-010000F"
                                value={form.data.backup_reference} onChange={(event) => form.setData('backup_reference', event.target.value)} />
                        )}
                    </FormField>
                )}
                <FormField className="sm:col-span-2" label={t('backup.reason')} required error={form.errors.reason}>
                    {({ id, describedBy, invalid }) => (
                        <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required minLength={10} maxLength={1000} rows={3}
                            value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    )}
                </FormField>
                <div className="flex justify-end sm:col-span-2">
                    <Button type="submit" variant="primary" loading={form.processing}>{t('backup.submitRequest')}</Button>
                </div>
            </form>
        </Card>
    );
}

function RequestActions({ record, can }: { record: Recovery; can: RequestPermissions }) {
    const { t } = useBackupText();
    const { confirm } = useConfirm();
    const form = useForm({ action: '', evidence_reference: '' });
    const [error, setError] = useState('');
    const actions = [
        can.review && record.status === 'REQUESTED' && 'review',
        can.approve && record.status === 'UNDER_REVIEW' && 'approve',
        can.approve && record.status === 'TEST_RESTORE_VERIFIED' && 'authorize-production',
        can.approve && ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_VERIFIED'].includes(record.status) && 'reject',
        record.can_cancel && 'cancel',
    ].filter((action): action is string => Boolean(action));

    async function submit(action: string) {
        if (action === 'authorize-production') {
            const { confirmed } = await confirm({ title: t('backup.confirmAuthorizeTitle'), description: t('backup.confirmAuthorizeMessage'),
                confirmLabel: t('backup.actions.authorize-production'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
            if (!confirmed) return;
        }
        setError('');
        form.transform((data) => ({ ...data, action }));
        form.post(route('backups.requests.transition', record.id), { preserveScroll: true, preserveState: true, onError: (errors) => setError(Object.values(errors).join(' ')) });
    }

    if (actions.length === 0 && !OPERATOR_STATUSES.includes(record.status)) return null;

    return (
        <div className="space-y-2 border-t border-[color:var(--app-border)] pt-3">
            {OPERATOR_STATUSES.includes(record.status) && <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('backup.operatorStep')}</p>}
            {actions.length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input className="h-[var(--control-h-sm)] w-44" aria-label={t('backup.evidenceTicket')} placeholder={t('backup.evidenceTicket')}
                        value={form.data.evidence_reference} onChange={(event) => form.setData('evidence_reference', event.target.value)} />
                    {actions.map((action) => (
                        <Button key={action} size="sm" loading={form.processing}
                            variant={action === 'reject' ? 'outline' : action === 'cancel' ? 'ghost' : 'primary'} onClick={() => submit(action)}>
                            {t(`backup.actions.${action}`)}
                        </Button>
                    ))}
                </div>
            )}
            {error && <p role="alert" className="text-xs text-red-700 dark:text-red-300">{error}</p>}
        </div>
    );
}

export function RequestList({ requests, can }: { requests: Recovery[]; can: RequestPermissions }) {
    const text = useBackupText();
    const time = useTime();
    const { t } = text;
    if (requests.length === 0) {
        return <EmptyState icon={<ClipboardListIcon className="h-8 w-8" />} title={t('backup.noRequests')} description={t('backup.noRequestsHelp')} />;
    }

    return (
        <ul className="space-y-3">
            {requests.map((record) => (
                <li key={record.id}>
                    <Card className="space-y-3 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h4 className="text-sm font-semibold text-[color:var(--app-foreground)]">{record.incident_reference}</h4>
                                    <StatusBadge tone="neutral">{t(`backup.types.${record.restore_type}`)}</StatusBadge>
                                </div>
                                <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">
                                    {record.target_time ? time.absolute(record.target_time) : record.backup_reference ?? t(`backup.types.${record.restore_type}`)}
                                    {' · '}{t('backup.evidence')}: {record.evidence_reference ?? t('backup.pending')}
                                </p>
                            </div>
                            <StatusBadge tone={toneFor(record.status)}>{t(`backup.statuses.${record.status}`)}</StatusBadge>
                        </div>
                        <StageProgress status={record.status} />
                        <p className="whitespace-pre-line text-sm text-[color:var(--app-foreground)]">{record.reason}</p>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                            {([['requestedBy', record.requester], ['reviewedBy', record.reviewer], ['approvedBy', record.approver], ['productionAuthorizedBy', record.production_authorizer]] as const).map(([label, name]) => (
                                <div key={label}>
                                    <dt className="text-[color:var(--app-muted-foreground)]">{t(`backup.${label}`)}</dt>
                                    <dd className="font-medium text-[color:var(--app-foreground)]">{name ?? '—'}</dd>
                                </div>
                            ))}
                        </dl>
                        {record.failure_summary && <Alert tone="danger">{record.failure_summary}</Alert>}
                        <RequestActions record={record} can={can} />
                    </Card>
                </li>
            ))}
        </ul>
    );
}

export function RequestsPanel({ requests, can }: { requests: Recovery[]; can: RequestPermissions }) {
    return (
        <div className="space-y-6">
            <WorkflowGuide />
            {can.request && <RequestForm />}
            <RequestList requests={requests} can={can} />
        </div>
    );
}
