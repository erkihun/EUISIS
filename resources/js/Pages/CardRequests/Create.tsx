import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent, useState } from 'react';

type Employee = {
    id: string;
    employee_number: string;
    full_name: string;
    status: string;
    eligible_request_types: string[];
    current_assignment?: { organization?: { name_en: string; name_am?: string } | null } | null;
};
type PageProps = { employees: Employee[]; requestTypes: string[] };
const panel = 'rounded-panel border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
const input = 'w-full rounded-control border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100';
const textButton = 'rounded px-2 py-1.5 text-xs font-semibold text-[color:var(--color-primary)] hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 disabled:opacity-40 dark:hover:bg-slate-800';

export default function CardRequestCreate({ employees, requestTypes }: PageProps) {
    const { t, locale } = useLocale();
    const label = (key: string) => t('idCards.' + key);
    const form = useForm({ employee_ids: [] as string[], request_type: 'new', reason: '' });
    const [search, setSearch] = useState('');
    const [selectedOnly, setSelectedOnly] = useState(false);
    const selected = new Set(form.data.employee_ids);
    const eligibleEmployees = employees.filter((employee) => employee.eligible_request_types.includes(form.data.request_type));
    const organizationName = (employee: Employee) => {
        const org = employee.current_assignment?.organization;
        return (locale === 'am' ? org?.name_am || org?.name_en : org?.name_en || org?.name_am) ?? '';
    };
    const visibleEmployees = eligibleEmployees.filter((employee) =>
        (!selectedOnly || selected.has(employee.id)) &&
        [employee.employee_number, employee.full_name, employee.current_assignment?.organization?.name_en, employee.current_assignment?.organization?.name_am].join(' ').toLowerCase().includes(search.trim().toLowerCase()),
    );
    const selectable = visibleEmployees.filter((employee) => !selected.has(employee.id));
    const remaining = 500 - selected.size;
    const countLabel = label('selectedEmployees').replace(':count', String(selected.size));

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        if (!form.processing && selected.size > 0) form.post(route('card-requests.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={label('createRequest')} description={label('createRequestDescription')} backHref={route('card-requests.index')} />}>
            <Head title={label('createRequest')} />
            <form onSubmit={handleSubmit} className="w-full">
                <fieldset disabled={form.processing} className="grid min-w-0 items-start gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
                    <aside className={panel + ' p-5 sm:p-6 lg:sticky lg:top-6'} aria-labelledby="request-details-heading">
                        <h2 id="request-details-heading" className="mb-5 flex items-center gap-3 font-semibold text-gray-900 dark:text-slate-100"><Step number="01" />{label('requestDetails')}</h2>
                        <div className="space-y-5">
                            <div>
                                <label htmlFor="request-type" className="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300">{label('requestType')}</label>
                                <select id="request-type" className={input} value={form.data.request_type} onChange={(event) => {
                                    form.setData((data) => ({ ...data, request_type: event.target.value, employee_ids: [] }));
                                    form.clearErrors();
                                    setSelectedOnly(false);
                                }}>
                                    {requestTypes.map((type) => <option key={type} value={type}>{label('requestType_' + type)}</option>)}
                                </select>
                                <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-slate-400">{label('eligibility_' + form.data.request_type)}</p>
                                <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-slate-400">{label('typeSelectionHelp')}</p>
                                {form.errors.request_type && <p role="alert" className="mt-1 text-xs text-red-600">{form.errors.request_type}</p>}
                            </div>
                            <div>
                                <label htmlFor="request-reason" className="mb-2 flex flex-wrap justify-between gap-1 text-sm font-medium text-gray-700 dark:text-slate-300">{label('requestReason')}<span className="text-xs font-normal text-gray-400">{t('common.optional')}</span></label>
                                <textarea id="request-reason" className={input + ' min-h-32 resize-y'} maxLength={1000} placeholder={label('requestReason')} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} aria-describedby="reason-length" />
                                <p id="reason-length" className="mt-1 text-right text-xs tabular-nums text-gray-400">{form.data.reason.length} / 1000</p>
                                {form.errors.reason && <p role="alert" className="mt-1 text-xs text-red-600">{form.errors.reason}</p>}
                            </div>
                            <div className="rounded-lg bg-gray-50 p-4 dark:bg-slate-950/50">
                                <p className="text-sm font-semibold text-gray-900 dark:text-slate-100" aria-live="polite">{countLabel}</p>
                                <p className="mt-2 text-xs leading-5 text-gray-500 dark:text-slate-400">{label('bulkRequestHelp')}</p>
                            </div>
                            <button type="submit" disabled={form.processing || selected.size === 0} className="w-full rounded-control bg-[color:var(--color-primary)] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[color:var(--color-primary-hover)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50">{form.processing ? t('common.saving') : label('submitRequest') + ' (' + selected.size + ')'}</button>
                            <Link href={route('card-requests.index')} className="block rounded-control py-2 text-center text-sm font-medium text-gray-500 hover:text-gray-900 dark:text-slate-400 dark:hover:text-white">{t('common.cancel')}</Link>
                        </div>
                    </aside>
                    <section className={panel + ' min-w-0 overflow-hidden'} aria-labelledby="employees-heading">
                        <div className="border-b border-gray-100 p-5 dark:border-slate-800 sm:p-6">
                            <div className="flex items-center justify-between gap-3">
                                <h2 id="employees-heading" className="flex items-center gap-3 font-semibold text-gray-900 dark:text-slate-100"><Step number="02" />{label('selectEmployees')}</h2>
                                <span className="rounded-full bg-gray-100 px-3 py-1 text-xs tabular-nums text-gray-600 dark:bg-slate-800 dark:text-slate-300">{selected.size} / 500</span>
                            </div>
                            <label htmlFor="employee-search" className="sr-only">{label('searchEmployees')}</label>
                            <div className="relative mt-5">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="pointer-events-none absolute left-3 top-3 h-5 w-5 text-gray-400"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg>
                                <input id="employee-search" type="search" className={input + ' pl-10'} placeholder={label('searchEmployees')} value={search} onChange={(event) => setSearch(event.target.value)} />
                            </div>
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <label className="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-slate-300">
                                    <input type="checkbox" className="rounded border-gray-300 text-[color:var(--color-primary)]" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />
                                    {label('showSelectedOnly')}
                                </label>
                                <div className="flex flex-wrap gap-1">
                                    <button type="button" className={textButton} disabled={remaining === 0 || selected.size === eligibleEmployees.length} onClick={() => form.setData('employee_ids', [...form.data.employee_ids, ...eligibleEmployees.filter((employee) => !selected.has(employee.id)).slice(0, remaining).map((employee) => employee.id)])}>{label(eligibleEmployees.length > 500 ? 'selectAllEmployeesLimit' : 'selectAllEmployees')}</button>
                                    <button type="button" className={textButton} disabled={remaining === 0 || selectable.length === 0} onClick={() => form.setData('employee_ids', [...form.data.employee_ids, ...selectable.slice(0, remaining).map((employee) => employee.id)])}>{label('selectResults')}</button>
                                    <button type="button" className={textButton} disabled={selected.size === 0} onClick={() => form.setData('employee_ids', [])}>{label('clearSelection')}</button>
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-wrap justify-between gap-2 bg-gray-50 px-5 py-2.5 text-xs text-gray-500 dark:bg-slate-950/40 dark:text-slate-400 sm:px-6">
                            <span>{label('matchingEmployees').replace(':count', String(visibleEmployees.length))}</span>
                            <span aria-live="polite">{countLabel}</span>
                        </div>
                        <div className="max-h-[32rem] overflow-y-auto overscroll-contain">
                            {visibleEmployees.map((employee) => {
                                const checked = selected.has(employee.id);
                                return (
                                    <label key={employee.id} className={'flex cursor-pointer items-center gap-3 border-b border-gray-100 px-5 py-4 transition last:border-0 dark:border-slate-800 sm:px-6 ' + (checked ? 'bg-blue-50/70 dark:bg-blue-950/25' : 'hover:bg-gray-50 dark:hover:bg-slate-800/50')}>
                                        <input type="checkbox" className="h-4 w-4 shrink-0 rounded border-gray-300 text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)] disabled:opacity-40" checked={checked} disabled={!checked && remaining === 0} onChange={(event) => form.setData('employee_ids', event.target.checked ? [...form.data.employee_ids, employee.id] : form.data.employee_ids.filter((id) => id !== employee.id))} />
                                        <span aria-hidden="true" className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-500 dark:bg-slate-800 dark:text-slate-300 sm:flex">{employee.full_name.trim().slice(0, 1)}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block break-words text-sm font-semibold text-gray-900 dark:text-slate-100">{employee.full_name}</span>
                                            <span className="mt-1 block break-words text-xs leading-5 text-gray-500 dark:text-slate-400">{employee.employee_number}{organizationName(employee) && ' · ' + organizationName(employee)}</span>
                                        </span>
                                    </label>
                                );
                            })}
                            {visibleEmployees.length === 0 && <div className="px-6 py-16 text-center"><p className="text-sm font-medium text-gray-700 dark:text-slate-200">{label('noEmployeesFound')}</p><p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{label('adjustEmployeeFilters')}</p></div>}
                        </div>
                        {remaining === 0 && <p role="status" className="border-t border-gray-100 p-4 text-sm text-amber-700 dark:border-slate-800 dark:text-amber-300">{label('selectionLimitReached')}</p>}
                        {Object.entries(form.errors).filter(([key]) => key.startsWith('employee_ids')).map(([key, error]) => <p key={key} role="alert" className="px-5 py-3 text-sm text-red-600 dark:text-red-400">{error}</p>)}
                    </section>
                </fieldset>
            </form>
        </AuthenticatedLayout>
    );
}

function Step({ number }: { number: string }) {
    return <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-sm font-bold text-[color:var(--color-primary)] dark:bg-slate-800" aria-hidden="true">{number}</span>;
}
