import { AlertTriangle, CheckCircle, ClockIcon, InfoIcon, MinusIcon, XCircle } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { StatusBadge as UiStatusBadge, type Tone } from '@euisis/ui';
import type { ReactNode } from 'react';
import type { Bilingual, EmployeeRef, HandlerRef, SlaSummary } from '@/types/grievances';

/*
 * Grievance page kit. Layout pieces are the shared EPMS/admin kit (same
 * cards, tables, buttons and pager as every admin page); this file adds the
 * grievance-specific helpers. Rules the pages follow:
 *  - status and SLA are always shown as text (+ icon), never colour alone;
 *  - no fake progress percentages;
 *  - dates through <LocalizedDateDisplay> (Ethiopian for AM, Gregorian for EN);
 *  - every visible string comes from i18n (grievances.* and the page namespace).
 */
export {
    Section, Details, Field, FieldError, Empty, Problems, Table, TablePanel, Pager, Stat,
    inputCls, compactInputCls, labelCls, primaryBtn, secondaryBtn, dangerBtn, smallBtn, smallPrimaryBtn,
    linkBtn, dangerLinkBtn, panelCls, pageCls, tableCls, thCls, tdCls, AppFilterBar, filterInputCls,
} from '@/Components/performance/ui';
export type { Paginator } from '@/Components/performance/ui';

/** `:name` placeholder interpolation (the app's t() does none). */
export function fmt(template: string, params: Record<string, string | number | null | undefined>): string {
    return Object.entries(params).reduce((text, [key, value]) => text.split(`:${key}`).join(value === null || value === undefined ? '' : String(value)), template);
}

/** Localized name of a bilingual record: Amharic in AM when present, else English. */
export function nameOf(value: Bilingual | { name_en?: string | null; name_am?: string | null } | null | undefined, locale: string): string {
    if (!value) return '—';
    return (locale === 'am' && value.name_am) || value.name_en || value.name_am || '—';
}

export function employeeName(e: EmployeeRef | null | undefined, locale: string): string {
    if (!e) return '—';
    return (locale === 'am' ? e.name : e.name_en) || e.name || e.name_en || '—';
}

/**
 * Enum labels: `label('status', 'under_review')` → grievances.enums.status.under_review.
 * Falls back to a readable form of the raw value, never a translation key.
 */
export function useEnumLabel() {
    const { t } = useLocale();
    return (group: string, value: string | null | undefined): string => {
        if (value === null || value === undefined || value === '') return '—';
        const key = `grievances.enums.${group}.${value}`;
        const text = t(key);
        return text === key ? value.replace(/_/g, ' ') : text;
    };
}

const TONES: Record<string, Tone> = {
    // case
    draft: 'neutral', submitted: 'info', intake_review: 'info', returned_for_correction: 'warning', rejected_at_intake: 'danger',
    under_review: 'info', awaiting_information: 'warning', hearing_scheduled: 'info', decision_drafting: 'info',
    pending_approval: 'warning', decision_issued: 'success', appealed: 'warning', referred_external: 'info',
    withdraw_requested: 'warning', withdrawn: 'neutral', closed: 'neutral',
    // stage
    pending: 'info', received: 'info', resolved: 'success', escalated: 'warning', referred: 'info', reassigned: 'neutral',
    // decision
    under_internal_review: 'info', pending_executive_approval: 'warning', resubmitted: 'warning', approved: 'success',
    rejected: 'danger', finalized: 'success', issued: 'success', superseded: 'neutral',
    // letters, dispatch, misc
    signed: 'info', voided: 'danger', queued: 'neutral', sent: 'info', delivered: 'success', failed: 'danger', acknowledged: 'success',
    active: 'success', inactive: 'neutral', pending_approval_committee: 'warning', retired: 'neutral', revoked: 'neutral',
    open: 'info', done: 'success', cancelled: 'neutral', completed: 'success', in_progress: 'info',
    scheduled: 'info', held: 'success', adjourned: 'warning', confirmed: 'success', accepted: 'success',
    declared: 'warning', routed: 'info', requested: 'warning', ended: 'neutral', responded: 'success',
    normal_confidential: 'neutral', restricted: 'warning', highly_restricted: 'danger',
    low: 'neutral', normal: 'neutral', high: 'warning', urgent: 'danger',
};

/** Status pill with a translated label and a restrained tone. */
export function GPill({ group, value, tone }: { group: string; value: string | null | undefined; tone?: Tone }) {
    const label = useEnumLabel();
    return <UiStatusBadge tone={tone ?? TONES[value ?? ''] ?? 'neutral'} className="badge">{label(group, value)}</UiStatusBadge>;
}

