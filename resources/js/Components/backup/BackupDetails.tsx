import { ArchiveIcon, CheckCircle, ChevronRight, HistoryIcon, XCircle } from '@/Components/Icons';
import { Alert, Card, EmptyState, StatusBadge, cx } from '@euisis/ui';
import { FactRow } from './BackupHealthOverview';
import { toneClasses, toneFor, useBackupText, useTime, type BackupHealth, type HistoryItem, type Issue, type Policy } from './backupUi';

const SEVERITIES = [
    { severity: 'CRITICAL', tone: 'danger' },
    { severity: 'WARNING', tone: 'warning' },
    { severity: 'READINESS', tone: 'neutral' },
] as const;

/** Names the subject an issue code refers to: a repository, or a specific go-live control. */
function useIssueText() {
    const text = useBackupText();
    return (issue: Issue) => {
        if (issue.reason === 'CONTROL_UNVERIFIED') {
            const control = issue.code.replace(/_UNVERIFIED$/, '').toLowerCase();
            return `${text.t(`backup.controls.${control}`)}: ${text.t('backup.unverified')}`;
        }
        const repository = issue.code.match(/^REPOSITORY_(\d+)_/);
        const description = text.reason(issue.reason);
        return repository ? `${text.t('backup.repository')} ${repository[1]} · ${description}` : description;
    };
}

/** Open issues grouped by what they mean for recovery right now. */
export function IssuesPanel({ issues }: { issues: Issue[] }) {
    const text = useBackupText();
    const issueText = useIssueText();
    if (issues.length === 0) {
        return <EmptyState icon={<CheckCircle className="h-8 w-8" />} title={text.t('backup.noIssues')} description={text.t('backup.noIssuesHelp')} />;
    }
    // Go-live evidence gaps can run long; fold them away while there are operational problems to fix first.
    const operational = issues.some((issue) => issue.severity !== 'READINESS');

    return (
        <div className="space-y-4">
            {SEVERITIES.map(({ severity, tone }) => {
                const items = issues.filter((issue) => issue.severity === severity);
                if (items.length === 0) return null;
                return (
                    <details key={severity} open={severity !== 'READINESS' || !operational} className="group">
                        <summary className="flex cursor-pointer list-none flex-wrap items-baseline gap-x-2 rounded-[var(--radius-control)] py-1 [&::-webkit-details-marker]:hidden">
                            <span className="flex items-center gap-2 text-sm font-semibold text-[color:var(--app-foreground)]">
                                <ChevronRight className="h-3.5 w-3.5 text-[color:var(--app-muted-foreground)] transition-transform group-open:rotate-90" aria-hidden="true" />
                                <span className={cx('h-2 w-2 rounded-full', toneClasses[tone].solid)} aria-hidden="true" />
                                {text.t(`backup.severities.${severity}`)}
                                <span className="font-normal text-[color:var(--app-muted-foreground)]">({items.length})</span>
                            </span>
                            <span className="text-xs text-[color:var(--app-muted-foreground)]">{text.t(`backup.severityHelp.${severity}`)}</span>
                        </summary>
                        <ul className="mt-2 divide-y divide-[color:var(--app-border)] rounded-[var(--radius-card)] border border-[color:var(--app-border)]">
                            {items.map((issue) => (
                                <li key={issue.code} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                    <span className="text-sm text-[color:var(--app-foreground)]">{issueText(issue)}</span>
                                    <code className="shrink-0 font-mono text-xs text-[color:var(--app-muted-foreground)]">{issue.code}</code>
                                </li>
                            ))}
                        </ul>
                    </details>
                );
            })}
        </div>
    );
}

