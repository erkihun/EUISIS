import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';
import type { Option } from '@/Components/dailyActivity/types';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
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

type Assignment = {
    id: string;
    reviewer: { id: number; name: string; email: string } | null;
    organization: { name_en: string; name_am: string | null } | null;
    organization_unit: { name_en: string; name_am: string | null } | null;
    include_sub_units: boolean;
    employee: { full_name: string; employee_number: string } | null;
    effective_from: string | null;
    effective_to: string | null;
};

type Props = {
    fields: Field[];
    reviewers: Assignment[];
    options: {
        organizations: Option[];
        units: Option[];
        employees: { id: string; full_name: string; employee_number: string }[];
        users: { id: number; name: string; email: string }[];
    } | null;
    selectedOrganizationId: string | null;
    can: { viewSettings: boolean; updateSettings: boolean; manageReviewers: boolean };
};

const TIME_FIELDS = new Set(['submission_deadline', 'reminder_time']);

/**
 * Daily Activities > Settings. Module rules and reviewer assignments only;
 * nothing here grants a permission. Each half is shown only to the user who
 * may manage it, and both are re-authorised on the server.
 */
export default function DailyActivitiesSettings({ fields, reviewers, options, selectedOrganizationId, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();

    type SettingValue = string | number | boolean | string[] | null;
    const settingsForm = useForm<Record<string, SettingValue>>(
        Object.fromEntries(fields.map((field) => [field.key, (field.value ?? null) as SettingValue])),
    );
    const reviewerForm = useForm({
        reviewer_user_id: '',
        organization_id: selectedOrganizationId ?? '',
        organization_unit_id: '',
        include_sub_units: true,
        employee_id: '',
        effective_from: '',
        effective_to: '',
    });

    const label = (field: Field) => (locale === 'am' ? field.label_am : field.label_en);
    const description = (field: Field) => (locale === 'am' ? field.description_am : field.description_en);
    const settingErrors = settingsForm.errors as Record<string, string | undefined>;

    function saveSettings(event: React.FormEvent) {
        event.preventDefault();
        settingsForm.put(route('daily-activities.settings.update'), { preserveScroll: true });
    }

    function chooseOrganization(id: string) {
        reviewerForm.setData({ ...reviewerForm.data, organization_id: id, organization_unit_id: '', employee_id: '' });
        // Units and employees are loaded for the chosen organization only.
        router.get(route('daily-activities.settings'), id ? { organization_id: id } : {}, { preserveState: true, preserveScroll: true, only: ['options', 'selectedOrganizationId'] });
    }

    function addReviewer(event: React.FormEvent) {
        event.preventDefault();
        reviewerForm.post(route('daily-activities.settings.reviewers.store'), {
            preserveScroll: true,
            onSuccess: () => reviewerForm.reset('reviewer_user_id', 'organization_unit_id', 'employee_id', 'effective_from', 'effective_to'),
        });
    }

    async function removeReviewer(id: string) {
        const { confirmed } = await confirm({
            title: t('dailyActivities.actions.removeReviewer'),
            confirmLabel: t('dailyActivities.actions.removeReviewer'),
            cancelLabel: t('dailyActivities.actions.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('daily-activities.settings.reviewers.destroy', id), { preserveScroll: true });
    }

    function renderControl(field: Field) {
        const value = settingsForm.data[field.key];
        const disabled = !can.updateSettings;
        const id = `setting-${field.key}`;

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
                <div className="flex flex-wrap gap-2">
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
        } else if (field.type === 'date') {
            control = <LocalizedDatePicker id={id} value={String(value ?? '')} disabled={disabled} onChange={(v) => settingsForm.setData(field.key, v)} />;
        } else if (field.type === 'integer') {
            control = <input id={id} type="number" inputMode="numeric" disabled={disabled} className={inputCls} value={String(value ?? '')} onChange={(e) => settingsForm.setData(field.key, e.target.value)} />;
        } else {
            control = <input id={id} type={TIME_FIELDS.has(field.key) ? 'time' : 'text'} disabled={disabled} className={inputCls} value={String(value ?? '')} onChange={(e) => settingsForm.setData(field.key, e.target.value)} />;
        }

        return (
            <div>
                <label htmlFor={id} className={labelCls}>{label(field)}</label>
                {control}
                {description(field) && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description(field)}</p>}
            </div>
        );
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.settings.title')} description={t('dailyActivities.settings.description')} />}>
            <Head title={t('dailyActivities.settings.title')} />
            <div className="mx-auto max-w-4xl space-y-6">
                {can.viewSettings && (
                    <form onSubmit={saveSettings} className={`${panelCls} p-4`}>
                        <h2 className="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.settings.rules')}</h2>
                        {!can.updateSettings && <p className="mb-3 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.readOnly')}</p>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {fields.map((field) => (
                                <div key={field.key} className={field.type === 'multiselect' ? 'sm:col-span-2' : ''}>
                                    {renderControl(field)}
                                    {settingErrors[field.key] && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{settingErrors[field.key]}</p>}
                                </div>
                            ))}
                        </div>
                        {can.updateSettings && (
                            <div className="mt-4 flex justify-end">
                                <button type="submit" disabled={settingsForm.processing} className={`${primaryBtn} w-full sm:w-auto`}>{t('dailyActivities.actions.saveSettings')}</button>
                            </div>
                        )}
                    </form>
                )}

                {can.manageReviewers && options && (
                    <section className={`${panelCls} p-4`}>
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.settings.reviewers')}</h2>
                        <p className="mb-3 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.reviewersHint')}</p>

                        <form onSubmit={addReviewer} className="grid gap-3 border-b border-gray-100 pb-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-slate-800">
                            <div>
                                <label htmlFor="rv-user" className={labelCls}>{t('dailyActivities.settings.reviewer')}</label>
                                <select id="rv-user" className={inputCls} value={reviewerForm.data.reviewer_user_id} onChange={(e) => reviewerForm.setData('reviewer_user_id', e.target.value)}>
                                    <option value="">—</option>
                                    {options.users.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.email})</option>)}
                                </select>
                                {reviewerForm.errors.reviewer_user_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.reviewer_user_id}</p>}
                            </div>
                            <div>
                                <label htmlFor="rv-org" className={labelCls}>{t('dailyActivities.settings.organization')}</label>
                                <select id="rv-org" className={inputCls} value={reviewerForm.data.organization_id} onChange={(e) => chooseOrganization(e.target.value)}>
                                    <option value="">—</option>
                                    {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                                </select>
                                {reviewerForm.errors.organization_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.organization_id}</p>}
                            </div>
                            <div>
                                <label htmlFor="rv-unit" className={labelCls}>{t('dailyActivities.settings.unit')}</label>
                                <select id="rv-unit" className={inputCls} value={reviewerForm.data.organization_unit_id} disabled={!options.units.length} onChange={(e) => reviewerForm.setData('organization_unit_id', e.target.value)}>
                                    <option value="">{t('dailyActivities.settings.wholeOrganization')}</option>
                                    {options.units.map((u) => <option key={u.id} value={u.id}>{named(u, locale)}</option>)}
                                </select>
                                {reviewerForm.errors.organization_unit_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.organization_unit_id}</p>}
                            </div>
                            <div>
                                <label htmlFor="rv-employee" className={labelCls}>{t('dailyActivities.settings.employee')}</label>
                                <select id="rv-employee" className={inputCls} value={reviewerForm.data.employee_id} disabled={!options.employees.length} onChange={(e) => reviewerForm.setData('employee_id', e.target.value)}>
                                    <option value="">{t('dailyActivities.settings.anyEmployee')}</option>
                                    {options.employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_number})</option>)}
                                </select>
                                {reviewerForm.errors.employee_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.employee_id}</p>}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className={labelCls}>{t('dailyActivities.settings.effectiveFrom')}</label>
                                    <LocalizedDatePicker value={reviewerForm.data.effective_from} onChange={(v) => reviewerForm.setData('effective_from', v)} />
                                </div>
                                <div>
                                    <label className={labelCls}>{t('dailyActivities.settings.effectiveTo')}</label>
                                    <LocalizedDatePicker value={reviewerForm.data.effective_to} onChange={(v) => reviewerForm.setData('effective_to', v)} />
                                </div>
                            </div>
                            <div className="flex flex-col justify-end gap-2">
                                <label className="flex min-h-10 items-center gap-2 text-sm">
                                    <input type="checkbox" checked={reviewerForm.data.include_sub_units} onChange={(e) => reviewerForm.setData('include_sub_units', e.target.checked)} />
                                    {t('dailyActivities.settings.includeSubUnits')}
                                </label>
                                <button type="submit" disabled={reviewerForm.processing} className={primaryBtn}>{t('dailyActivities.actions.addReviewer')}</button>
                            </div>
                        </form>

                        {reviewers.length === 0 ? (
                            <p className="pt-3 text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.noReviewers')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                {reviewers.map((assignment) => (
                                    <li key={assignment.id} className="flex flex-wrap items-start justify-between gap-2 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <p className="font-medium text-gray-900 dark:text-slate-100">{assignment.reviewer?.name ?? '—'}</p>
                                            <p className="text-xs text-gray-600 dark:text-slate-400">
                                                {t('dailyActivities.settings.coverage')}:{' '}
                                                {assignment.employee
                                                    ? `${assignment.employee.full_name} (${assignment.employee.employee_number})`
                                                    : [named(assignment.organization, locale), named(assignment.organization_unit, locale) || t('dailyActivities.settings.wholeOrganization')].filter(Boolean).join(' › ')}
                                                {assignment.organization_unit && assignment.include_sub_units && !assignment.employee && ` + ${t('dailyActivities.settings.includeSubUnits').toLowerCase()}`}
                                            </p>
                                            {(assignment.effective_from || assignment.effective_to) && (
                                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                                    <LocalizedDateDisplay value={assignment.effective_from} /> – <LocalizedDateDisplay value={assignment.effective_to} />
                                                </p>
                                            )}
                                        </div>
                                        <button type="button" onClick={() => removeReviewer(assignment.id)} className={`${secondaryBtn} min-h-8 px-3 py-1 text-xs`}>
                                            {t('dailyActivities.actions.removeReviewer')}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
