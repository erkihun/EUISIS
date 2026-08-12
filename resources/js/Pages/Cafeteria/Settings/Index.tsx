import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import Modal from '@/Components/Modal';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

// ─── shared types ────────────────────────────────────────────────────────────
type Settings = Record<string, unknown>;
type InPageTab = 'general' | 'subsidy' | 'days' | 'scan' | 'day-rules' | 'holidays' | 'subsidy-rules' | 'reports' | 'provider-users';

type DayRule = {
    id: string; day_of_week: number; day_name: string;
    is_open: boolean; is_subsidy_day: boolean;
    open_time: string | null; close_time: string | null;
    notes: string | null;
};
type Holiday = {
    id: string; name_en: string; name_am: string | null;
    holiday_date: string; is_recurring: boolean;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean };
};
type SubsidyRule = {
    id: string; code: string; name_en: string; subsidy_amount: number;
    currency: string; effective_from: string; effective_to: string | null;
    applies_to: string; is_active: boolean; deleted_at: string | null;
    can: { update: boolean; archive: boolean };
};
type ProviderOption = { id: string; code: string; name_en: string; name_am: string | null; organization_id: string | null };
type UserOption = { id: string; name: string; email: string | null; username: string | null };
type OrganizationOption = { id: string; name_en: string; name_am: string | null; code: string };
type BranchOption = { id: string; code: string; name_en: string; name_am: string | null; cafeteria_provider_id: string; organization_id: string | null; provider_name_en: string | null };
type ProviderUser = {
    id: string;
    service_provider_user_id: string | null;
    cafeteria_provider_id: string | null;
    cafeteria_provider_branch_id: string | null;
    organization_id: string | null;
    provider_role: string | null;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    user: { name: string | null; email: string | null; username: string | null; status: string | null; provider_portal_enabled: boolean };
    provider: { code: string | null; name_en: string | null; name_am: string | null };
    organization: { name_en: string; name_am: string | null; code: string } | null;
    branch: { id: string; code: string; name_en: string } | null;
};
type CanProps = {
    update: boolean;
    updateDayRules: boolean;
    createHoliday: boolean;
    createSubsidyRule: boolean;
    manageProviderUsers: boolean;
};

// ─── constants ───────────────────────────────────────────────────────────────
const ALL_TABS: InPageTab[] = ['general', 'subsidy', 'days', 'scan', 'day-rules', 'holidays', 'subsidy-rules', 'reports', 'provider-users'];
const DAY_VALUES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;

const AM_DAY_NAMES: Record<number, string> = {
    1: 'ሰኞ', 2: 'ማክሰኞ', 3: 'ረቡዕ', 4: 'ሐሙስ', 5: 'ዓርብ', 6: 'ቅዳሜ', 7: 'እሑድ',
};

function localizedDayName(dayOfWeek: number, locale: string): string {
    if (locale === 'am') return AM_DAY_NAMES[dayOfWeek] ?? String(dayOfWeek);
    const base = new Date(2023, 0, 2); // Monday
    base.setDate(base.getDate() + dayOfWeek - 1);
    return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(base);
}

const DAY_COLORS: Record<number, string> = {
    1: 'bg-blue-50 dark:bg-blue-950/30',  2: 'bg-blue-50 dark:bg-blue-950/30',
    3: 'bg-blue-50 dark:bg-blue-950/30',  4: 'bg-blue-50 dark:bg-blue-950/30',
    5: 'bg-blue-50 dark:bg-blue-950/30',  6: 'bg-amber-50 dark:bg-amber-950/30',
    7: 'bg-amber-50 dark:bg-amber-950/30',
};