const SLA_STYLE: Record<SlaSummary["state"], { tone: string; Icon: typeof CheckCircle }> = {
    on_track: { tone: 'text-emerald-700 bg-emerald-50 ring-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/30 dark:ring-emerald-900', Icon: CheckCircle },
    due_soon: { tone: 'text-amber-800 bg-amber-50 ring-amber-200 dark:text-amber-300 dark:bg-amber-950/30 dark:ring-amber-900', Icon: ClockIcon },
    due_today: { tone: 'text-orange-800 bg-orange-50 ring-orange-200 dark:text-orange-300 dark:bg-orange-950/30 dark:ring-orange-900', Icon: AlertTriangle },
    overdue: { tone: 'text-red-700 bg-red-50 ring-red-200 dark:text-red-300 dark:bg-red-950/30 dark:ring-red-900', Icon: XCircle },
    paused: { tone: 'text-slate-700 bg-slate-100 ring-slate-200 dark:text-slate-300 dark:bg-slate-800 dark:ring-slate-700', Icon: MinusIcon },
    stopped: { tone: 'text-slate-600 bg-slate-50 ring-slate-200 dark:text-slate-400 dark:bg-slate-900 dark:ring-slate-700', Icon: MinusIcon },
    no_deadline: { tone: 'text-slate-600 bg-slate-50 ring-slate-200 dark:text-slate-400 dark:bg-slate-900 dark:ring-slate-700', Icon: InfoIcon },
};

/**
 * SLA indicator: icon + text (+ remaining days), never colour alone.
 * `compact` drops the remaining-days line for table cells.
 */
export function SlaBadge({ sla, compact = false }: { sla: Pick<SlaSummary, 'state'> & Partial<SlaSummary> | null | undefined; compact?: boolean }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    if (!sla) return <span className="text-xs text-gray-400">—</span>;
    const { tone, Icon } = SLA_STYLE[sla.state] ?? SLA_STYLE.no_deadline;
    const days = sla.remaining_days;
    const unit = sla.day_type === 'calendar_days' ? t('grievances.common.calendar_days_short') : t('grievances.common.working_days_short');
    const detail = !compact && days !== null && days !== undefined && sla.state !== 'stopped'
        ? (days >= 0 ? fmt(t('grievances.common.days_left'), { n: days, unit }) : fmt(t('grievances.common.days_over'), { n: Math.abs(days), unit }))
        : null;

    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tone}`}>
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            <span>{label('sla_state', sla.state)}</span>
            {detail && <span className="font-normal opacity-80">· {detail}</span>}
        </span>
    );
}

/** Handler name with its organization (handlers can sit in another organization). */
export function HandlerName({ handler, showType = false }: { handler: HandlerRef | null | undefined; showType?: boolean }) {
    const { locale } = useLocale();
    const label = useEnumLabel();
    if (!handler || !handler.type) return <span>—</span>;
    const org = (locale === 'am' && handler.organization_name_am) || handler.organization_name_en;
    return (
        <span className="min-w-0">
            <span className="font-medium text-gray-900 dark:text-slate-100">{(locale === 'am' && handler.name_am) || handler.name_en || '—'}</span>
            {(org || showType) && (
                <span className="block text-xs text-gray-500 dark:text-slate-400">
                    {showType ? label('handler_type', handler.type) : null}{showType && org ? ' · ' : ''}{org}
                </span>
            )}
        </span>
    );
}

/** Case number in the monospace style used for official references. */
export function CaseNumber({ value }: { value: string | null | undefined }) {
    return <span className="font-mono text-[13px] font-semibold tracking-tight text-[color:var(--color-primary)]">{value ?? '—'}</span>;
}

/** Confidentiality notice shown on every case page (text + icon). */
export function ConfidentialNotice({ level }: { level: string | null | undefined }) {
    const { t } = useLocale();
    const label = useEnumLabel();
    return (
        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            <InfoIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{t('grievances.common.confidential_notice')} <strong className="font-medium">{label('confidentiality', level)}</strong></span>
        </div>
    );
}

/** Human file size. */
export function fileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Simple labelled textarea/select/input wrappers for action forms. */
export function FormRow({ label, error, children, help }: { label: string; error?: string; children: ReactNode; help?: string }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">{label}</span>
            {children}
            {help && <span className="mt-1 block text-xs text-gray-500 dark:text-slate-400">{help}</span>}
            {error && <span className="mt-1 block text-xs text-red-600 dark:text-red-400">{error}</span>}
        </label>
    );
}
