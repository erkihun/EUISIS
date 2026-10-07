import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import ManagementNav from '@/Components/dailyActivity/ManagementNav';
import { inputCls, labelCls, named, panelCls, primaryBtn, secondaryBtn } from '@/Components/dailyActivity/helpers';
import type { ManagementAbilities, Option } from '@/Components/dailyActivity/types';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type AssignmentStatus = 'active' | 'scheduled' | 'ended';

type Assignment = {
    id: string;
    status: AssignmentStatus;
    reviewer: { id: number; name: string; email: string } | null;
    organization: { name_en: string; name_am: string | null } | null;
    organization_unit: { name_en: string; name_am: string | null } | null;
    include_sub_units: boolean;
    employee: { full_name: string; employee_number: string } | null;
    effective_from: string | null;
    effective_to: string | null;
    covered_employees: number;
};

type Policy = { organization_id: string; organization: { name_en: string; name_am: string | null } | null; review_mode: string };

type Props = {
    reviewers: Assignment[];
    options: {
        organizations: Option[];
        units: Option[];
        employees: { id: string; full_name: string; employee_number: string }[];
        employeesTruncated: boolean;
        users: { id: number; name: string; email: string }[];
    };
    selectedOrganizationId: string | null;
    employeeSearch: string;
    policies: Policy[];
    cityDefaultMode: string;
    reviewModes: string[];
    can: ManagementAbilities;
};

/** Above this, one reviewer is unlikely to keep up with daily review. */
const LARGE_COVERAGE = 150;

const STATUS_STYLE: Record<AssignmentStatus, string> = {
    active: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
    scheduled: 'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900',
    ended: 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
};

/**
 * Daily Activities > Reviewer Assignments. Organization-scoped: the lists and
 * every write are confined to the organizations the user administers, and
 * re-checked on the server. Assigning a reviewer grants no permission.
 */