// ─── inline DayRuleRow ───────────────────────────────────────────────────────
function DayRuleRow({ rule, canUpdate }: { rule: DayRule; canUpdate: boolean }) {
    const { t, locale } = useLocale();
    const [isOpen, setIsOpen] = useState(rule.is_open);
    const [isSubsidy, setIsSubsidy] = useState(rule.is_subsidy_day);
    const [openTime, setOpenTime] = useState(rule.open_time ?? '');
    const [closeTime, setCloseTime] = useState(rule.close_time ?? '');
    const [saving, setSaving] = useState(false);

    function save() {
        setSaving(true);
        router.patch(route('cafeteria.day-rules.update', rule.id), {
            is_open: isOpen, is_subsidy_day: isSubsidy,
            open_time: openTime || null, close_time: closeTime || null,
        }, { onFinish: () => setSaving(false), preserveScroll: true });
    }

    const timeCls = 'rounded border border-gray-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-50';

    return (
        <div className={`flex flex-wrap items-center gap-4 rounded-xl border border-gray-200 p-4 dark:border-slate-700 ${DAY_COLORS[rule.day_of_week]}`}>
            <div className="w-28 font-semibold text-gray-800 dark:text-slate-100">{localizedDayName(rule.day_of_week, locale)}</div>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                <input type="checkbox" disabled={!canUpdate} checked={isOpen} onChange={e => { setIsOpen(e.target.checked); if (!e.target.checked) setIsSubsidy(false); }} className="h-4 w-4 rounded" />
                {t('cafeteria.isOpen')}
            </label>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                <input type="checkbox" disabled={!canUpdate || !isOpen} checked={isSubsidy} onChange={e => setIsSubsidy(e.target.checked)} className="h-4 w-4 rounded" />
                {t('cafeteria.isSubsidyDay')}
            </label>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.openTime')}</span>
                <LocalizedTimePicker value={openTime} onChange={setOpenTime} disabled={!canUpdate || !isOpen} className={timeCls} />
                <span className="text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.closeTime')}</span>
                <LocalizedTimePicker value={closeTime} onChange={setCloseTime} disabled={!canUpdate || !isOpen} className={timeCls} />
            </div>
            <div className="ml-auto flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${isOpen ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                    {isOpen ? t('cafeteria.openDay') : t('cafeteria.closedDay')}
                </span>
                {isOpen && (
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${isSubsidy ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                        {isSubsidy ? t('cafeteria.isSubsidyDay') : t('common.no')}
                    </span>
                )}
                {canUpdate && (
                    <button onClick={save} disabled={saving} className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                        {saving ? '…' : t('common.save')}
                    </button>
                )}
            </div>
        </div>
    );
}

// ─── main page ───────────────────────────────────────────────────────────────
export default function CafeteriaSettingsIndex({
    settings, can, activeTab = 'general',
    dayRules: rawDayRules,
    holidays: rawHolidays,
    holidaysYear,
    subsidyRules: rawSubsidyRules,
    providerUsers = [],
    providerOptions = [],
    userOptions = [],
    organizationOptions = [],
    branchOptions = [],
}: {
    settings: Settings;
    can: CanProps;
    activeTab?: string;
    dayRules?: DayRule[] | { data: DayRule[] };
    holidays?: Holiday[] | { data: Holiday[] };
    holidaysYear?: number;
    subsidyRules?: SubsidyRule[] | { data: SubsidyRule[] };
    providerUsers?: ProviderUser[];
    providerOptions?: ProviderOption[];
    userOptions?: UserOption[];
    organizationOptions?: OrganizationOption[];
    branchOptions?: BranchOption[];
}) {
    const dayRules: DayRule[]     = Array.isArray(rawDayRules)     ? rawDayRules     : (rawDayRules     as { data: DayRule[] })?.data     ?? [];
    const holidays: Holiday[]     = Array.isArray(rawHolidays)     ? rawHolidays     : (rawHolidays     as { data: Holiday[] })?.data     ?? [];
    const subsidyRules: SubsidyRule[] = Array.isArray(rawSubsidyRules) ? rawSubsidyRules : (rawSubsidyRules as { data: SubsidyRule[] })?.data ?? [];
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [tab, setTab] = useState<InPageTab>(
        (ALL_TABS.includes(activeTab as InPageTab) ? activeTab : 'general') as InPageTab
    );
    const [form, setForm] = useState<Settings>({ ...settings });
    const [providerUserForm, setProviderUserForm] = useState({
        service_provider_user_id: '',
        cafeteria_provider_id: '',
        cafeteria_provider_branch_id: '',
        organization_id: '',
        provider_role: 'operator',
        effective_from: '',
        effective_to: '',
    });
    const [saving, setSaving] = useState(false);

    const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60';
    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
    const sectionCls = 'rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 space-y-5';
    const tableCls = 'overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    const thCls = 'px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-slate-400';
    const tdCls = 'px-4 py-3 text-sm text-gray-700 dark:text-slate-300';

    function set(key: string, value: unknown) {
        setForm(f => ({ ...f, [key]: value }));
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setSaving(true);
        router.put(route('cafeteria.settings.update'), form as Record<string, string | number | boolean | null>, {
            onFinish: () => setSaving(false),
            preserveScroll: true,
        });
    }

    async function archiveHoliday(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.holidays.archive', id), { preserveScroll: true });
    }

    async function archiveSubsidyRule(id: string) {
        const { confirmed } = await confirm({ title: t('confirmations.confirmDeleteTitle'), description: '', confirmLabel: t('confirmations.delete'), cancelLabel: t('confirmations.cancel'), variant: 'danger' });
        if (confirmed) router.delete(route('cafeteria.subsidy-rules.archive', id), { preserveScroll: true });
    }

    function saveProviderUser(e: FormEvent) {
        e.preventDefault();
        router.post(route('cafeteria.settings.provider-users.store'), providerUserForm, {
            preserveScroll: true,
            onSuccess: () => setProviderUserForm({
                service_provider_user_id: '',
                cafeteria_provider_id: '',
                cafeteria_provider_branch_id: '',
                organization_id: '',
                provider_role: 'operator',
                effective_from: '',
                effective_to: '',
            }),
        });
    }

    function updateProviderUser(assignment: ProviderUser, isActive: boolean) {
        router.patch(route('cafeteria.settings.provider-users.update', assignment.id), {
            provider_role: assignment.provider_role ?? 'operator',
            is_active: isActive,
            effective_from: assignment.effective_from,
            effective_to: assignment.effective_to,
        }, { preserveScroll: true });
    }

    const [viewingAssignment, setViewingAssignment] = useState<ProviderUser | null>(null);
    const [editingAssignment, setEditingAssignment] = useState<ProviderUser | null>(null);
    const [editForm, setEditForm] = useState({
        service_provider_user_id: '',
        cafeteria_provider_id: '',
        cafeteria_provider_branch_id: '',
        organization_id: '',
        provider_role: 'operator',
        effective_from: '',
        effective_to: '',
        is_active: true,
    });

    function openEdit(assignment: ProviderUser) {
        const provider = providerOptions.find(p => p.id === assignment.cafeteria_provider_id);
        setEditForm({
            service_provider_user_id: assignment.service_provider_user_id ?? '',
            cafeteria_provider_id: assignment.cafeteria_provider_id ?? '',
            cafeteria_provider_branch_id: assignment.cafeteria_provider_branch_id ?? '',
            organization_id: provider?.organization_id ?? assignment.organization_id ?? '',
            provider_role: assignment.provider_role ?? 'operator',
            effective_from: assignment.effective_from ?? '',
            effective_to: assignment.effective_to ?? '',
            is_active: assignment.is_active,
        });
        setEditingAssignment(assignment);
    }

    function submitEdit(e: FormEvent) {
        e.preventDefault();
        if (!editingAssignment) return;
        router.patch(route('cafeteria.settings.provider-users.update', editingAssignment.id), editForm, {
            preserveScroll: true,
            onSuccess: () => setEditingAssignment(null),
        });
    }

    async function deleteProviderUser(assignment: ProviderUser) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: `${assignment.user.name} — ${assignment.provider.name_en}`,
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('cafeteria.settings.provider-users.destroy', assignment.id), { preserveScroll: true });
        }
    }

    const dayLabel: Record<string, string> = {
        monday: t('cafeteria.dayMonday'), tuesday: t('cafeteria.dayTuesday'),
        wednesday: t('cafeteria.dayWednesday'), thursday: t('cafeteria.dayThursday'),
        friday: t('cafeteria.dayFriday'), saturday: t('cafeteria.daySaturday'),
        sunday: t('cafeteria.daySunday'),
    };

    const tabDefs: { key: InPageTab; label: string }[] = [
        { key: 'general',       label: t('cafeteria.generalSettings') },
        { key: 'subsidy',       label: t('cafeteria.subsidySettings') },
        { key: 'days',          label: t('cafeteria.daySettings') },
        { key: 'scan',          label: t('cafeteria.scanSettings') },
        { key: 'day-rules',     label: t('cafeteria.dayRules') },
        { key: 'holidays',      label: t('nav.cafeteriaHolidays') },
        { key: 'subsidy-rules', label: t('nav.cafeteriaSubsidyRules') },
        { key: 'reports',       label: t('cafeteria.reportSettings') },
        { key: 'provider-users', label: t('cafeteria.providerUsers') },
    ];

    const tabBarCls = (active: boolean) =>
        `whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            active
                ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'
        }`;

    const isSettingsTab = (k: InPageTab) => ['general', 'subsidy', 'days', 'scan', 'reports'].includes(k);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.settings')} />}>
            <Head title={t('cafeteria.settings')} />
            <div className="space-y-6">
                {/* Tab bar */}
                <div className="overflow-x-auto">
                    <div className="flex min-w-max border-b border-gray-200 dark:border-slate-700">
                        {tabDefs.map(tb => (
                            <button
                                key={tb.key}
                                type="button"
                                onClick={() => setTab(tb.key)}
                                className={tabBarCls(tab === tb.key)}
                            >
                                {tb.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ── Settings form tabs (wrap in form) ── */}
                {isSettingsTab(tab) && (
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* General */}
                        {tab === 'general' && (
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.generalSettings')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.currency')}</label>
                                        <input className={inputCls} disabled={!can.update} value={String(form.currency ?? 'ETB')} onChange={e => set('currency', e.target.value)} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.requireActiveEmployee')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.require_active_employee ? '1' : '0'} onChange={e => set('require_active_employee', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.requireActiveIdCard')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.require_active_id_card ? '1' : '0'} onChange={e => set('require_active_id_card', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.requireProviderOperator')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.require_provider_operator ? '1' : '0'} onChange={e => set('require_provider_operator', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Subsidy */}
                        {tab === 'subsidy' && (
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.subsidySettings')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.defaultDailySubsidyAmount')}</label>
                                        <input type="number" min="0" step="0.01" className={inputCls} disabled={!can.update} value={String(form.default_daily_subsidy_amount ?? 0)} onChange={e => set('default_daily_subsidy_amount', parseFloat(e.target.value))} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.usageModeLabel')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.default_usage_mode ?? 'single_day')} onChange={e => set('default_usage_mode', e.target.value)}>
                                            <option value="single_day">{t('cafeteria.usageModeSingleDay')}</option>
                                            <option value="use_remaining_week">{t('cafeteria.usageModeRemainingWeek')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowUpfrontWeekdayUsage')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_upfront_weekday_usage ? '1' : '0'} onChange={e => set('allow_upfront_weekday_usage', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowPastDayClaim')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_past_day_claim ? '1' : '0'} onChange={e => set('allow_past_day_claim', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowFutureWeekBorrowing')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_future_week_borrowing ? '1' : '0'} onChange={e => set('allow_future_week_borrowing', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.excessAmountMode')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.excess_amount_mode ?? 'employee_payable')} onChange={e => set('excess_amount_mode', e.target.value)}>
                                            <option value="employee_payable">{t('cafeteria.excessModeEmployeePayable')}</option>
                                            <option value="reject">{t('cafeteria.excessModeReject')}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Working Days */}
                        {tab === 'days' && (
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.daySettings')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.weekStartDay')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.week_start_day ?? 'monday')} onChange={e => set('week_start_day', e.target.value)}>
                                            {DAY_VALUES.map(d => <option key={d} value={d}>{dayLabel[d]}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.weekEndDay')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.week_end_day ?? 'friday')} onChange={e => set('week_end_day', e.target.value)}>
                                            {DAY_VALUES.map(d => <option key={d} value={d}>{dayLabel[d]}</option>)}
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.closedWeekendDefault')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.closed_weekend_default ? '1' : '0'} onChange={e => set('closed_weekend_default', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowSaturdayService')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_saturday_service ? '1' : '0'} onChange={e => set('allow_saturday_service', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowSundayService')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_sunday_service ? '1' : '0'} onChange={e => set('allow_sunday_service', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.excludePublicHolidays')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.exclude_public_holidays ? '1' : '0'} onChange={e => set('exclude_public_holidays', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Scan Rules */}
                        {tab === 'scan' && (
                            <>
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.scanSettings')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.weekendScanMode')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.weekend_scan_mode ?? 'reject')} onChange={e => set('weekend_scan_mode', e.target.value)}>
                                            <option value="reject">{t('cafeteria.scanModeReject')}</option>
                                            <option value="allow">{t('cafeteria.scanModeAllow')}</option>
                                            <option value="employee_payable">{t('cafeteria.scanModeEmployeePayable')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.holidayScanMode')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.holiday_scan_mode ?? 'reject')} onChange={e => set('holiday_scan_mode', e.target.value)}>
                                            <option value="reject">{t('cafeteria.scanModeReject')}</option>
                                            <option value="allow">{t('cafeteria.scanModeAllow')}</option>
                                            <option value="employee_payable">{t('cafeteria.scanModeEmployeePayable')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.maxTransactionAmountPerScan')}</label>
                                        <input type="number" min="0" step="0.01" className={inputCls} disabled={!can.update} value={String(form.max_transaction_amount_per_scan ?? '')} placeholder={t('common.optional')} onChange={e => set('max_transaction_amount_per_scan', e.target.value ? parseFloat(e.target.value) : null)} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.maxExtraAmountPerWeek')}</label>
                                        <input type="number" min="0" step="0.01" className={inputCls} disabled={!can.update} value={String(form.max_extra_amount_per_week ?? '')} placeholder={t('common.optional')} onChange={e => set('max_extra_amount_per_week', e.target.value ? parseFloat(e.target.value) : null)} />
                                    </div>
                                </div>
                            </div>

                            {/* Leave Access Control */}
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.leaveAccessControl')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.blockCafeteriaDuringLeave')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.block_cafeteria_during_employee_leave ? '1' : '0'} onChange={e => set('block_cafeteria_during_employee_leave', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.leaveScanMode')}</label>
                                        <select className={inputCls} disabled={!can.update || !form.block_cafeteria_during_employee_leave} value={String(form.leave_scan_mode ?? 'reject')} onChange={e => set('leave_scan_mode', e.target.value)}>
                                            <option value="reject">{t('cafeteria.leaveScanModeReject')}</option>
                                            <option value="employee_payable">{t('cafeteria.leaveScanModeEmployeePayable')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.excludeLeaveDaysFromSubsidy')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.exclude_leave_days_from_subsidy ? '1' : '0'} onChange={e => set('exclude_leave_days_from_subsidy', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.allowLeaveDayRetroactiveClaim')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.allow_leave_day_retroactive_claim ? '1' : '0'} onChange={e => set('allow_leave_day_retroactive_claim', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.autoResumeAfterLeave')}</label>
                                        <select className={inputCls} disabled={!can.update} value={form.auto_resume_after_leave ? '1' : '0'} onChange={e => set('auto_resume_after_leave', e.target.value === '1')}>
                                            <option value="1">{t('common.yes')}</option>
                                            <option value="0">{t('common.no')}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            </>
                        )}

                        {/* Report Settings */}
                        {tab === 'reports' && (
                            <div className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.reportSettings')}</h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.reportType')}</label>
                                        <select className={inputCls} disabled={!can.update} value={String(form.report_default_format ?? 'csv')} onChange={e => set('report_default_format', e.target.value)}>
                                            <option value="csv">CSV</option>
                                            <option value="xlsx">Excel (XLSX)</option>
                                            <option value="pdf">PDF</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.reportTimezone')}</label>
                                        <input className={inputCls} disabled={!can.update} value={String(form.report_timezone ?? 'Africa/Addis_Ababa')} onChange={e => set('report_timezone', e.target.value)} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.payrollCutoffDay')}</label>
                                        <input type="number" min="1" max="31" className={inputCls} disabled={!can.update} value={String(form.payroll_cutoff_day ?? '')} placeholder={t('common.optional')} onChange={e => set('payroll_cutoff_day', e.target.value ? parseInt(e.target.value) : null)} />
                                    </div>
                                </div>
                            </div>
                        )}

                        {can.update && (
                            <div className="flex justify-end">
                                <button type="submit" disabled={saving} className="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
                                    {saving ? t('common.saving') : t('common.save')}
                                </button>
                            </div>
                        )}
                    </form>
                )}

                {/* ── Working Day Rules tab ── */}
                {tab === 'day-rules' && (
                    <div className="space-y-3">
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('cafeteria.specialDayOverrideNote')}</p>
                        {dayRules.length === 0
                            ? <EmptyState title={t('common.noResults')} />
                            : dayRules.map(r => <DayRuleRow key={r.id} rule={r} canUpdate={can.updateDayRules} />)
                        }
                    </div>
                )}

                {/* ── Public Holidays tab ── */}
                {tab === 'holidays' && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-gray-500 dark:text-slate-400">{holidaysYear}</span>
                            {can.createHoliday && (
                                <Link href={route('cafeteria.holidays.create')} className="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    + {t('cafeteria.addHoliday')}
                                </Link>
                            )}
                        </div>
                        <div className={tableCls}>
                            {holidays.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                                <th className={thCls}>{t('cafeteria.holidayDate')}</th>
                                                <th className={thCls}>{t('cafeteria.nameEn')}</th>
                                                <th className={thCls}>{t('cafeteria.nameAm')}</th>
                                                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.isRecurring')}</th>
                                                <th className="px-4 py-3" />
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                            {holidays.map(h => (
                                                <tr key={h.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                                    <td className={`${tdCls} font-medium text-gray-900 dark:text-slate-100`}><LocalizedDateDisplay value={h.holiday_date} /></td>
                                                    <td className={tdCls}>{h.name_en}</td>
                                                    <td className={tdCls}>{h.name_am ?? '—'}</td>
                                                    <td className="px-4 py-3 text-center text-sm">{h.is_recurring ? '✓' : '—'}</td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex justify-end gap-3">
                                                            {h.can.update && <Link href={route('cafeteria.holidays.edit', h.id)} className="text-xs text-blue-600 hover:underline">{t('common.edit')}</Link>}
                                                            {h.can.archive && !h.deleted_at && <button onClick={() => archiveHoliday(h.id)} className="text-xs text-red-600 hover:underline">{t('common.archive')}</button>}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* ── Subsidy Rules tab ── */}
                {tab === 'subsidy-rules' && (
                    <div className="space-y-4">
                        <div className="flex justify-end">
                            {can.createSubsidyRule && (
                                <Link href={route('cafeteria.subsidy-rules.create')} className="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                    + {t('cafeteria.addSubsidyRule')}
                                </Link>
                            )}
                        </div>
                        <div className={tableCls}>
                            {subsidyRules.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                                <th className={thCls}>{t('cafeteria.subsidyRule')}</th>
                                                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.subsidyAmount')}</th>
                                                <th className={thCls}>{t('cafeteria.effectiveFrom')}</th>
                                                <th className={thCls}>{t('cafeteria.effectiveTo')}</th>
                                                <th className={thCls}>{t('cafeteria.appliesTo')}</th>
                                                <th className="px-4 py-3" />
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                            {subsidyRules.map(rule => (
                                                <tr key={rule.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium text-gray-900 dark:text-slate-100">{rule.name_en}</div>
                                                        <div className="font-mono text-xs text-gray-500">{rule.code}</div>
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium text-emerald-600">{rule.subsidy_amount.toFixed(2)} {rule.currency}</td>
                                                    <td className={tdCls}><LocalizedDateDisplay value={rule.effective_from} /></td>
                                                    <td className={tdCls}><LocalizedDateDisplay value={rule.effective_to} /></td>
                                                    <td className={tdCls}>{rule.applies_to}</td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex justify-end gap-3">
                                                            {rule.can.update && <Link href={route('cafeteria.subsidy-rules.edit', rule.id)} className="text-xs text-blue-600 hover:underline">{t('common.edit')}</Link>}
                                                            {rule.can.archive && !rule.deleted_at && <button onClick={() => archiveSubsidyRule(rule.id)} className="text-xs text-red-600 hover:underline">{t('common.archive')}</button>}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'provider-users' && (
                    <div className="space-y-4">
                        {can.manageProviderUsers && (
                            <form onSubmit={saveProviderUser} className={sectionCls}>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.addProviderUser')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.providerUser')}</label>
                                        <select
                                            required
                                            className={inputCls}
                                            value={providerUserForm.service_provider_user_id}
                                            onChange={e => setProviderUserForm(f => ({ ...f, service_provider_user_id: e.target.value }))}
                                        >
                                            <option value="">{t('common.select')}</option>
                                            {userOptions.map(user => (
                                                <option key={user.id} value={user.id}>{user.name} · {user.email ?? user.username ?? '-'}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.provider')}</label>
                                        <select
                                            required
                                            className={inputCls}
                                            value={providerUserForm.cafeteria_provider_id}
                                            onChange={e => {
                                                const provider = providerOptions.find(p => p.id === e.target.value);
                                                setProviderUserForm(f => ({
                                                    ...f,
                                                    cafeteria_provider_id: e.target.value,
                                                    cafeteria_provider_branch_id: '',
                                                    organization_id: provider?.organization_id ?? '',
                                                }));
                                            }}
                                        >
                                            <option value="">{t('common.select')}</option>
                                            {providerOptions.map(provider => (
                                                <option key={provider.id} value={provider.id}>{provider.name_en} · {provider.code}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.branches')}</label>
                                        <select
                                            className={inputCls}
                                            value={providerUserForm.cafeteria_provider_branch_id}
                                            onChange={e => setProviderUserForm(f => ({ ...f, cafeteria_provider_branch_id: e.target.value }))}
                                        >
                                            <option value="">{t('common.select')}</option>
                                            {branchOptions
                                                .filter(b => !providerUserForm.cafeteria_provider_id || b.cafeteria_provider_id === providerUserForm.cafeteria_provider_id)
                                                .map(b => (
                                                    <option key={b.id} value={b.id}>{b.name_en} · {b.code}</option>
                                                ))
                                            }
                                        </select>
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('cafeteria.providerRole')}</label>
                                        <input className={inputCls} value={providerUserForm.provider_role} onChange={e => setProviderUserForm(f => ({ ...f, provider_role: e.target.value }))} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('common.effectiveFrom')}</label>
                                        <input type="date" className={inputCls} value={providerUserForm.effective_from} onChange={e => setProviderUserForm(f => ({ ...f, effective_from: e.target.value }))} />
                                    </div>
                                    <div>
                                        <label className={labelCls}>{t('common.effectiveTo')}</label>
                                        <input type="date" className={inputCls} value={providerUserForm.effective_to} onChange={e => setProviderUserForm(f => ({ ...f, effective_to: e.target.value }))} />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                        {t('common.save')}
                                    </button>
                                </div>
                            </form>
                        )}

                        {/* ── View Modal ─────────────────────────────────────── */}
                        <Modal show={viewingAssignment !== null} maxWidth="lg" onClose={() => setViewingAssignment(null)}>
                            {viewingAssignment && (
                                <div className="p-6 space-y-5">
                                    <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.providerUser')}</h3>
                                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        {[
                                            { label: t('cafeteria.providerUser'), value: viewingAssignment.user.name },
                                            { label: t('common.email'), value: viewingAssignment.user.email ?? viewingAssignment.user.username ?? '—' },
                                            { label: t('common.status'), value: viewingAssignment.user.status ?? '—' },
                                            { label: t('cafeteria.provider'), value: `${viewingAssignment.provider.name_en} · ${viewingAssignment.provider.code}` },
                                            { label: t('cafeteria.branches'), value: viewingAssignment.branch ? `${viewingAssignment.branch.name_en} · ${viewingAssignment.branch.code}` : '—' },
                                            { label: t('common.organization'), value: viewingAssignment.organization ? `${viewingAssignment.organization.name_en} · ${viewingAssignment.organization.code}` : t('common.allOrganizations') },
                                            { label: t('cafeteria.providerRole'), value: viewingAssignment.provider_role ?? 'operator' },
                                            { label: t('common.active'), value: viewingAssignment.is_active ? t('common.yes') : t('common.no') },
                                        ].map(({ label, value }) => (
                                            <div key={label}>
                                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{label}</dt>
                                                <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{value}</dd>
                                            </div>
                                        ))}
                                        <div>
                                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.effectiveFrom')}</dt>
                                            <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                                                {viewingAssignment.effective_from
                                                    ? <LocalizedDateDisplay value={viewingAssignment.effective_from} />
                                                    : '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.effectiveTo')}</dt>
                                            <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">
                                                {viewingAssignment.effective_to
                                                    ? <LocalizedDateDisplay value={viewingAssignment.effective_to} />
                                                    : '—'}
                                            </dd>
                                        </div>
                                    </dl>
                                    <div className="flex justify-end">
                                        <button type="button" onClick={() => setViewingAssignment(null)} className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                                            {t('common.close')}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </Modal>

                        {/* ── Edit Modal ─────────────────────────────────────── */}
                        <Modal show={editingAssignment !== null} maxWidth="2xl" onClose={() => setEditingAssignment(null)}>
                            {editingAssignment && (
                                <form onSubmit={submitEdit} className="p-6 space-y-5">
                                    <div className="border-b border-gray-100 pb-3 dark:border-slate-700">
                                        <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{t('common.edit')} — {t('cafeteria.providerUser')}</h3>
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        {/* Provider User */}
                                        <div>
                                            <label className={labelCls}>{t('cafeteria.providerUser')}</label>
                                            <select
                                                required
                                                className={inputCls}
                                                value={editForm.service_provider_user_id}
                                                onChange={e => setEditForm(f => ({ ...f, service_provider_user_id: e.target.value }))}
                                            >
                                                <option value="">{t('common.select')}</option>
                                                {userOptions.map(u => (
                                                    <option key={u.id} value={u.id}>{u.name} · {u.email ?? u.username ?? '—'}</option>
                                                ))}
                                            </select>
                                        </div>
                                        {/* Cafeteria Provider */}
                                        <div>
                                            <label className={labelCls}>{t('cafeteria.provider')}</label>
                                            <select
                                                required
                                                className={inputCls}
                                                value={editForm.cafeteria_provider_id}
                                                onChange={e => {
                                                    const provider = providerOptions.find(p => p.id === e.target.value);
                                                    setEditForm(f => ({
                                                        ...f,
                                                        cafeteria_provider_id: e.target.value,
                                                        cafeteria_provider_branch_id: '',
                                                        organization_id: provider?.organization_id ?? '',
                                                    }));
                                                }}
                                            >
                                                <option value="">{t('common.select')}</option>
                                                {providerOptions.map(p => (
                                                    <option key={p.id} value={p.id}>{p.name_en} · {p.code}</option>
                                                ))}
                                            </select>
                                        </div>
                                        {/* Branch */}
                                        <div>
                                            <label className={labelCls}>{t('cafeteria.branches')}</label>
                                            <select
                                                className={inputCls}
                                                value={editForm.cafeteria_provider_branch_id}
                                                onChange={e => setEditForm(f => ({ ...f, cafeteria_provider_branch_id: e.target.value }))}
                                            >
                                                <option value="">{t('common.select')}</option>
                                                {branchOptions
                                                    .filter(b => !editForm.cafeteria_provider_id || b.cafeteria_provider_id === editForm.cafeteria_provider_id)
                                                    .map(b => (
                                                        <option key={b.id} value={b.id}>{b.name_en} · {b.code}</option>
                                                    ))
                                                }
                                            </select>
                                        </div>
                                        {/* Provider Role */}
                                        <div>
                                            <label className={labelCls}>{t('cafeteria.providerRole')}</label>
                                            <input
                                                className={inputCls}
                                                value={editForm.provider_role}
                                                onChange={e => setEditForm(f => ({ ...f, provider_role: e.target.value }))}
                                            />
                                        </div>
                                        {/* Active */}
                                        <div className="flex items-end pb-1">
                                            <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                                                <input
                                                    type="checkbox"
                                                    className="h-4 w-4 rounded"
                                                    checked={editForm.is_active}
                                                    onChange={e => setEditForm(f => ({ ...f, is_active: e.target.checked }))}
                                                />
                                                {t('common.active')}
                                            </label>
                                        </div>
                                        {/* Effective From */}
                                        <div>
                                            <label className={labelCls}>{t('common.effectiveFrom')}</label>
                                            <input
                                                type="date"
                                                className={inputCls}
                                                value={editForm.effective_from}
                                                onChange={e => setEditForm(f => ({ ...f, effective_from: e.target.value }))}
                                            />
                                        </div>
                                        {/* Effective To */}
                                        <div>
                                            <label className={labelCls}>{t('common.effectiveTo')}</label>
                                            <input
                                                type="date"
                                                className={inputCls}
                                                value={editForm.effective_to}
                                                onChange={e => setEditForm(f => ({ ...f, effective_to: e.target.value }))}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-slate-700">
                                        <button type="button" onClick={() => setEditingAssignment(null)} className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                                            {t('common.cancel')}
                                        </button>
                                        <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                            {t('common.save')}
                                        </button>
                                    </div>
                                </form>
                            )}
                        </Modal>

                        <div className={tableCls}>
                            {providerUsers.length === 0 ? <EmptyState title={t('common.noResults')} /> : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                                <th className={thCls}>{t('cafeteria.providerUser')}</th>
                                                <th className={thCls}>{t('cafeteria.provider')}</th>
                                                <th className={thCls}>{t('cafeteria.branches')}</th>
                                                <th className={thCls}>{t('common.organization')}</th>
                                                <th className={thCls}>{t('cafeteria.providerRole')}</th>
                                                <th className={thCls}>{t('common.effectiveFrom')}</th>
                                                <th className={thCls}>{t('common.effectiveTo')}</th>
                                                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-slate-400">{t('common.active')}</th>
                                                <th className="px-4 py-3" />
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                            {providerUsers.map(assignment => (
                                                <tr key={assignment.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium text-gray-900 dark:text-slate-100">{assignment.user.name}</div>
                                                        <div className="text-xs text-gray-500">{assignment.user.email}</div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium text-gray-900 dark:text-slate-100">{assignment.provider.name_en}</div>
                                                        <div className="font-mono text-xs text-gray-500">{assignment.provider.code}</div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {assignment.branch ? (
                                                            <>
                                                                <div className="font-medium text-gray-900 dark:text-slate-100">{assignment.branch.name_en}</div>
                                                                <div className="font-mono text-xs text-gray-500">{assignment.branch.code}</div>
                                                            </>
                                                        ) : (
                                                            <span className="text-xs text-gray-400 dark:text-slate-500">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {assignment.organization ? (
                                                            <>
                                                                <div className="font-medium text-gray-900 dark:text-slate-100">{assignment.organization.name_en}</div>
                                                                <div className="font-mono text-xs text-gray-500">{assignment.organization.code}</div>
                                                            </>
                                                        ) : (
                                                            <span className="text-xs text-gray-400 dark:text-slate-500">{t('common.allOrganizations')}</span>
                                                        )}
                                                    </td>
                                                    <td className={tdCls}>{assignment.provider_role ?? 'operator'}</td>
                                                    <td className={tdCls}>{assignment.effective_from ? <LocalizedDateDisplay value={assignment.effective_from} /> : '—'}</td>
                                                    <td className={tdCls}>{assignment.effective_to ? <LocalizedDateDisplay value={assignment.effective_to} /> : '—'}</td>
                                                    <td className="px-4 py-3 text-center">
                                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${assignment.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                                                            {assignment.is_active ? t('common.yes') : t('common.no')}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => setViewingAssignment(assignment)}
                                                                className="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100"
                                                            >
                                                                {t('common.view')}
                                                            </button>
                                                            {can.manageProviderUsers && (
                                                                <>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => openEdit(assignment)}
                                                                        className="rounded px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50 hover:text-blue-800 dark:text-blue-400 dark:hover:bg-blue-900/30 dark:hover:text-blue-300"
                                                                    >
                                                                        {t('common.edit')}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => updateProviderUser(assignment, !assignment.is_active)}
                                                                        className={`rounded px-2 py-1 text-xs font-medium ${assignment.is_active ? 'text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/30' : 'text-green-600 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/30'}`}
                                                                    >
                                                                        {assignment.is_active ? t('common.disabled') : t('common.enabled')}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => deleteProviderUser(assignment)}
                                                                        className="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 hover:text-red-800 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-300"
                                                                    >
                                                                        {t('common.delete')}
                                                                    </button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