function StorageBar({ usage, threshold }: { usage: number | null; threshold: number }) {
    const text = useBackupText();
    const over = usage !== null && usage >= threshold;

    return (
        <div>
            <div className="flex justify-between text-xs">
                <span className="text-[color:var(--app-muted-foreground)]">{text.t('backup.storageUsed')}</span>
                <span className={cx('font-medium tabular-nums', over ? toneClasses.warning.text : 'text-[color:var(--app-foreground)]')}>{usage === null ? text.t('backup.unknown') : `${usage}%`}</span>
            </div>
            <div className="relative mt-1.5 h-2 rounded-full bg-[color:var(--app-surface-muted)]" role="progressbar" aria-label={text.t('backup.storageUsed')}
                aria-valuemin={0} aria-valuemax={100} aria-valuenow={usage ?? undefined}>
                {usage !== null && <div className={cx('h-2 rounded-full', over ? toneClasses.warning.solid : 'bg-[color:var(--color-primary)]')} style={{ width: `${Math.min(100, usage)}%` }} />}
                <span className="absolute -inset-y-0.5 w-0.5 rounded bg-amber-500/70" style={{ left: `${threshold}%` }} title={`${text.t('backup.storageThreshold')}: ${threshold}%`} aria-hidden="true" />
            </div>
        </div>
    );
}

/** Per-repository detail: the independent copies recovery depends on. */
export function RepositoriesPanel({ status, policy }: { status: BackupHealth; policy: Policy }) {
    const text = useBackupText();
    const time = useTime();
    const { t } = text;
    if (status.repositories.length === 0) {
        return <EmptyState icon={<ArchiveIcon className="h-8 w-8" />} title={t('backup.repositoriesNotChecked')}
            description={text.reason(status.components.infrastructure.reason_code, status.message)} />;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {status.repositories.map((repository) => (
                <Card key={repository.id} className="space-y-4 p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('backup.repository')} {repository.id}</p>
                            <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{repository.name}</h3>
                        </div>
                        <div className="flex flex-wrap justify-end gap-1.5">
                            <StatusBadge tone={toneFor(repository.status)}>{text.state(repository.status)}</StatusBadge>
                            {repository.status === 'AVAILABLE' && <StatusBadge tone={toneFor(repository.backup_status)}>{text.state(repository.backup_status)}</StatusBadge>}
                        </div>
                    </div>
                    <StorageBar usage={repository.usage_percent} threshold={policy.storage_warning_percent} />
                    <dl className="space-y-1.5 text-sm">
                        <FactRow label={t('backup.latestFull')} title={time.absolute(repository.full?.completed_at)}>{time.relative(repository.full?.completed_at)}</FactRow>
                        <FactRow label={t('backup.latestDaily')} title={time.absolute(repository.daily?.completed_at)}>{time.relative(repository.daily?.completed_at)}</FactRow>
                        <FactRow label={t('backup.backupAge')}>{repository.age_seconds === null ? t('backup.unknown') : `${(repository.age_seconds / 3600).toFixed(1)} ${t('backup.hours')}`}</FactRow>
                        <FactRow label={t('backup.encrypted')}>{repository.encrypted === null ? t('backup.unknown') : repository.encrypted ? t('backup.yes') : t('backup.no')}</FactRow>
                        <FactRow label={t('backup.lastVerified')} title={time.absolute(repository.last_verified_at)}>{time.relative(repository.last_verified_at)}</FactRow>
                        <FactRow label={t('backup.lastRestoreTest')} title={time.absolute(repository.last_restore_test_at)}>{time.relative(repository.last_restore_test_at)}</FactRow>
                        {repository.latest && <FactRow label={t('backup.backupLabel')}><code className="font-mono text-xs">{repository.latest.reference}</code></FactRow>}
                    </dl>
                </Card>
            ))}
        </div>
    );
}

