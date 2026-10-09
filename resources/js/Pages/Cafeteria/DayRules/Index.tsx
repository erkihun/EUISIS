import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

const AM_DAY_NAMES: Record<number, string> = {
    1: 'ሰኞ', 2: 'ማክሰኞ', 3: 'ረቡዕ', 4: 'ሐሙስ', 5: 'ዓርብ', 6: 'ቅዳሜ', 7: 'እሑድ',
};

// day_of_week: 1=Mon … 6=Sat, 7=Sun (ISO). Jan 2 2023 is a Monday.
function localizedDayName(dayOfWeek: number, locale: string): string {
    if (locale === 'am') return AM_DAY_NAMES[dayOfWeek] ?? String(dayOfWeek);
    const base = new Date(2023, 0, 2);
    base.setDate(base.getDate() + dayOfWeek - 1);
    return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(base);
}


type DayRule = {
    id: string; day_of_week: number; day_name: string;
    is_open: boolean; is_subsidy_day: boolean;
    open_time: string | null; close_time: string | null;
    notes: string | null; can: { update: boolean };
};

const DAY_COLORS: Record<number, string> = {
    1: 'bg-blue-50 dark:bg-blue-950/30',
    2: 'bg-blue-50 dark:bg-blue-950/30',
    3: 'bg-blue-50 dark:bg-blue-950/30',
    4: 'bg-blue-50 dark:bg-blue-950/30',
    5: 'bg-blue-50 dark:bg-blue-950/30',
    6: 'bg-amber-50 dark:bg-amber-950/30',
    7: 'bg-amber-50 dark:bg-amber-950/30',
};

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
            is_open: isOpen,
            is_subsidy_day: isSubsidy,
            open_time: openTime || null,
            close_time: closeTime || null,
        }, {
            onFinish: () => setSaving(false),
            preserveScroll: true,
        });
    }

    const timeCls = 'rounded border border-gray-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-50';

    return (
        <div className={`flex flex-wrap items-center gap-4 rounded-card border border-gray-200 p-4 dark:border-slate-700 ${DAY_COLORS[rule.day_of_week]}`}>
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

            {/* Status badges */}
            <div className="ml-auto flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${isOpen ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                    {isOpen ? t('cafeteria.openDay') : t('cafeteria.closedDay')}
                </span>
                {isOpen && (
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${isSubsidy ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-[color:var(--color-primary)]' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                        {isSubsidy ? t('cafeteria.isSubsidyDay') : t('common.no')}
                    </span>
                )}
                {canUpdate && (
                    <button onClick={save} disabled={saving} className="rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                        {saving ? '…' : t('common.save')}
                    </button>
                )}
            </div>
        </div>
    );
}

export default function DayRulesIndex({ rules, can }: { rules: DayRule[]; can: { update: boolean } }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cafeteria.dayRules')} />}>
            <Head title={t('cafeteria.dayRules')} />
            <div className="space-y-3">
                <p className="text-sm text-gray-500 dark:text-slate-400">
                    {t('cafeteria.specialDayOverrideNote')}
                </p>
                {rules.map(r => (
                    <DayRuleRow key={r.id} rule={r} canUpdate={can.update} />
                ))}
                {rules.length === 0 && (
                    <div className="rounded-card border border-dashed border-gray-300 p-10 text-center text-sm text-gray-400 dark:border-slate-700">
                        {t('common.noResults')}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