export default function DailyActivitiesReviewerAssignments({ reviewers, options, selectedOrganizationId, employeeSearch, policies, cityDefaultMode, reviewModes, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [search, setSearch] = useState(employeeSearch);

    const reviewerForm = useForm({
        reviewer_user_id: '',
        organization_id: selectedOrganizationId ?? '',
        organization_unit_id: '',
        include_sub_units: true,
        employee_id: '',
        effective_from: '',
        effective_to: '',
    });

    const policyForm = useForm({ organization_id: '', review_mode: 'inherit' });

    function choosePolicyOrganization(id: string) {
        const current = policies.find((policy) => policy.organization_id === id);
        policyForm.setData({ organization_id: id, review_mode: current?.review_mode ?? 'inherit' });
    }

    function savePolicy(event: React.FormEvent) {
        event.preventDefault();
        policyForm.put(route('daily-activities.reviewers.policy'), { preserveScroll: true });
    }

    function reloadOptions(organizationId: string, employeeSearchTerm: string) {
        const query: Record<string, string> = {};
        if (organizationId) query.organization_id = organizationId;
        if (organizationId && employeeSearchTerm.trim()) query.employee_search = employeeSearchTerm.trim();
        // Units and employees are loaded for the chosen organization only.
        router.get(route('daily-activities.reviewers.index'), query, { preserveState: true, preserveScroll: true, only: ['options', 'selectedOrganizationId', 'employeeSearch'] });
    }

    function chooseOrganization(id: string) {
        reviewerForm.setData({ ...reviewerForm.data, organization_id: id, organization_unit_id: '', employee_id: '' });
        setSearch('');
        reloadOptions(id, '');
    }

    function addReviewer(event: React.FormEvent) {
        event.preventDefault();
        reviewerForm.post(route('daily-activities.reviewers.store'), {
            preserveScroll: true,
            onSuccess: () => reviewerForm.reset('reviewer_user_id', 'organization_unit_id', 'employee_id', 'effective_from', 'effective_to'),
        });
    }

    async function endReviewer(assignment: Assignment) {
        const { confirmed } = await confirm({
            title: t('dailyActivities.settings.endReviewer'),
            description: `${assignment.reviewer?.name ?? ''} — ${t('dailyActivities.settings.endReviewerHint')}`,
            confirmLabel: t('dailyActivities.settings.endReviewer'),
            cancelLabel: t('dailyActivities.actions.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(route('daily-activities.reviewers.destroy', assignment.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('dailyActivities.settings.reviewersPageTitle')} description={t('dailyActivities.settings.reviewersPageDescription')} />}>
            <Head title={t('dailyActivities.settings.reviewersPageTitle')} />
            <div className="space-y-4">
                <ManagementNav can={can} current="daily-activities.reviewers.index" />

                <section className={`${panelCls} p-4`} aria-labelledby="add-reviewer-heading">
                    <h2 id="add-reviewer-heading" className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.actions.addReviewer')}</h2>
                    <p className="mb-3 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.reviewersHint')}</p>

                    <form onSubmit={addReviewer} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
                            <label htmlFor="rv-employee-search" className={labelCls}>{t('dailyActivities.settings.employeeSearch')}</label>
                            <div className="flex gap-2">
                                <input
                                    id="rv-employee-search"
                                    type="search"
                                    className={inputCls}
                                    value={search}
                                    disabled={!reviewerForm.data.organization_id}
                                    placeholder={t('dailyActivities.settings.employeeSearchPlaceholder')}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); reloadOptions(reviewerForm.data.organization_id, search); } }}
                                />
                                <button type="button" className={`${secondaryBtn} shrink-0`} disabled={!reviewerForm.data.organization_id} onClick={() => reloadOptions(reviewerForm.data.organization_id, search)}>
                                    {t('dailyActivities.settings.employeeSearch')}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label htmlFor="rv-employee" className={labelCls}>{t('dailyActivities.settings.employee')}</label>
                            <select id="rv-employee" className={inputCls} value={reviewerForm.data.employee_id} disabled={!options.employees.length} onChange={(e) => reviewerForm.setData('employee_id', e.target.value)}>
                                <option value="">{t('dailyActivities.settings.anyEmployee')}</option>
                                {options.employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_number})</option>)}
                            </select>
                            {options.employeesTruncated && <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.employeesTruncated').replace('{count}', String(options.employees.length))}</p>}
                            {reviewerForm.data.organization_id && employeeSearch && options.employees.length === 0 && <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{t('dailyActivities.settings.noEmployeeMatch')}</p>}
                            {reviewerForm.errors.employee_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.employee_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <label htmlFor="rv-from" className={labelCls}>{t('dailyActivities.settings.effectiveFrom')}</label>
                                <LocalizedDatePicker id="rv-from" value={reviewerForm.data.effective_from} onChange={(v) => reviewerForm.setData('effective_from', v)} />
                            </div>
                            <div>
                                <label htmlFor="rv-to" className={labelCls}>{t('dailyActivities.settings.effectiveTo')}</label>
                                <LocalizedDatePicker id="rv-to" value={reviewerForm.data.effective_to} onChange={(v) => reviewerForm.setData('effective_to', v)} />
                                {reviewerForm.errors.effective_to && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{reviewerForm.errors.effective_to}</p>}
                            </div>
                        </div>
                        <div className="flex flex-col justify-end gap-2 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between lg:col-span-3">
                            <label className="flex min-h-10 items-center gap-2 text-sm">
                                <input type="checkbox" checked={reviewerForm.data.include_sub_units} onChange={(e) => reviewerForm.setData('include_sub_units', e.target.checked)} />
                                {t('dailyActivities.settings.includeSubUnits')}
                            </label>
                            <button type="submit" disabled={reviewerForm.processing} className={primaryBtn}>{t('dailyActivities.actions.addReviewer')}</button>
                        </div>
                    </form>
                </section>

                <section className={`${panelCls} p-4`} aria-labelledby="reviewer-list-heading">
                    <h2 id="reviewer-list-heading" className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.settings.reviewers')}</h2>
                    {reviewers.length === 0 ? (
                        <p className="pt-3 text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.noReviewers')}</p>
                    ) : (
                        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                            {reviewers.map((assignment) => (
                                <li key={assignment.id} className={`flex flex-wrap items-start justify-between gap-2 py-2.5 text-sm ${assignment.status === 'ended' ? 'opacity-75' : ''}`}>
                                    <div className="min-w-0">
                                        <p className="flex flex-wrap items-center gap-2 font-medium text-gray-900 dark:text-slate-100">
                                            {assignment.reviewer?.name ?? '—'}
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_STYLE[assignment.status]}`}>
                                                {t(`dailyActivities.settings.status.${assignment.status}`)}
                                            </span>
                                        </p>
                                        <p className="text-xs text-gray-600 dark:text-slate-400">
                                            {t('dailyActivities.settings.coverage')}:{' '}
                                            {assignment.employee
                                                ? `${assignment.employee.full_name} (${assignment.employee.employee_number})`
                                                : [named(assignment.organization, locale), named(assignment.organization_unit, locale) || t('dailyActivities.settings.wholeOrganization')].filter(Boolean).join(' › ')}
                                            {assignment.organization_unit && assignment.include_sub_units && !assignment.employee && ` + ${t('dailyActivities.settings.includeSubUnits').toLowerCase()}`}
                                            {' · '}<span className="tabular-nums">{t('dailyActivities.settings.coveredEmployees').replace('{count}', String(assignment.covered_employees))}</span>
                                        </p>
                                        {assignment.status !== 'ended' && assignment.covered_employees > LARGE_COVERAGE && (
                                            <p className="mt-1 max-w-2xl text-xs text-amber-700 dark:text-amber-300">{t('dailyActivities.settings.largeCoverage')}</p>
                                        )}
                                        {(assignment.effective_from || assignment.effective_to) && (
                                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                                <LocalizedDateDisplay value={assignment.effective_from} /> – <LocalizedDateDisplay value={assignment.effective_to} />
                                            </p>
                                        )}
                                    </div>
                                    {assignment.status !== 'ended' && (
                                        <button type="button" onClick={() => endReviewer(assignment)} className={`${secondaryBtn} min-h-8 px-3 py-1 text-xs`}>
                                            {t('dailyActivities.settings.endReviewer')}
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className={`${panelCls} p-4`} aria-labelledby="review-policy-heading">
                    <h2 id="review-policy-heading" className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('dailyActivities.settings.policyTitle')}</h2>
                    <p className="mb-3 text-xs text-gray-500 dark:text-slate-400">
                        {t('dailyActivities.settings.policyHint').replace('{mode}', t(`dailyActivities.settings.modes.${cityDefaultMode}`))}
                    </p>
                    <form onSubmit={savePolicy} className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                        <div>
                            <label htmlFor="policy-org" className={labelCls}>{t('dailyActivities.settings.policyOrganization')}</label>
                            <select id="policy-org" className={inputCls} value={policyForm.data.organization_id} onChange={(e) => choosePolicyOrganization(e.target.value)}>
                                <option value="">—</option>
                                {options.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                            </select>
                            {policyForm.errors.organization_id && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{policyForm.errors.organization_id}</p>}
                        </div>
                        <div>
                            <label htmlFor="policy-mode" className={labelCls}>{t('dailyActivities.settings.policyMode')}</label>
                            <select id="policy-mode" className={inputCls} value={policyForm.data.review_mode} disabled={!policyForm.data.organization_id} onChange={(e) => policyForm.setData('review_mode', e.target.value)}>
                                {reviewModes.map((mode) => <option key={mode} value={mode}>{t(`dailyActivities.settings.modes.${mode}`)}</option>)}
                            </select>
                        </div>
                        <button type="submit" disabled={!policyForm.data.organization_id || policyForm.processing} className={primaryBtn}>{t('dailyActivities.settings.savePolicy')}</button>
                    </form>
                    {policies.length === 0 ? (
                        <p className="pt-3 text-sm text-gray-500 dark:text-slate-400">{t('dailyActivities.settings.noPolicies')}</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-gray-100 border-t border-gray-100 dark:divide-slate-800 dark:border-slate-800">
                            {policies.map((policy) => (
                                <li key={policy.organization_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span className="font-medium text-gray-900 dark:text-slate-100">{named(policy.organization, locale)}</span>
                                    <span className="text-gray-600 dark:text-slate-300">{t(`dailyActivities.settings.modes.${policy.review_mode}`)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
