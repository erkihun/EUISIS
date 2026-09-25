import PortalPage from '@/Components/employees/portal/PortalPage';
import type { BilingualLabels } from '@/Components/employees/portal/labels';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { Bar, Pill, formatScore } from '@/Components/performance/ui';
import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import type { PageProps } from '@/types';
import type { ReactNode, SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

/* ── icon set ────────────────────────────────────────────────────────────── */
const Ic = {
    User:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M18 20a6 6 0 0 0-12 0"/><circle cx="12" cy="10" r="4"/></svg>,
    Building:   (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>,
    Briefcase:  (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>,
    Card:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>,
    ArrowRight: (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>,
    Layers:     (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>,
    Clock:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>,
    Check:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>,
    Alert:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>,
    Mail:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>,
    Phone:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1.25h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.29 6.29l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>,
    Clipboard:  (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>,
    Target:     (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>,
    Bell:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>,
};

/* ── types ───────────────────────────────────────────────────────────────── */
type DashboardDay = { date: string; status: string; is_late: boolean; items_count: number };
type PortalProps = PageProps & {
    daily_activity: { today: string; today_status: string; week: DashboardDay[]; recent: DashboardDay[]; required: number; submitted: number; missing: number; returned: number } | null;
    /** EPMS: own agreement at a glance. Scores appear only once released. */
    performance?: {
        agreement: { id: string; status: string; cycle: { name_en: string | null; name_am: string | null }; effective_from: string | null; effective_to: string | null } | null;
        kpis: { code: string; name_en: string; name_am: string | null; weight: string; achievement: string | null; health: string }[];
        counts: { total: number; on_track: number; attention: number; not_reported: number } | null;
        result: { final_score: string | null; rating_en: string | null; rating_am: string | null } | null;
        actions: { key: 'acknowledge' | 'self_assessment' | 'review_returned' | 'result_released'; review?: 'MID_YEAR' | 'YEAR_END' }[];
    } | null;
    notifications: { unread: number; latest: { id: string; title: string; message: string; read: boolean; created_at: string | null }[] };
    pending_requests: number;
    holidays: { date: string; name_en: string; name_am: string | null }[];
    requests: { id: string; field: string; status: string; created_at: string | null }[];
    portal_labels?: BilingualLabels;
    employee: { id: string; full_name: string | null; employee_number: string | null; status: string | null; photo_url: string | null; email: string | null; phone: string | null; } | null;
    assignment: { organization: string | null; organization_am: string | null; organization_unit: string | null; organization_unit_am: string | null; position: string | null; position_am: string | null; grade_level: string | null; effective_from: string | null; } | null;
    id_card: { card_number: string | null; status: string; expires_at: string | null; is_active: boolean; reprint_required?: boolean; issued_at?: string | null; } | null;
    entitlements: { id: string; service: string | null; service_am: string | null; service_code: string | null; quota_limit: number | null; quota_used: number | null; effective_to: string | null; }[];
    transfer_apps: { id: string; status: string; status_label: string; submitted_at: string | null; organization: string | null; organization_am: string | null; position: string | null; position_am: string | null; announcement_id: string; }[];
    open_announcements: { id: string; organization: string | null; organization_am: string | null; position: string | null; position_am: string | null; grade_level: string | null; vacancies: number; closing_date: string | null; }[];
};

/* ── small helpers ───────────────────────────────────────────────────────── */
const APP_COLOR: Record<string, string> = {
    submitted:              'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
    under_review:           'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    verified:               'bg-purple-100 text-purple-700 dark:bg-purple-950/50 dark:text-purple-300',
    selected:               'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    approved:               'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    rejected:               'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
    withdrawn:              'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400',
    transferred:            'bg-teal-100 text-teal-700 dark:bg-teal-950/50 dark:text-teal-300',
    release_pending:        'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    receiving_pending:      'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    final_approval_pending: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
};

function StatusPill({ status, label }: { status: string; label?: string }) {
    return (
        <span className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${APP_COLOR[status] ?? 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
            {label ?? status.replace(/_/g, ' ')}
        </span>
    );
}

function SectionHeading({ icon: I, title, href }: { icon: (p: IconProps) => JSX.Element; title: string; href?: string }) {
    const { t } = useLocale();

    return (
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h2 className="flex items-center gap-3 text-base font-semibold text-gray-900 dark:text-slate-100">
                <span className="portal-section-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"><I className="h-5 w-5" aria-hidden="true" /></span> {title}
            </h2>
            {href && (
                <Link href={href} className="flex items-center gap-0.5 text-xs text-[var(--color-primary)] hover:underline">
                    {t('employeePortal.viewAll')} <Ic.ArrowRight className="h-3 w-3" />
                </Link>
            )}
        </div>
    );
}

/* ── dashboard building blocks ───────────────────────────────────────────── */

const panel = 'portal-panel rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900';

/** One summary figure. Plain text on a quiet surface; colour only when it signals action. */
function Figure({ label, value, caption, tone = 'default', href }: {
    label: string;
    value: ReactNode;
    caption?: ReactNode;
    tone?: 'default' | 'good' | 'warn' | 'bad';
    href?: string;
}) {
    const valueCls = {
        default: 'text-gray-900 dark:text-slate-100',
        good: 'text-emerald-700 dark:text-emerald-400',
        warn: 'text-amber-700 dark:text-amber-400',
        bad: 'text-red-700 dark:text-red-400',
    }[tone];
    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">{label}</p>
                {href && <Ic.ArrowRight className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />}
            </div>
            <p className={`mt-3 text-2xl font-semibold leading-tight tracking-tight ${valueCls}`}>{value}</p>
            {caption && <p className="mt-2 text-xs leading-relaxed text-gray-500 dark:text-slate-400">{caption}</p>}
        </>
    );

    return href ? (
        <Link href={href} className={`${panel} block p-4 transition-colors hover:border-[var(--color-primary)]/40`}>{body}</Link>
    ) : (
        <div className={`${panel} p-4`}>{body}</div>
    );
}

/* Day marks match My Activity Calendar so the two never disagree visually. */
const DAY_MARK: Record<string, { symbol: string; cls: string }> = {
    submitted: { symbol: '✓', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900' },
    approved: { symbol: '✓', cls: 'bg-emerald-100 text-emerald-900 ring-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-200 dark:ring-emerald-800' },
    missing: { symbol: '!', cls: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900' },
    returned: { symbol: '↩', cls: 'bg-amber-50 text-amber-900 ring-amber-300 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900' },
    draft: { symbol: '…', cls: 'bg-gray-50 text-gray-700 ring-gray-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600' },
    required: { symbol: '•', cls: 'bg-[var(--color-primary)]/10 text-[var(--color-primary)] ring-[var(--color-primary)]/40' },
    leave: { symbol: 'L', cls: 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900' },
    public_holiday: { symbol: 'H', cls: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-950/40 dark:text-violet-300 dark:ring-violet-900' },
};
const QUIET_MARK = { symbol: '–', cls: 'bg-transparent text-gray-400 ring-gray-100 dark:text-slate-600 dark:ring-slate-800' };

/* ── main page ───────────────────────────────────────────────────────────── */
export default function EmployeePortal({ employee, assignment, id_card, entitlements, transfer_apps, daily_activity, performance = null, notifications, pending_requests, holidays, requests }: PortalProps) {
    const { t, locale } = useLocale();
    const { props } = usePage<PortalProps>();
    const user = props.auth?.user;

    /* Every localized value on this page resolves through one pair of helpers
     * so the Amharic reading is never dropped on the floor. */
    const name = (en: string | null, am: string | null) => localizedName(en ?? '', am, locale) || '—';
    const fill = (key: string, values: Record<string, string | number>) =>
        Object.entries(values).reduce((text, [token, value]) => text.replace(`:${token}`, String(value)), t(key));

    const displayName = employee?.full_name ?? user?.name ?? '';
    const assignmentPosition = assignment ? name(assignment.position, assignment.position_am) : null;
    const placement = [assignmentPosition, assignment ? name(assignment.organization_unit, assignment.organization_unit_am) : null]
        .filter((part) => part && part !== '—')
        .join(' · ');

    if (!employee) {
        return (
            <PortalPage title={t('employeePortal.title')}>
                <div className="rounded-panel border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-900/50 dark:bg-amber-950/20">
                    <Ic.Alert className="mx-auto mb-3 h-10 w-10 text-amber-500" />
                    <p className="font-medium text-amber-800 dark:text-amber-300">{t('employeePortal.noProfileTitle')}</p>
                    <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">{t('employeePortal.noProfileBody')}</p>
                </div>
            </PortalPage>
        );
    }

    /*
     * What the employee should do next, most urgent first. Only real,
     * actionable items appear; nothing is padded in to fill the space.
     */
    const todos: { key: string; text: string; href: string; tone: 'bad' | 'warn' | 'info' }[] = [];
    if (daily_activity) {
        if (daily_activity.today_status === 'required') {
            todos.push({ key: 'today', text: t('employeePortal.todoRegisterToday'), href: route('employee.daily-activity.entry'), tone: 'info' });
        } else if (daily_activity.today_status === 'draft') {
            todos.push({ key: 'draft', text: t('employeePortal.todoFinishDraft'), href: route('employee.daily-activity.entry'), tone: 'info' });
        }
        if (daily_activity.returned > 0) {
            todos.push({ key: 'returned', text: fill('employeePortal.todoReturned', { count: daily_activity.returned }), href: route('employee.daily-activity.history', { status: 'returned_for_correction' }), tone: 'warn' });
        }
        if (daily_activity.missing > 0) {
            todos.push({ key: 'missing', text: fill('employeePortal.todoMissing', { count: daily_activity.missing }), href: route('employee.daily-activity.calendar'), tone: 'bad' });
        }
    }
    for (const action of performance?.actions ?? []) {
        const phase = action.review === 'MID_YEAR' ? 'MidYear' : 'YearEnd';
        const href = route('employee.performance.index', performance?.agreement ? { agreement: performance.agreement.id } : {});
        if (action.key === 'acknowledge') todos.push({ key: 'epms-ack', text: t('employeePortal.todoAcknowledgeAgreement'), href, tone: 'warn' });
        if (action.key === 'review_returned') todos.push({ key: `epms-returned-${phase}`, text: t(`employeePortal.todoReviewReturned${phase}`), href, tone: 'warn' });
        if (action.key === 'self_assessment') todos.push({ key: `epms-self-${phase}`, text: t(`employeePortal.todoSelfAssessment${phase}`), href, tone: 'info' });
        if (action.key === 'result_released') todos.push({ key: 'epms-result', text: t('employeePortal.todoResultReleased'), href, tone: 'info' });
    }
    if (id_card?.reprint_required) {
        todos.push({ key: 'reprint', text: t('employeePortal.todoReprint'), href: route('employee.id-card'), tone: 'warn' });
    }
    if (notifications.unread > 0) {
        todos.push({ key: 'unread', text: fill('employeePortal.todoUnread', { count: notifications.unread }), href: route('employee.notifications'), tone: 'info' });
    }
    if (pending_requests > 0) {
        todos.push({ key: 'requests', text: fill('employeePortal.todoPendingRequests', { count: pending_requests }), href: route('employee.requests'), tone: 'info' });
    }
    const toneDot = { bad: 'bg-red-500', warn: 'bg-amber-500', info: 'bg-[var(--color-primary)]' };

    const perf = performance;
    const perfAgreement = perf?.agreement ?? null;
    const perfTone = perf?.actions.some((a) => a.key === 'acknowledge' || a.key === 'review_returned') || (perf?.counts?.attention ?? 0) > 0
        ? 'warn'
        : perf?.result ? 'good' : 'default';
    const figureCount = (daily_activity ? 2 : 1) + 2 + (perf ? 1 : 0);
    const figureGrid = figureCount >= 5 ? 'xl:grid-cols-3 2xl:grid-cols-5' : figureCount === 4 ? '2xl:grid-cols-4' : 'xl:grid-cols-3';

    const today = daily_activity?.today_status;
    const todayTone = today === 'submitted' || today === 'approved' ? 'good' : today === 'returned' ? 'warn' : today === 'required' || today === 'draft' ? 'warn' : 'default';

    return (
        <PortalPage
            title={t('employeePortal.title')}
            description={[displayName ? fill('employeePortal.welcome', { name: displayName }) : '', placement].filter(Boolean).join(' · ')}
            actions={daily_activity ? (
                <Link href={route('employee.daily-activity.entry')} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90">
                    <Ic.Clipboard className="h-4 w-4" /> {t('employeePortal.registerActivity')}
                </Link>
            ) : undefined}
        >
            {/* ── Needs your attention ─────────────────────────────────────── */}
            <section className={panel} aria-labelledby="attention-title">
                <h2 id="attention-title" className="border-b border-gray-100 px-5 py-4 text-base font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                    {t('employeePortal.attentionTitle')}
                    {todos.length > 0 && <span className="ms-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">{todos.length}</span>}
                </h2>
                {todos.length === 0 ? (
                    <p className="flex items-center gap-2 px-4 py-3 text-sm text-gray-600 dark:text-slate-300">
                        <Ic.Check className="h-4 w-4 text-emerald-600" /> {t('employeePortal.allClear')}
                    </p>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                        {todos.map((todo) => (
                            <li key={todo.key}>
                                <Link href={todo.href} className="flex min-h-14 items-center gap-3 px-5 py-3 text-sm hover:bg-gray-50 dark:hover:bg-slate-800/50">
                                    <span className={`h-2 w-2 shrink-0 rounded-full ${toneDot[todo.tone]}`} aria-hidden="true" />
                                    <span className="min-w-0 flex-1 text-gray-800 dark:text-slate-200">{todo.text}</span>
                                    <span className="flex shrink-0 items-center gap-0.5 text-xs font-medium text-[var(--color-primary)]">
                                        {t('employeePortal.open')} <Ic.ArrowRight className="h-3 w-3" />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* ── Summary figures ──────────────────────────────────────────── */}
            <div className={`grid grid-cols-1 gap-4 sm:grid-cols-2 ${figureGrid}`}>
                {daily_activity ? (
                    <>
                        <Figure
                            label={t('employeePortal.todayActivity')}
                            value={today ? t(`dailyActivities.dayStatuses.${today}`) : '—'}
                            caption={<LocalizedDateDisplay value={daily_activity.today} />}
                            tone={todayTone}
                            href={route('employee.daily-activity.entry')}
                        />
                        <Figure
                            label={t('employeePortal.weekActivity')}
                            value={`${daily_activity.submitted} / ${daily_activity.required}`}
                            caption={daily_activity.missing > 0
                                ? <span className="text-red-700 dark:text-red-400">{fill('employeePortal.missingCount', { count: daily_activity.missing })}</span>
                                : fill('employeePortal.submittedOf', { submitted: daily_activity.submitted, required: daily_activity.required })}
                            tone={daily_activity.missing > 0 ? 'bad' : 'default'}
                            href={route('employee.daily-activity.calendar')}
                        />
                    </>
                ) : (
                    <Figure
                        label={t('employeePortal.activeBenefits')}
                        value={entitlements.length}
                        href={route('employee.services')}
                    />
                )}
                {perf && (
                    <Figure
                        label={t('employeePortal.myPerformance')}
                        value={perf.result
                            ? <>{formatScore(perf.result.final_score)} <span className="text-base font-medium">{name(perf.result.rating_en, perf.result.rating_am)}</span></>
                            : perfAgreement ? t(`performance.enums.agreement.${perfAgreement.status}`) : t('employeePortal.noAgreementShort')}
                        caption={perf.counts && perf.counts.total > 0
                            ? [fill('employeePortal.kpiCount', { count: perf.counts.total }), fill('employeePortal.kpiOnTrack', { count: perf.counts.on_track })].join(' · ')
                            : perfAgreement ? name(perfAgreement.cycle.name_en, perfAgreement.cycle.name_am) : undefined}
                        tone={perfTone}
                        href={route('employee.performance.index', performance?.agreement ? { agreement: performance.agreement.id } : {})}
                    />
                )}
                <Figure
                    label={t('employeePortal.idCard')}
                    value={id_card ? <span className="capitalize">{id_card.reprint_required ? t('employeePortal.reprintRequired') : id_card.status.replace(/_/g, ' ')}</span> : t('employeePortal.noCard')}
                    caption={id_card?.expires_at ? <>{t('employeePortal.expiresShort')} <LocalizedDateDisplay value={id_card.expires_at} /></> : id_card?.card_number ?? undefined}
                    tone={id_card?.reprint_required ? 'warn' : id_card?.is_active ? 'good' : 'default'}
                    href={route('employee.id-card')}
                />
                <Figure
                    label={t('employeePortal.unreadMessages')}
                    value={notifications.unread}
                    tone={notifications.unread > 0 ? 'warn' : 'default'}
                    href={route('employee.notifications')}
                />
            </div>

            {/* ── Main grid ────────────────────────────────────────────────── */}
            <div className="grid gap-4 xl:grid-cols-3">
                <div className="space-y-4 xl:col-span-2">

                    {/* Daily activity: this week at a glance */}
                    {daily_activity && (
                        <section className={`${panel} p-4`}>
                            <SectionHeading icon={Ic.Clipboard} title={t('employeePortal.dailyActivity')} href={route('employee.daily-activity.calendar')} />
                            <div className="grid grid-cols-7 gap-1.5">
                                {daily_activity.week.map((day) => {
                                    const mark = DAY_MARK[day.status] ?? QUIET_MARK;
                                    const isToday = day.date === daily_activity.today;
                                    const label = t(`dailyActivities.dayStatuses.${day.status}`);
                                    const cell = (
                                        <>
                                            <span className="text-[10px] font-medium opacity-80">
                                                {new Date(`${day.date}T00:00:00Z`).toLocaleDateString(locale === 'am' ? 'am-ET' : 'en-GB', { weekday: 'short', timeZone: 'UTC' })}
                                            </span>
                                            <span className="text-base font-semibold leading-none" aria-hidden="true">{mark.symbol}</span>
                                        </>
                                    );
                                    const cls = `flex h-16 flex-col items-center justify-between rounded-control p-1.5 ring-1 ring-inset ${mark.cls} ${isToday ? 'outline outline-2 outline-offset-1 outline-[var(--color-primary)]' : ''}`;
                                    const title = [label, day.items_count ? fill('employeePortal.activityItems', { count: day.items_count }) : ''].filter(Boolean).join(' · ');
                                    const registrable = !['weekend', 'public_holiday', 'leave', 'future', 'not_employed', 'not_assigned'].includes(day.status);

                                    return registrable ? (
                                        <Link key={day.date} href={route('employee.daily-activity.entry', { date: day.date })} className={`${cls} hover:brightness-95`} title={title} aria-label={title}>{cell}</Link>
                                    ) : (
                                        <div key={day.date} className={cls} title={title} aria-label={title}>{cell}</div>
                                    );
                                })}
                            </div>
                            <p className="mt-3 text-xs text-gray-500 dark:text-slate-400">
                                {fill('employeePortal.submittedOf', { submitted: daily_activity.submitted, required: daily_activity.required })}
                                {daily_activity.missing > 0 && <> · <span className="text-red-700 dark:text-red-400">{fill('employeePortal.missingCount', { count: daily_activity.missing })}</span></>}
                            </p>

                            <h3 className="mb-1 mt-4 text-xs font-semibold text-gray-500 dark:text-slate-400">{t('employeePortal.recentDays')}</h3>
                            {daily_activity.recent.length === 0 ? (
                                <p className="text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noActivityYet')}</p>
                            ) : (
                                <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {daily_activity.recent.map((day) => (
                                        <li key={day.date}>
                                            <Link href={route('employee.daily-activity.entry', { date: day.date })} className="flex items-center justify-between gap-2 py-2 text-sm hover:text-[var(--color-primary)]">
                                                <LocalizedDateDisplay value={day.date} className="text-gray-800 dark:text-slate-200" />
                                                <span className="flex items-center gap-2 text-xs text-gray-500 dark:text-slate-400">
                                                    {fill('employeePortal.activityItems', { count: day.items_count })}
                                                    <StatusBadge status={day.status === 'returned_for_correction' ? 'returned' : day.status} label={t(`dailyActivities.statuses.${day.status}`)} />
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}

                    {/* My Performance: agreement and KPI progress (evidence-based, not a preliminary score) */}
                    {perf && (
                        <section className={`${panel} p-4`}>
                            <SectionHeading icon={Ic.Target} title={t('employeePortal.myPerformance')} href={route('employee.performance.index', performance?.agreement ? { agreement: performance.agreement.id } : {})} />
                            {!perfAgreement ? (
                                <p className="text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noPerformanceAgreement')} {t('performance.my.noAgreementHelp')}</p>
                            ) : (
                                <>
                                    <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-slate-400">
                                        <Pill group="agreement" value={perfAgreement.status} />
                                        <span>{name(perfAgreement.cycle.name_en, perfAgreement.cycle.name_am)}</span>
                                        <span><LocalizedDateDisplay value={perfAgreement.effective_from} /> – <LocalizedDateDisplay value={perfAgreement.effective_to} /></span>
                                    </div>
                                    {perf.result && (
                                        <p className="mb-3 text-sm text-gray-800 dark:text-slate-200">
                                            {t('performance.agreements.result')}: <span className="font-semibold tabular-nums">{formatScore(perf.result.final_score)}</span> · {name(perf.result.rating_en, perf.result.rating_am)}
                                        </p>
                                    )}
                                    {perf.kpis.length === 0 ? (
                                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('performance.dashboard.noData')}</p>
                                    ) : (
                                        <ul className="space-y-3">
                                            {perf.kpis.map((kpi) => (
                                                <li key={kpi.code}>
                                                    <div className="mb-1 flex items-center justify-between gap-3 text-sm">
                                                        <span className="min-w-0 truncate text-gray-800 dark:text-slate-200">{kpi.code} — {name(kpi.name_en, kpi.name_am)}</span>
                                                        <span className="flex shrink-0 items-center gap-2 text-xs">
                                                            <span className="tabular-nums text-gray-600 dark:text-slate-300">{kpi.achievement === null ? '—' : `${formatScore(kpi.achievement)}%`}</span>
                                                            <Pill group="health" value={kpi.health} />
                                                        </span>
                                                    </div>
                                                    <Bar value={kpi.achievement} />
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {perf.counts && perf.counts.total > 0 && (
                                        <p className="mt-3 text-xs text-gray-600 dark:text-slate-300">
                                            {[
                                                fill('employeePortal.kpiCount', { count: perf.counts.total }),
                                                fill('employeePortal.kpiOnTrack', { count: perf.counts.on_track }),
                                                perf.counts.attention > 0 ? fill('employeePortal.kpiAttention', { count: perf.counts.attention }) : '',
                                                perf.counts.not_reported > 0 ? fill('employeePortal.kpiNotReported', { count: perf.counts.not_reported }) : '',
                                            ].filter(Boolean).join(' · ')}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('performance.my.progressNote')}</p>
                                </>
                            )}
                        </section>
                    )}

                    {/* Transfer applications */}
                    <section className={`${panel} p-4`}>
                        <SectionHeading icon={Ic.Clock} title={t('employeePortal.myTransferApplications')} href={route('employee.transfer-applications')} />
                        {transfer_apps.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-slate-400">
                                {t('employeePortal.noApplications')}{' '}
                                <Link href={route('employee.announcements')} className="text-[var(--color-primary)] hover:underline">{t('employeePortal.browseAnnouncements')}</Link>
                            </p>
                        ) : (
                            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                {transfer_apps.slice(0, 3).map((app) => (
                                    <li key={app.id} className="flex items-center gap-3 py-2">
                                        <div className="min-w-0 flex-1">
                                            <Link href={route('employee.announcements.show', { announcement: app.announcement_id })} className="text-sm font-medium text-gray-900 hover:text-[var(--color-primary)] dark:text-slate-100">
                                                {name(app.position, app.position_am)}
                                            </Link>
                                            <p className="truncate text-xs text-gray-500 dark:text-slate-400">{name(app.organization, app.organization_am)}{app.submitted_at && <> · <LocalizedDateDisplay value={app.submitted_at} /></>}</p>
                                        </div>
                                        <StatusPill status={app.status} label={app.status_label} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* My requests: correction requests and where they stand */}
                    <section className={`${panel} p-4`}>
                        <SectionHeading icon={Ic.Clipboard} title={t('employeePortal.myRequests')} href={route('employee.requests')} />
                        {requests.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-slate-400">
                                {t('employeePortal.noRequests')}{' '}
                                <Link href={route('employee.requests')} className="text-[var(--color-primary)] hover:underline">{t('employeePortal.newRequest')}</Link>
                            </p>
                        ) : (
                            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                {requests.map((request) => (
                                    <li key={request.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium text-gray-900 dark:text-slate-100">{t(`employeePortal.correctionFields.${request.field}`)}</span>
                                            <span className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={request.created_at} /></span>
                                        </span>
                                        <StatusBadge status={request.status} label={t(`employeePortal.requestStatuses.${request.status}`)} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                {/* ── Side column ──────────────────────────────────────────── */}
                <div className="space-y-4">

                    {/* Profile */}
                    <section className={`${panel} p-4`}>
                        <div className="flex items-center gap-3">
                            {employee.photo_url ? (
                                <img src={employee.photo_url} alt="" className="h-14 w-11 rounded-card object-cover" />
                            ) : (
                                <div className="flex h-14 w-11 items-center justify-center rounded-card bg-[var(--color-primary)]/10">
                                    <Ic.User className="h-7 w-7 text-[var(--color-primary)]" />
                                </div>
                            )}
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-semibold text-gray-900 dark:text-slate-100">{employee.full_name}</p>
                                <p className="text-xs text-gray-500 dark:text-slate-400">#{employee.employee_number}</p>
                            </div>
                            <Link href={route('employee.profile')} className="shrink-0 text-xs font-medium text-[var(--color-primary)] hover:underline">{t('employeePortal.myProfile')}</Link>
                        </div>
                        <dl className="mt-3 space-y-2 border-t border-gray-100 pt-3 text-sm dark:border-slate-800">
                            {assignment?.organization && (
                                <div className="flex items-start gap-2">
                                    <Ic.Building className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="text-gray-700 dark:text-slate-300">{name(assignment.organization, assignment.organization_am)}</span>
                                </div>
                            )}
                            {assignment?.position && (
                                <div className="flex items-start gap-2">
                                    <Ic.Briefcase className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="text-gray-700 dark:text-slate-300">{assignmentPosition}{assignment.grade_level && <span className="ms-1 text-gray-400">· {fill('employeePortal.gradeShort', { grade: assignment.grade_level })}</span>}</span>
                                </div>
                            )}
                            {employee.email && (
                                <div className="flex items-center gap-2">
                                    <Ic.Mail className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="truncate text-gray-700 dark:text-slate-300">{employee.email}</span>
                                </div>
                            )}
                            {employee.phone && (
                                <div className="flex items-center gap-2">
                                    <Ic.Phone className="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="text-gray-700 dark:text-slate-300">{employee.phone}</span>
                                </div>
                            )}
                        </dl>
                    </section>

                    {/* ID card */}
                    <section className={`${panel} p-4`}>
                        <SectionHeading icon={Ic.Card} title={t('employeePortal.idCard')} href={route('employee.id-card')} />
                        {id_card ? (
                            <>
                                {id_card.reprint_required && (
                                    <p className="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                                        {t('employeePortal.todoReprint')}
                                    </p>
                                )}
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <div className="col-span-2">
                                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('employeePortal.cardNumber')}</dt>
                                        <dd className="font-mono text-gray-900 dark:text-slate-100">{id_card.card_number ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('employeePortal.issued')}</dt>
                                        <dd className="text-gray-900 dark:text-slate-100"><LocalizedDateDisplay value={id_card.issued_at ?? null} /></dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-gray-500 dark:text-slate-400">{t('employeePortal.expires')}</dt>
                                        <dd className="text-gray-900 dark:text-slate-100"><LocalizedDateDisplay value={id_card.expires_at} /></dd>
                                    </div>
                                </dl>
                            </>
                        ) : (
                            <p className="text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noActiveCard')}</p>
                        )}
                    </section>

                    {/* Upcoming public holidays */}
                    <section className={`${panel} p-4`}>
                        <SectionHeading icon={Ic.Clock} title={t('employeePortal.upcomingHolidays')} />
                        {holidays.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noHolidays')}</p>
                        ) : (
                            <ul className="space-y-2">
                                {holidays.map((holiday) => (
                                    <li key={holiday.date} className="flex items-center justify-between gap-2 text-sm">
                                        <span className="min-w-0 truncate text-gray-900 dark:text-slate-100">{name(holiday.name_en, holiday.name_am)}</span>
                                        <LocalizedDateDisplay value={holiday.date} className="shrink-0 text-xs text-gray-500 dark:text-slate-400" />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* Notifications */}
                    <section className={`${panel} p-4`}>
                        <SectionHeading icon={Ic.Bell} title={t('employeePortal.notifications')} href={route('employee.notifications')} />
                        {notifications.latest.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-slate-400">{t('employeePortal.noNotifications')}</p>
                        ) : (
                            <ul className="space-y-2.5">
                                {notifications.latest.map((item) => (
                                    <li key={item.id} className="text-sm">
                                        <p className="flex items-center gap-1.5 font-medium text-gray-900 dark:text-slate-100">
                                            {!item.read && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-primary)]" aria-hidden="true" />}
                                            <span className="truncate">{item.title}</span>
                                        </p>
                                        <p className="line-clamp-2 text-xs text-gray-600 dark:text-slate-400">{item.message}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* Services */}
                    {entitlements.length > 0 && (
                        <section className={`${panel} p-4`}>
                            <SectionHeading icon={Ic.Layers} title={t('employeePortal.myServices')} href={route('employee.services')} />
                            <ul className="space-y-3">
                                {entitlements.slice(0, 4).map((e) => (
                                    <li key={e.id}>
                                        <div className="flex items-center justify-between gap-2 text-sm">
                                            <span className="truncate font-medium text-gray-900 dark:text-slate-100">{e.service ? name(e.service, e.service_am) : e.service_code}</span>
                                            {e.effective_to && <span className="shrink-0 text-[11px] text-gray-500">{t('employeePortal.until')} <LocalizedDateDisplay value={e.effective_to} /></span>}
                                        </div>
                                        {e.quota_limit != null && e.quota_limit > 0 && (
                                            <div className="mt-1">
                                                <div className="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                                                    <div className="h-full rounded-full bg-[var(--color-primary)]" style={{ width: `${Math.min(100, Math.round(((e.quota_used ?? 0) / e.quota_limit) * 100))}%` }} />
                                                </div>
                                                <p className="mt-0.5 text-[11px] text-gray-500 dark:text-slate-400">{fill('employeePortal.quotaUsed', { used: e.quota_used ?? 0, limit: e.quota_limit })}</p>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>
        </PortalPage>
    );
}
