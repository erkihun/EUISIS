import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import { inputCls, labelCls, panelCls, primaryBtn } from '@/Components/dailyActivity/helpers';
import type { ManagementAbilities } from '@/Components/dailyActivity/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import type { JSX } from 'react';

type Field = {
    key: string;
    type: string;
    value: unknown;
    label_en: string;
    label_am: string;
    description_en: string | null;
    description_am: string | null;
    options: string[] | null;
};

type Props = {
    fields: Field[];
    canUpdate: boolean;
    can: ManagementAbilities;
};

const TIME_FIELDS = new Set(['submission_deadline', 'reminder_time']);

/** Related rules shown together; any field not listed falls into "other". */
const SECTIONS: { key: string; fields: string[] }[] = [
    { key: 'general', fields: ['enabled', 'require_daily_submission', 'tracking_start_date', 'work_week_days'] },
    { key: 'submission', fields: ['submission_deadline', 'require_late_reason', 'reject_late_submission', 'allow_backdated_submission', 'max_backdate_days', 'require_output_result'] },
    { key: 'review', fields: ['manager_review_required', 'notify_on_approval'] },
    { key: 'measurement', fields: ['structured_entry_required', 'task_score_rule'] },
    { key: 'reminders', fields: ['auto_reminder_enabled', 'reminder_time'] },
    { key: 'evidence', fields: ['evidence_attachments_enabled', 'max_attachment_size_kb'] },
];

/** A value that only matters while its switch is on. It is still saved as entered. */
const DEPENDS_ON: Record<string, string> = {
    max_backdate_days: 'allow_backdated_submission',
    reminder_time: 'auto_reminder_enabled',
    max_attachment_size_kb: 'evidence_attachments_enabled',
};

/**
 * Daily Activities > Settings: the city-wide module rules. They apply to
 * every institution. Reviewer assignments are organization-scoped and have
 * their own page; nothing here grants a permission.
 */
