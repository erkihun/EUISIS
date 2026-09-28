import { ActivityIcon, AlertTriangle, ArchiveIcon, CheckCircle, ChevronRight, ClipboardCheckIcon, HistoryIcon, InfoIcon, Layers, ShieldCheck, XCircle } from '@/Components/Icons';
import { Card, StatusBadge, cx, type Tone } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { headline, overallTone, toneClasses, toneFor, useBackupText, useTime, type BackupHealth, type HealthComponent } from './backupUi';

export function ToneIcon({ tone, className = 'h-5 w-5' }: { tone: Tone; className?: string }) {
    const Icon = { success: CheckCircle, warning: AlertTriangle, danger: XCircle, info: InfoIcon, neutral: InfoIcon }[tone];
    return <Icon className={className} aria-hidden="true" />;
}

function ToneMark({ tone, size = 'md' }: { tone: Tone; size?: 'md' | 'lg' }) {
    // The large mark is decorative next to the status heading; phones need that width for text.
    return (
        <span className={cx('shrink-0 items-center justify-center rounded-full', toneClasses[tone].soft, toneClasses[tone].text, size === 'lg' ? 'hidden h-12 w-12 sm:flex' : 'flex h-10 w-10')}>
            <ToneIcon tone={tone} className={size === 'lg' ? 'h-6 w-6' : 'h-5 w-5'} />
        </span>
    );
}

export function Fact({ label, children, title }: { label: string; children: ReactNode; title?: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 break-words text-sm font-medium text-[color:var(--app-foreground)]" title={title}>{children}</dd>
        </div>
    );
}

export function FactRow({ label, children, title }: { label: string; children: ReactNode; title?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="text-right font-medium tabular-nums text-[color:var(--app-foreground)]" title={title}>{children}</dd>
        </div>
    );
}

function Chip({ label, children, tone }: { label: string; children: ReactNode; tone?: Tone }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-control)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-2 py-1 text-xs">
            {tone && <span className={cx('h-1.5 w-1.5 rounded-full', toneClasses[tone].solid)} aria-hidden="true" />}
            <span className="text-[color:var(--app-muted-foreground)]">{label}</span>
            <span className="font-medium text-[color:var(--app-foreground)]">{children}</span>
        </span>
    );
}

/** Compact summary for the dashboard. */
export function BackupHealthCard({ status }: { status: BackupHealth }) {
    const text = useBackupText();
    const time = useTime();
    const tone = overallTone(status);

    return (
        <Card className="p-5" aria-label={text.t('backup.healthAria')}>
            <div className="flex flex-wrap items-start gap-4">
                <ToneMark tone={tone} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{text.t('backup.title')}</h2>
                        <StatusBadge tone={tone}>{text.state(status.overall_status)}</StatusBadge>
                    </div>
                    <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{headline(status, text)}</p>
                </div>
                <Link href={route('backups.index')} className="inline-flex items-center gap-1 text-sm font-medium text-[color:var(--color-primary)] hover:underline">
                    {text.t('backup.openPage')}<ChevronRight className="h-4 w-4" aria-hidden="true" />
                </Link>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-[color:var(--app-border)] pt-4 lg:grid-cols-4">
                <Fact label={text.t('backup.lastBackup')} title={time.absolute(status.latest_backup_at)}>{time.relative(status.latest_backup_at)}</Fact>
                <Fact label={text.t('backup.cards.repository')}><StatusBadge tone={toneFor(status.repository_status)}>{text.state(status.repository_status)}</StatusBadge></Fact>
                <Fact label={text.t('backup.cards.wal')}><StatusBadge tone={toneFor(status.wal_status)}>{text.state(status.wal_status)}</StatusBadge></Fact>
                <Fact label={text.t('backup.lastRestoreTest')} title={time.absolute(status.latest_restore_test_at)}>{time.relative(status.latest_restore_test_at)}</Fact>
            </dl>
        </Card>
    );
}

/** Page banner: one overall verdict with its reason, environment context and freshest evidence. */
export function HealthHero({ status }: { status: BackupHealth }) {
    const text = useBackupText();
    const time = useTime();
    const tone = overallTone(status);
    const blocker = status.production_blocker ? text.t('backup.blockerYes') : status.enforced ? text.t('backup.blockerNo') : text.t('backup.blockerNotEnforced');

    return (
        <Card className="relative overflow-hidden p-0" aria-label={text.t('backup.healthAria')}>
            <span className={cx('absolute inset-y-0 left-0 w-1', toneClasses[tone].solid)} aria-hidden="true" />
            <div className="flex flex-col gap-6 p-6 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex min-w-0 items-start gap-4">
                    <ToneMark tone={tone} size="lg" />
                    <div className="min-w-0">
                        <p className="text-xs font-medium uppercase tracking-wide text-[color:var(--app-muted-foreground)]">{text.t('backup.overallStatus')}</p>
                        <h2 className={cx('mt-0.5 text-2xl font-semibold', toneClasses[tone].text)}>{text.state(status.overall_status)}</h2>
                        <p className="mt-1 max-w-2xl text-sm text-[color:var(--app-foreground)]">{headline(status, text)}</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <Chip label={text.t('backup.environment')}>{status.environment}</Chip>
                            <Chip label={text.t('backup.productionBlocker')} tone={status.production_blocker ? 'danger' : 'neutral'}>{blocker}</Chip>
                            <Chip label={text.t('backup.readiness')} tone={toneFor(status.readiness)}>{text.t(`backup.readinessStates.${status.readiness}`)}</Chip>
                            {status.reason_code && <Chip label={text.t('backup.reasonLabel')}><code className="font-mono">{status.reason_code}</code></Chip>}
                        </div>
                    </div>
                </div>
                <dl className="grid shrink-0 grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-6 lg:w-[27rem]">
                    <Fact label={text.t('backup.lastBackup')} title={time.absolute(status.latest_backup_at)}>{time.relative(status.latest_backup_at)}</Fact>
                    <Fact label={text.t('backup.lastWal')} title={time.absolute(status.latest_wal_at)}>{time.relative(status.latest_wal_at)}</Fact>
                    <Fact label={text.t('backup.lastRestoreTest')} title={time.absolute(status.latest_restore_test_at)}>{time.relative(status.latest_restore_test_at)}</Fact>
                </dl>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] px-6 py-2.5 text-xs text-[color:var(--app-muted-foreground)]">
                <span title={time.absolute(status.checked_at)}>{text.t('backup.checkedAt')} {time.relative(status.checked_at)} · {text.t('backup.driver')}: {status.driver}</span>
                <span>{text.t('backup.runbookHint')} <code className="font-mono">docs/runbooks/backup-health-troubleshooting.md</code></span>
            </div>
        </Card>
    );
}

