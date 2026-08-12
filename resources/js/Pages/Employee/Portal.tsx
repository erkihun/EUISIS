import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

/* ── icon set ────────────────────────────────────────────────────────────── */
const Ic = {
    User:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M18 20a6 6 0 0 0-12 0"/><circle cx="12" cy="10" r="4"/></svg>,
    Building:   (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>,
    Briefcase:  (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>,
    Card:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>,
    Utensils:   (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zM21 15v7"/></svg>,
    ArrowRight: (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>,
    Megaphone:  (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>,
    Layers:     (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>,
    Clock:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>,
    Check:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>,
    Alert:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>,
    Mail:       (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>,
    Phone:      (p: IconProps) => <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1.25h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.29 6.29l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>,
};

/* ── types ───────────────────────────────────────────────────────────────── */
type WeekDay = {
    date: string; is_today: boolean; is_working_day: boolean;
    is_consumed: boolean; is_available: boolean;
    is_holiday: boolean; is_weekend: boolean; is_employee_excluded?: boolean;
};
type PortalProps = PageProps & {
    employee: { id: string; full_name: string | null; employee_number: string | null; status: string | null; photo_url: string | null; email: string | null; phone: string | null; } | null;
    assignment: { organization: string | null; organization_unit: string | null; position: string | null; grade_level: string | null; effective_from: string | null; } | null;
    id_card: { card_number: string | null; status: string; expires_at: string | null; is_active: boolean; } | null;
    cafeteria: {
        balance: number; pending_deduction: number; daily_amount: number | null;
        available_days: number; remaining_subsidy: number | null;
        week_start: string; week_end: string; week_days: WeekDay[];
        recent_transactions: { date: string | null; subsidy: number; meal_amount: number; employee_pays: number; provider: string | null; status: string | null; }[];
    } | null;
    entitlements: { id: string; service: string | null; service_code: string | null; quota_limit: number | null; quota_used: number | null; effective_to: string | null; }[];
    transfer_apps: { id: string; status: string; status_label: string; submitted_at: string | null; organization: string | null; position: string | null; announcement_id: string; }[];
    open_announcements: { id: string; organization: string | null; position: string | null; grade_level: string | null; vacancies: number; closing_date: string | null; }[];
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
const CARD_COLOR: Record<string, string> = {
    active:        'text-emerald-600 dark:text-emerald-400',
    expired:       'text-red-500 dark:text-red-400',
    revoked:       'text-red-600 dark:text-red-400',
    suspended:     'text-amber-500 dark:text-amber-400',
    issued:        'text-blue-500 dark:text-blue-400',
    pending_print: 'text-gray-400 dark:text-slate-500',
};

function fmt(n: number | null | undefined) {
    if (n == null) return '—';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function StatusPill({ status, label }: { status: string; label?: string }) {
    return (
        <span className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${APP_COLOR[status] ?? 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
            {label ?? status.replace(/_/g, ' ')}
        </span>
    );
}

function SectionHeading({ icon: I, title, href }: { icon: (p: IconProps) => JSX.Element; title: string; href?: string }) {
    return (
        <div className="mb-4 flex items-center justify-between">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-slate-100">
                <I className="h-4 w-4 text-[var(--color-primary,#2563eb)]" /> {title}
            </h2>
            {href && (
                <Link href={href} className="flex items-center gap-0.5 text-xs text-[var(--color-primary,#2563eb)] hover:underline">
                    View all <Ic.ArrowRight className="h-3 w-3" />
                </Link>
            )}
        </div>
    );
}

/* ── week calendar strip ─────────────────────────────────────────────────── */
function WeekStrip({ days }: { days: WeekDay[] }) {
    const names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    return (
        <div className="flex gap-2">
            {names.map((name, i) => {
                const d = days[i];
                let bg = 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-600';
                let ring = '';
                if (d) {
                    if (d.is_holiday || d.is_employee_excluded) bg = 'bg-amber-100 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400';
                    else if (d.is_consumed)  bg = 'bg-emerald-500 text-white';
                    else if (d.is_available) bg = 'bg-[var(--color-primary,#2563eb)]/15 text-[var(--color-primary,#2563eb)]';
                    if (d.is_today) ring = 'ring-2 ring-[var(--color-primary,#2563eb)] ring-offset-1 dark:ring-offset-slate-900';
                }
                return (
                    <div key={name} className="flex flex-1 flex-col items-center gap-1">
                        <span className={`text-[10px] font-semibold ${d?.is_today ? 'text-[var(--color-primary,#2563eb)]' : 'text-gray-400 dark:text-slate-500'}`}>{name}</span>
                        <div className={`flex h-9 w-full items-center justify-center rounded-lg text-xs font-bold ${bg} ${ring}`}>
                            {!d ? '—' : d.is_consumed ? '✓' : d.is_available ? '●' : d.is_holiday ? '✗' : '—'}
                        </div>
                        <span className="text-[9px] text-gray-400 dark:text-slate-600">{d ? new Date(d.date).getDate() : ''}</span>
                    </div>
                );
            })}
        </div>
    );
}

/* ── main page ───────────────────────────────────────────────────────────── */
export default function EmployeePortal({ employee, assignment, id_card, cafeteria, entitlements, transfer_apps, open_announcements }: PortalProps) {
    const { t } = useLocale();
    const { props } = usePage<PortalProps>();
    const user = props.auth?.user;

    const greeting = employee?.full_name ?? user?.name ?? 'Employee';
    const activeApps = transfer_apps.filter(a => !['rejected', 'withdrawn', 'cancelled', 'transferred'].includes(a.status));

    if (!employee) {
        return (
            <AuthenticatedLayout header={<PageHeader title={t('nav.myPortal') || 'My Portal'} />}>
                <Head title="My Portal" />
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-900/50 dark:bg-amber-950/20">
                    <Ic.Alert className="mx-auto mb-3 h-10 w-10 text-amber-500" />
                    <p className="font-medium text-amber-800 dark:text-amber-300">No employee profile linked to your account.</p>
                    <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">Contact HR to link your account to your employee record.</p>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('nav.myPortal') || 'My Portal'}
                    description={greeting + (assignment?.position ? ` · ${assignment.position}` : '')}
                />
            }
        >
            <Head title="My Portal" />

            {/* ── Top stat row ─────────────────────────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">

                <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Café Balance</p>
                    <p className={`mt-1.5 text-2xl font-bold ${cafeteria && cafeteria.balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-slate-100'}`}>
                        {cafeteria != null ? `${fmt(cafeteria.balance)}` : '—'}
                    </p>
                    <p className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">ETB</p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Days Left</p>
                    <p className="mt-1.5 text-2xl font-bold text-gray-900 dark:text-slate-100">
                        {cafeteria != null ? cafeteria.available_days : '—'}
                    </p>
                    <p className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">
                        {cafeteria?.daily_amount != null ? `${fmt(cafeteria.daily_amount)} ETB/day` : 'this week'}
                    </p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">ID Card</p>
                    {id_card ? (
                        <>
                            <p className={`mt-1.5 text-lg font-bold capitalize ${CARD_COLOR[id_card.status] ?? 'text-gray-900 dark:text-slate-100'}`}>
                                {id_card.status.replace(/_/g, ' ')}
                            </p>
                            <p className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">{id_card.expires_at ? <><span>Exp. </span><LocalizedDateDisplay value={id_card.expires_at} /></> : id_card.card_number ?? '—'}</p>
                        </>
                    ) : (
                        <p className="mt-1.5 text-sm text-gray-400 dark:text-slate-500">No card</p>
                    )}
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Active Apps</p>
                    <p className="mt-1.5 text-2xl font-bold text-gray-900 dark:text-slate-100">{activeApps.length}</p>
                    <p className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">transfer applications</p>
                </div>
            </div>

            {/* ── Main grid ────────────────────────────────────────────────── */}
            <div className="grid gap-6 lg:grid-cols-3">

                {/* ── Left 2/3 ─────────────────────────────────────────────── */}
                <div className="space-y-6 lg:col-span-2">

                    {/* Cafeteria */}
                    {cafeteria && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <SectionHeading icon={Ic.Utensils} title="Cafeteria Subsidy" />

                            {/* Week strip */}
                            <div className="mb-5">
                                <div className="mb-2 flex items-center justify-between text-xs text-gray-400 dark:text-slate-500">
                                    <span>Week {cafeteria.week_start} – {cafeteria.week_end}</span>
                                    <div className="flex items-center gap-3 text-[10px]">
                                        <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-emerald-500" />Used</span>
                                        <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-[var(--color-primary,#2563eb)]/20" />Available</span>
                                    </div>
                                </div>
                                <WeekStrip days={cafeteria.week_days} />
                            </div>

                            {/* Stats row */}
                            <div className="mb-5 grid grid-cols-3 divide-x divide-gray-100 rounded-xl border border-gray-100 dark:divide-slate-800 dark:border-slate-800">
                                <div className="px-4 py-3 text-center">
                                    <p className="text-[10px] text-gray-400 dark:text-slate-500">Daily Rate</p>
                                    <p className="mt-1 text-base font-bold text-gray-900 dark:text-slate-100">{fmt(cafeteria.daily_amount)}</p>
                                    <p className="text-[9px] text-gray-400">ETB</p>
                                </div>
                                <div className="px-4 py-3 text-center">
                                    <p className="text-[10px] text-gray-400 dark:text-slate-500">Week Remaining</p>
                                    <p className="mt-1 text-base font-bold text-[var(--color-primary,#2563eb)]">{fmt(cafeteria.remaining_subsidy)}</p>
                                    <p className="text-[9px] text-gray-400">ETB</p>
                                </div>
                                <div className="px-4 py-3 text-center">
                                    <p className="text-[10px] text-gray-400 dark:text-slate-500">Balance</p>
                                    <p className={`mt-1 text-base font-bold ${cafeteria.balance < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                                        {fmt(cafeteria.balance)}
                                    </p>
                                    <p className="text-[9px] text-gray-400">ETB</p>
                                </div>
                            </div>

                            {/* Recent transactions */}
                            {cafeteria.recent_transactions.length > 0 && (
                                <>
                                    <p className="mb-2 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-slate-500">Recent Transactions</p>
                                    <div className="divide-y divide-gray-100 dark:divide-slate-800">
                                        {cafeteria.recent_transactions.map((tx, i) => (
                                            <div key={i} className="flex items-center justify-between py-2.5 text-sm">
                                                <div>
                                                    <p className="font-medium text-gray-800 dark:text-slate-200">{tx.provider ?? 'Cafeteria'}</p>
                                                    <p className="text-xs text-gray-400 dark:text-slate-500">{tx.date}</p>
                                                </div>
                                                <div className="text-right text-xs">
                                                    <p className="font-semibold text-emerald-600 dark:text-emerald-400">-{fmt(tx.subsidy)} ETB subsidy</p>
                                                    {tx.employee_pays > 0 && <p className="text-gray-500 dark:text-slate-400">You pay {fmt(tx.employee_pays)} ETB</p>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                    )}

                    {/* Transfer applications */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <SectionHeading icon={Ic.Clock} title="My Transfer Applications" href={route('employee.transfer-applications')} />

                        {transfer_apps.length === 0 ? (
                            <div className="py-6 text-center">
                                <p className="text-sm text-gray-400 dark:text-slate-500">No applications yet.</p>
                                <Link href={route('public.transfer-announcements')} className="mt-2 inline-block text-sm text-[var(--color-primary,#2563eb)] hover:underline">
                                    Browse open announcements →
                                </Link>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {transfer_apps.map(app => (
                                    <div key={app.id} className="flex items-center gap-3 rounded-xl border border-gray-100 p-3 dark:border-slate-800">
                                        <div className="min-w-0 flex-1">
                                            <Link href={route('public.transfer-announcements.show', { announcement: app.announcement_id })}
                                                className="text-sm font-medium text-gray-900 hover:text-[var(--color-primary,#2563eb)] dark:text-slate-100">
                                                {app.position ?? '—'}
                                            </Link>
                                            <p className="truncate text-xs text-gray-400 dark:text-slate-500">{app.organization}{app.submitted_at && <> · <LocalizedDateDisplay value={app.submitted_at} /></>}</p>
                                        </div>
                                        <StatusPill status={app.status} label={app.status_label} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Open announcements */}
                    {open_announcements.length > 0 && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <SectionHeading icon={Ic.Megaphone} title="Open Announcements" href={route('public.transfer-announcements')} />
                            <div className="space-y-2">
                                {open_announcements.map(a => (
                                    <Link key={a.id} href={route('public.transfer-announcements.show', { announcement: a.id })}
                                        className="group flex items-center justify-between rounded-xl border border-gray-100 p-3 transition hover:border-[var(--color-primary,#2563eb)]/30 hover:bg-[var(--color-primary,#2563eb)]/5 dark:border-slate-800">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium text-gray-900 dark:text-slate-100">{a.position ?? '—'}</p>
                                            <p className="truncate text-xs text-gray-400 dark:text-slate-500">{a.organization}{a.grade_level && ` · Grade ${a.grade_level}`}</p>
                                        </div>
                                        <div className="ml-3 shrink-0 text-right">
                                            <span className="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                                {a.vacancies} open
                                            </span>
                                            {a.closing_date && <p className="mt-0.5 text-[10px] text-gray-400">Closes <LocalizedDateDisplay value={a.closing_date} /></p>}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* ── Right 1/3 ────────────────────────────────────────────── */}
                <div className="space-y-5">

                    {/* Profile card */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-3">
                            {employee.photo_url ? (
                                <img src={employee.photo_url} alt="" className="h-14 w-11 rounded-xl object-cover" />
                            ) : (
                                <div className="flex h-14 w-11 items-center justify-center rounded-xl bg-[var(--color-primary,#2563eb)]/10">
                                    <Ic.User className="h-7 w-7 text-[var(--color-primary,#2563eb)]" />
                                </div>
                            )}
                            <div className="min-w-0">
                                <p className="truncate font-semibold text-gray-900 dark:text-slate-100">{employee.full_name}</p>
                                <p className="text-xs text-gray-400 dark:text-slate-500">#{employee.employee_number}</p>
                                <span className="mt-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold capitalize text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    {employee.status ?? 'active'}
                                </span>
                            </div>
                        </div>
                        <dl className="space-y-2.5 border-t border-gray-100 pt-3 text-sm dark:border-slate-800">
                            {assignment?.organization && (
                                <div className="flex items-start gap-2">
                                    <Ic.Building className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="text-gray-700 dark:text-slate-300">{assignment.organization}</span>
                                </div>
                            )}
                            {assignment?.position && (
                                <div className="flex items-start gap-2">
                                    <Ic.Briefcase className="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span className="text-gray-700 dark:text-slate-300">{assignment.position}{assignment.grade_level && <span className="ml-1 text-gray-400">· Gr. {assignment.grade_level}</span>}</span>
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
                    </div>

                    {/* ID Card */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <SectionHeading icon={Ic.Card} title="ID Card" />
                        {id_card ? (
                            <div className="rounded-xl bg-gradient-to-br from-[var(--color-primary,#2563eb)] to-indigo-700 p-4 text-white shadow">
                                <div className="flex items-center justify-between">
                                    <Ic.Card className="h-5 w-5 opacity-60" />
                                    {id_card.is_active && (
                                        <Ic.Check className="h-4 w-4 text-emerald-300" />
                                    )}
                                </div>
                                <p className="mt-3 font-mono text-sm opacity-80">{id_card.card_number ?? '——————'}</p>
                                <div className="mt-2 flex items-end justify-between">
                                    <p className="text-xs capitalize opacity-70">{id_card.status.replace(/_/g, ' ')}</p>
                                    {id_card.expires_at && <p className="text-xs opacity-70">Exp. <LocalizedDateDisplay value={id_card.expires_at} /></p>}
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-400 dark:text-slate-500">No active ID card.</p>
                        )}
                    </div>

                    {/* Entitlements */}
                    {entitlements.length > 0 && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <SectionHeading icon={Ic.Layers} title="My Services" />
                            <div className="space-y-3">
                                {entitlements.map(e => (
                                    <div key={e.id} className="rounded-xl border border-gray-100 p-3 dark:border-slate-800">
                                        <div className="flex items-center justify-between">
                                            <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{e.service ?? e.service_code}</p>
                                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">Active</span>
                                        </div>
                                        {e.quota_limit != null && (
                                            <div className="mt-2">
                                                <div className="mb-1 flex justify-between text-[10px] text-gray-400 dark:text-slate-500">
                                                    <span>{e.quota_used ?? 0} / {e.quota_limit} used</span>
                                                    <span>{Math.round(((e.quota_used ?? 0) / e.quota_limit) * 100)}%</span>
                                                </div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-800">
                                                    <div className="h-full rounded-full bg-[var(--color-primary,#2563eb)]"
                                                        style={{ width: `${Math.min(100, Math.round(((e.quota_used ?? 0) / e.quota_limit) * 100))}%` }} />
                                                </div>
                                            </div>
                                        )}
                                        {e.effective_to && <p className="mt-1 text-[10px] text-gray-400">Until <LocalizedDateDisplay value={e.effective_to} /></p>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