/** Imported operation journal: backups, verification, restore tests and retention runs. */
export function HistoryPanel({ history }: { history: HistoryItem[] }) {
    const text = useBackupText();
    const time = useTime();
    const { t } = text;
    if (history.length === 0) {
        return <EmptyState icon={<HistoryIcon className="h-8 w-8" />} title={t('backup.noHistory')} description={t('backup.noHistoryHelp')} />;
    }
    const cell = 'px-4 py-2.5';

    return (
        <div className="overflow-x-auto rounded-[var(--radius-card)] border border-[color:var(--app-border)]">
            <table className="w-full text-left text-sm">
                <thead className="bg-[color:var(--app-surface-muted)] text-xs text-[color:var(--app-muted-foreground)]">
                    <tr>
                        {['started', 'operation', 'repository', 'target', 'result'].map((key) => <th key={key} scope="col" className={cx(cell, 'font-medium')}>{t(`backup.${key}`)}</th>)}
                    </tr>
                </thead>
                <tbody className="divide-y divide-[color:var(--app-border)]">
                    {history.map((item) => (
                        <tr key={item.id}>
                            <td className={cx(cell, 'whitespace-nowrap')} title={time.absolute(item.started_at)}>{time.relative(item.started_at)}</td>
                            <td className={cell}>{text.operation(item.type)}</td>
                            <td className={cell}>{item.repository ?? '—'}</td>
                            <td className={cell}>
                                {item.backup_reference ? <code className="font-mono text-xs">{item.backup_reference}</code> : '—'}
                                {item.recovery_target && <p className="text-xs text-[color:var(--app-muted-foreground)]">{time.absolute(item.recovery_target)}</p>}
                            </td>
                            <td className={cell}>
                                <StatusBadge tone={toneFor(item.status)}>{text.state(item.status)}</StatusBadge>
                                {item.failure_summary && <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{item.failure_summary}</p>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Configured thresholds (display only) and the go-live evidence attestations. */
export function PolicyPanel({ status, policy }: { status: BackupHealth; policy: Policy }) {
    const text = useBackupText();
    const time = useTime();
    const { t } = text;
    const controls = Object.entries(status.controls);
    const verified = controls.filter(([, passed]) => passed).length;

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="p-5">
                <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('backup.policyTitle')}</h3>
                <dl className="mt-3 space-y-1.5 text-sm">
                    <FactRow label={t('backup.retention')}>{policy.retention_full_count} {t('backup.fullChains')}</FactRow>
                    <FactRow label={t('backup.staleAfter')}>{policy.stale_hours} / {policy.critical_hours} {t('backup.hours')}</FactRow>
                    <FactRow label={t('backup.restoreTestWithin')}>{policy.restore_test_days} {t('backup.days')}</FactRow>
                    <FactRow label={t('backup.storageThreshold')}>{policy.storage_warning_percent}%</FactRow>
                    <FactRow label={t('backup.pitrRequired')}>{policy.pitr_required ? t('backup.yes') : t('backup.no')}</FactRow>
                    <FactRow label={t('backup.nextBackup')}>{t('backup.nextBackupValue')}</FactRow>
                    <FactRow label={t('backup.logicalBackup')}>{policy.logical_enabled ? time.relative(status.latest_logical_backup_at) : t('backup.logicalDisabled')}</FactRow>
                    <FactRow label="RPO"><StatusBadge tone={toneFor(status.rpo)}>{text.state(status.rpo)}</StatusBadge></FactRow>
                    <FactRow label="RTO"><StatusBadge tone={toneFor(status.rto)}>{text.state(status.rto)}</StatusBadge></FactRow>
                </dl>
                <Alert tone="info" className="mt-4">{t('backup.pitrNote')}</Alert>
            </Card>
            <Card className="p-5">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('backup.controlsTitle')}</h3>
                    <span className="text-xs tabular-nums text-[color:var(--app-muted-foreground)]">{verified} / {controls.length}</span>
                </div>
                <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{t('backup.controlsHelp')}</p>
                <ul className="mt-3 space-y-2 text-sm">
                    {controls.map(([name, passed]) => (
                        <li key={name} className="flex items-center justify-between gap-3">
                            <span className="flex items-center gap-2 text-[color:var(--app-foreground)]">
                                {passed
                                    ? <CheckCircle className={cx('h-4 w-4', toneClasses.success.text)} aria-hidden="true" />
                                    : <XCircle className="h-4 w-4 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />}
                                {t(`backup.controls.${name}`)}
                            </span>
                            <span className="text-xs text-[color:var(--app-muted-foreground)]">{passed ? t('backup.evidenceCurrent') : t('backup.unverified')}</span>
                        </li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}