function ComponentCard({ icon, title, component, children }: { icon: ReactNode; title: string; component: HealthComponent; children?: ReactNode }) {
    const text = useBackupText();
    const reason = component.reason_code ?? (component.status === 'NOT_CHECKED' ? 'NOT_CHECKED' : null);

    return (
        <Card className="flex flex-col p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-[var(--radius-control)] bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]" aria-hidden="true">{icon}</span>
                    <h3 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h3>
                </div>
                <StatusBadge tone={toneFor(component.status)}>{text.state(component.status)}</StatusBadge>
            </div>
            <p className="mb-4 mt-3 text-sm text-[color:var(--app-muted-foreground)]">{reason ? text.reason(reason) : text.t('backup.noAction')}</p>
            {/* mt-auto aligns the facts along the bottom edge of every card in a row. */}
            {children && <dl className="mt-auto space-y-1.5 border-t border-[color:var(--app-border)] pt-3 text-sm">{children}</dl>}
        </Card>
    );
}

/** The six recovery components, each with its own verdict and the facts behind it. */
export function ComponentGrid({ status }: { status: BackupHealth }) {
    const text = useBackupText();
    const time = useTime();
    const { t } = text;
    const wal = status.components.wal;
    const available = status.repositories.filter((repository) => repository.status === 'AVAILABLE').length;
    const icon = 'h-4 w-4';

    return (
        <section aria-label={t('backup.componentsAria')} className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <ComponentCard icon={<Layers className={icon} />} title={t('backup.cards.infrastructure')} component={status.components.infrastructure}>
                <FactRow label={t('backup.driver')}>{status.driver}</FactRow>
                {status.driver === 'pgbackrest' && <>
                    <FactRow label={t('backup.binary')}>{status.binary ? text.state(status.binary) : '—'}</FactRow>
                    <FactRow label={t('backup.stanza')}><code className="font-mono text-xs">{status.stanza ?? '—'}</code></FactRow>
                    <FactRow label={t('backup.version')}>{status.version ?? '—'}</FactRow>
                </>}
            </ComponentCard>
            <ComponentCard icon={<ArchiveIcon className={icon} />} title={t('backup.cards.repository')} component={status.components.repository}>
                <FactRow label={t('backup.repositoriesAvailable')}>{status.repositories.length ? `${available} / ${status.repositories.length}` : '—'}</FactRow>
                {status.repositories.map((repository) => (
                    <FactRow key={repository.id} label={repository.name}><StatusBadge tone={toneFor(repository.status)}>{text.state(repository.status)}</StatusBadge></FactRow>
                ))}
            </ComponentCard>
            <ComponentCard icon={<HistoryIcon className={icon} />} title={t('backup.cards.latestBackup')} component={status.components.backup}>
                <FactRow label={t('backup.latestFull')} title={time.absolute(status.latest_full_backup_at)}>{time.relative(status.latest_full_backup_at)}</FactRow>
                <FactRow label={t('backup.latestDaily')} title={time.absolute(status.latest_incremental_backup_at)}>{time.relative(status.latest_incremental_backup_at)}</FactRow>
                <FactRow label={t('backup.backupAge')}>{status.backup_age_seconds === null ? t('backup.unknown') : `${(status.backup_age_seconds / 3600).toFixed(1)} ${t('backup.hours')}`}</FactRow>
            </ComponentCard>
            <ComponentCard icon={<ActivityIcon className={icon} />} title={t('backup.cards.wal')} component={wal}>
                <FactRow label={t('backup.archiveMode')}><code className="font-mono text-xs">{wal.archive_mode ?? t('backup.unknown')}</code></FactRow>
                <FactRow label={t('backup.lastWal')} title={time.absolute(wal.last_archived_at)}>{time.relative(wal.last_archived_at)}</FactRow>
                <FactRow label={t('backup.walFailures')}>{wal.failed_count ?? t('backup.unknown')}</FactRow>
                <FactRow label={t('backup.walBacklog')}>{wal.backlog ?? t('backup.unknown')}</FactRow>
            </ComponentCard>
            <ComponentCard icon={<ShieldCheck className={icon} />} title={t('backup.cards.verification')} component={status.components.verification}>
                <FactRow label={t('backup.lastVerified')} title={time.absolute(status.latest_verified_at)}>{time.relative(status.latest_verified_at)}</FactRow>
            </ComponentCard>
            <ComponentCard icon={<ClipboardCheckIcon className={icon} />} title={t('backup.cards.restoreTest')} component={status.components.restore_test}>
                <FactRow label={t('backup.lastRestoreTest')} title={time.absolute(status.latest_restore_test_at)}>{time.relative(status.latest_restore_test_at)}</FactRow>
            </ComponentCard>
        </section>
    );
}