export default function DailyActivitiesSettings({ fields, canUpdate, can }: Props): JSX.Element {
    const { t, locale } = useLocale();

    type SettingValue = string | number | boolean | string[] | null;
    const settingsForm = useForm<Record<string, SettingValue>>(
        Object.fromEntries(fields.map((field) => [field.key, (field.value ?? null) as SettingValue])),
    );

    const label = (field: Field) => (locale === 'am' ? field.label_am : field.label_en);
    const description = (field: Field) => (locale === 'am' ? field.description_am : field.description_en);
    const settingErrors = settingsForm.errors as Record<string, string | undefined>;
    const byKey = new Map(fields.map((field) => [field.key, field]));
    const listed = new Set(SECTIONS.flatMap((section) => section.fields));
    const sections = [
        ...SECTIONS.map((section) => ({ key: section.key, fields: section.fields.map((key) => byKey.get(key)).filter((f): f is Field => f !== undefined) })),
        { key: 'other', fields: fields.filter((field) => !listed.has(field.key)) },
    ].filter((section) => section.fields.length > 0);

    function saveSettings(event: React.FormEvent) {
        event.preventDefault();
        settingsForm.put(route('daily-activities.settings.update'), { preserveScroll: true });
    }

    function renderControl(field: Field) {
        const value = settingsForm.data[field.key];
        const parent = DEPENDS_ON[field.key];
        const parentOff = parent !== undefined && byKey.has(parent) && !settingsForm.data[parent];
        const disabled = !canUpdate || parentOff;
        const id = `setting-${field.key}`;
        const parentHint = parentOff && parent
            ? <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{t('dailyActivities.settings.dependsOn').replace('{setting}', label(byKey.get(parent) as Field))}</p>
            : null;

        if (field.type === 'boolean') {
            return (
                <label htmlFor={id} className="flex min-h-10 cursor-pointer items-start gap-3">
                    <input id={id} type="checkbox" disabled={disabled} className="mt-0.5 h-5 w-5 rounded border-gray-300" checked={Boolean(value)} onChange={(e) => settingsForm.setData(field.key, e.target.checked)} />
                    <span>
                        <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label(field)}</span>
                        {description(field) && <span className="block text-xs text-gray-500 dark:text-slate-400">{description(field)}</span>}
                    </span>
                </label>
            );
        }

        let control: JSX.Element;
        if (field.type === 'multiselect') {
            const selected = new Set(((value as string[] | null) ?? []).map(String));
            control = (
                <div className="flex flex-wrap gap-2" role="group" aria-labelledby={`${id}-label`}>
                    {(field.options ?? []).map((option) => (
                        <label key={option} className="flex min-h-10 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm dark:border-slate-700">
                            <input
                                type="checkbox"
                                disabled={disabled}
                                checked={selected.has(option)}
                                onChange={(e) => {
                                    const next = new Set(selected);
                                    if (e.target.checked) next.add(option); else next.delete(option);
                                    settingsForm.setData(field.key, [...next].sort());
                                }}
                            />
                            {t(`dailyActivities.settings.weekdays.${option}`)}
                        </label>
                    ))}
                </div>
            );
        } else if (field.type === 'select') {
            control = (
                <select id={id} disabled={disabled} className={inputCls} value={String(value ?? '')} onChange={(e) => settingsForm.setData(field.key, e.target.value)}>
                    {(field.options ?? []).map((option) => <option key={option} value={option}>{t(`dailyActivities.settings.options.${field.key}.${option}`)}</option>)}
                </select>
            );
        } else if (field.type === 'date') {
            control = <LocalizedDatePicker id={id} value={String(value ?? '')} disabled={disabled} onChange={(v) => settingsForm.setData(field.key, v)} />;
        } else if (field.type === 'integer') {
            control = <input id={id} type="number" inputMode="numeric" disabled={disabled} className={inputCls} value={String(value ?? '')} onChange={(e) => settingsForm.setData(field.key, e.target.value)} />;
        } else {
            control = <input id={id} type={TIME_FIELDS.has(field.key) ? 'time' : 'text'} disabled={disabled} className={inputCls} value={String(value ?? '')} onChange={(e) => settingsForm.setData(field.key, e.target.value)} />;
        }

        return (
            <div>
                <label id={`${id}-label`} htmlFor={id} className={labelCls}>{label(field)}</label>
                {control}
                {description(field) && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description(field)}</p>}
                {parentHint}
            </div>
        );
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.settings.title')} description={t('dailyActivities.settings.description')} />}>
            <Head title={t('dailyActivities.settings.title')} />
            <div className="space-y-4">
                <ManagementNav can={can} current="daily-activities.settings" />
                <form onSubmit={saveSettings} className={`${panelCls} p-4`}>
                    <h2 className="mb-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.settings.rules')}</h2>
                    {!canUpdate && <p className="mb-3 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.readOnly')}</p>}
                    <div className="divide-y divide-gray-100 dark:divide-slate-800">
                        {sections.map((section) => (
                            <fieldset key={section.key} className="py-4 first:pt-2 last:pb-0">
                                <legend className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{t(`dailyActivities.settings.sections.${section.key}`)}</legend>
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                    {section.fields.map((field) => (
                                        <div key={field.key} className={field.type === 'multiselect' ? 'sm:col-span-2 xl:col-span-3' : ''}>
                                            {renderControl(field)}
                                            {settingErrors[field.key] && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{settingErrors[field.key]}</p>}
                                        </div>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                    {canUpdate && (
                        <div className="mt-4 flex justify-end border-t border-gray-100 pt-4 dark:border-slate-800">
                            <button type="submit" disabled={settingsForm.processing} className={`${primaryBtn} w-full sm:w-auto`}>{t('dailyActivities.actions.saveSettings')}</button>
                        </div>
                    )}
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
