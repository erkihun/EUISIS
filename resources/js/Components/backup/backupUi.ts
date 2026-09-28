import { useLocale } from '@/hooks/useLocale';
import { formatRelative, toDate } from '@/lib/relativeTime';
import type { Tone } from '@euisis/ui';

export type Backup = { reference: string; type: string; completed_at: number; size: number };
export type HealthComponent = { status: string; reason_code: string | null };
export type Issue = { code: string; reason: string; severity: 'CRITICAL' | 'WARNING' | 'READINESS' };
export type Repository = {
    id: number; name: string; status: string; backup_status: string; backup_reason: string | null; encrypted: boolean | null;
    latest: Backup | null; full: Backup | null; daily: Backup | null; age_seconds: number | null; usage_percent: number | null;
    wal_max: string | null; errored_backups: number; last_verified_at: number | null; verify_status: string;
    last_restore_test_at: number | null; restore_test_status: string;
};
export type BackupHealth = {
    overall_status: string; infrastructure_status: string; repository_status: string; backup_status: string; wal_status: string;
    verification_status: string; restore_test_status: string; reason_code: string | null; message: string; readiness: string;
    production_blocker: boolean; enforced: boolean; environment: string; checked_at: number;
    latest_full_backup_at: number | null; latest_incremental_backup_at: number | null; latest_backup_at: number | null;
    backup_age_seconds: number | null; latest_wal_at: number | null; latest_verified_at: number | null;
    latest_restore_test_at: number | null; latest_logical_backup_at: number | null;
    driver: string; stanza: string | null; binary: string | null; version: string | null;
    components: {
        infrastructure: HealthComponent; repository: HealthComponent; backup: HealthComponent; verification: HealthComponent; restore_test: HealthComponent;
        wal: HealthComponent & { archive_mode: string | null; last_archived_at: number | null; failed_count: number | null; backlog: number | null };
    };
    repositories: Repository[]; issues: Issue[]; controls: Record<string, boolean>; rpo: string; rto: string;
};
export type Recovery = {
    id: string; restore_type: string; incident_reference: string; reason: string; target_time: string | null; backup_reference: string | null;
    status: string; evidence_reference: string | null; failure_summary: string | null; created_at: string; can_cancel: boolean;
    requester: string | null; reviewer: string | null; approver: string | null; production_authorizer: string | null;
};
export type Policy = {
    retention_full_count: number; stale_hours: number; critical_hours: number; restore_test_days: number; storage_warning_percent: number;
    logical_enabled: boolean; pitr_required: boolean; repository_names: Record<string, string>;
};
export type HistoryItem = {
    id: string; type: string; status: string; repository: number | null; started_at: string; failure_summary: string | null;
    backup_reference: string | null; recovery_target: string | null;
};
export type RequestPermissions = { request: boolean; review: boolean; approve: boolean };

const SUCCESS = ['HEALTHY', 'AVAILABLE', 'PASSED', 'NOT_REQUIRED', 'FOUND', 'SUCCEEDED', 'COMPLETED', 'READY'];
const WARNING = ['WARNING', 'OVERDUE', 'NOT_RECORDED', 'RUNNING', 'NOT_EXECUTABLE', 'NOT_READY', 'NEEDS_DECISION'];
const NEUTRAL = ['NOT_CONFIGURED', 'NOT_CHECKED', 'UNKNOWN', 'CANCELLED', 'REJECTED', 'CONFIGURED'];
const INFO = ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'TEST_RESTORE_RUNNING', 'TEST_RESTORE_VERIFIED', 'PRODUCTION_RESTORE_AUTHORIZED', 'RESTORING'];
// Missing infrastructure is expected outside production: explained, not alarming (and never green).
const EXPECTED_IN_DEVELOPMENT = ['NOT_CONFIGURED', 'INFRASTRUCTURE_UNAVAILABLE', 'UNKNOWN'];

export function toneFor(status: string): Tone {
    if (SUCCESS.includes(status)) return 'success';
    if (WARNING.includes(status)) return 'warning';
    if (NEUTRAL.includes(status)) return 'neutral';
    if (INFO.includes(status)) return 'info';
    return 'danger';
}

export function overallTone(status: BackupHealth): Tone {
    return !status.enforced && EXPECTED_IN_DEVELOPMENT.includes(status.overall_status) ? 'info' : toneFor(status.overall_status);
}

/** Classes for custom tone surfaces, matching the design system's StatusBadge palette. */
export const toneClasses: Record<Tone, { soft: string; text: string; solid: string }> = {
    success: { soft: 'bg-emerald-50 dark:bg-emerald-950/40', text: 'text-emerald-700 dark:text-emerald-300', solid: 'bg-emerald-600 dark:bg-emerald-500' },
    warning: { soft: 'bg-amber-50 dark:bg-amber-950/40', text: 'text-amber-700 dark:text-amber-300', solid: 'bg-amber-500' },
    danger: { soft: 'bg-red-50 dark:bg-red-950/40', text: 'text-red-700 dark:text-red-300', solid: 'bg-red-600 dark:bg-red-500' },
    info: {
        soft: 'bg-[color:var(--color-primary-50)] dark:bg-[color:var(--color-primary-950)]',
        text: 'text-[color:var(--color-primary-700)] dark:text-[color:var(--color-primary-200)]',
        solid: 'bg-[color:var(--color-primary)]',
    },
    neutral: { soft: 'bg-gray-100 dark:bg-slate-800', text: 'text-gray-600 dark:text-slate-300', solid: 'bg-gray-400 dark:bg-slate-500' },
};

// Status and reason codes come from the server; unknown codes fall back to readable text.
export function useBackupText() {
    const { t, locale } = useLocale();
    const readable = (code: string) => code.replaceAll('_', ' ').toLowerCase().replace(/^\w/, (letter) => letter.toUpperCase());
    const lookup = (key: string, fallback: string) => {
        const value = t(key);
        return value === key ? fallback : value;
    };

    return {
        t,
        locale,
        state: (code: string) => lookup(`backup.states.${code}`, readable(code)),
        reason: (code: string | null, fallback?: string) => (code ? lookup(`backup.reasons.${code}`, fallback ?? readable(code)) : ''),
        operation: (type: string) => lookup(`backup.operationTypes.${type}`, readable(type)),
    };
}

/** Environment-aware one-line summary shown under the overall status. */
export function headline(status: BackupHealth, text: ReturnType<typeof useBackupText>): string {
    if (status.infrastructure_status !== 'AVAILABLE') {
        return status.enforced ? text.t('backup.headlines.unavailable') : text.t('backup.headlines.notConfigured');
    }
    return status.overall_status === 'HEALTHY' ? text.t('backup.headlines.healthy') : text.reason(status.reason_code, status.message);
}

// Recovery decisions depend on exact times, so absolute times always carry the time zone. Intl rejects
// dateStyle/timeStyle combined with timeZoneName, so the components are listed explicitly.
const TIME_FORMAT: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' };

export function useTime() {
    const { t, locale } = useLocale();
    const absolute = (value?: number | string | null) => toDate(value)?.toLocaleString(undefined, TIME_FORMAT) ?? t('backup.unknown');
    const relative = (value?: number | string | null) => formatRelative(value, locale) ?? t('backup.unknown');

    return { absolute, relative };
}
