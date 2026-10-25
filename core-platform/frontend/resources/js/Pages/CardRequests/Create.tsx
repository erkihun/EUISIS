import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent, useEffect, useRef, useState } from 'react';

import { buttonClassName, cx, selectClassName } from '@/Components/ui';
type Employee = {
    id: string;
    employee_number: string;
    full_name: string;
    status: string;
    eligible_request_types: string[];
    current_assignment?: { organization?: { name_en: string; name_am?: string } | null } | null;
};
type PageProps = { employees: Employee[]; employeesTruncated: boolean; filters: { q: string }; requestTypes: string[] };
const panel = 'rounded-panel border border-(--app-border) bg-(--app-surface) shadow-xs';
const input = cx(selectClassName, 'w-full');
const textButton = 'rounded-sm px-2 py-1.5 text-xs font-semibold text-primary hover:bg-(--app-surface-muted) focus-visible:outline-solid focus-visible:outline-2 disabled:opacity-40';

export default function CardRequestCreate({ employees, employeesTruncated, filters, requestTypes }: PageProps) {
    const { t, locale } = useLocale();
    const label = (key: string) => t('idCards.' + key);
    const form = useForm({ employee_ids: [] as string[], request_type: 'new', reason: '' });
    const [search, setSearch] = useState(filters.q ?? '');
    const firstRender = useRef(true);
    // The employee list is searched on the server and bounded (SCL-10); the
    // selection lives in the form, so it survives each reload.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = window.setTimeout(() => {
            router.reload({ only: ['employees', 'employeesTruncated', 'filters'], data: { q: search.trim() } });
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search]);
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
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={label('createRequest')} description={label('createRequestDescription')} backHref={route('card-requests.index')} />}>
            <Head title={label('createRequest')} />
            <form onSubmit={handleSubmit} className="w-full">
                <fieldset disabled={form.processing} className="grid min-w-0 items-start gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
                    <aside className={panel + ' p-5 sm:p-6 lg:sticky lg:top-6'} aria-labelledby="request-details-heading">
                        <h2 id="request-details-heading" className="mb-5 flex items-center gap-3 font-semibold text-(--app-foreground)"><Step number="01" />{label('requestDetails')}</h2>
                        <div className="space-y-5">
                            <div>
                                <label htmlFor="request-type" className="mb-2 block text-sm font-medium text-(--app-foreground)">{label('requestType')}</label>
                                <select id="request-type" className={input} value={form.data.request_type} onChange={(event) => {
                                    form.setData((data) => ({ ...data, request_type: event.target.value, employee_ids: [] }));
                                    form.clearErrors();
                                    setSelectedOnly(false);
                                }}>
                                    {requestTypes.map((type) => <option key={type} value={type}>{label('requestType_' + type)}</option>)}
                                </select>
                                <p className="mt-2 text-xs leading-5 text-(--app-muted-foreground)">{label('eligibility_' + form.data.request_type)}</p>
                                <p className="mt-2 text-xs leading-5 text-(--app-muted-foreground)">{label('typeSelectionHelp')}</p>
                                {form.errors.request_type && <p role="alert" className="mt-1 text-xs text-red-600">{form.errors.request_type}</p>}
                            </div>
                            <div>
                                <label htmlFor="request-reason" className="mb-2 flex flex-wrap justify-between gap-1 text-sm font-medium text-(--app-foreground)">{label('requestReason')}<span className="text-xs font-normal text-(--app-muted-foreground)">{t('common.optional')}</span></label>
                                <textarea id="request-reason" className={input + ' min-h-32 resize-y'} maxLength={1000} placeholder={label('requestReason')} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} aria-describedby="reason-length" />
                                <p id="reason-length" className="mt-1 text-right text-xs tabular-nums text-(--app-muted-foreground)">{form.data.reason.length} / 1000</p>
                                {form.errors.reason && <p role="alert" className="mt-1 text-xs text-red-600">{form.errors.reason}</p>}
                            </div>
                            <div className="rounded-lg bg-(--app-surface-muted) p-4">
                                <p className="text-sm font-semibold text-(--app-foreground)" aria-live="polite">{countLabel}</p>
                                <p className="mt-2 text-xs leading-5 text-(--app-muted-foreground)">{label('bulkRequestHelp')}</p>
                            </div>
                            <button type="submit" disabled={form.processing || selected.size === 0} className={buttonClassName({ variant: 'primary', size: 'lg', className: 'w-full' })}>{form.processing ? t('common.saving') : label('submitRequest') + ' (' + selected.size + ')'}</button>
                            <Link href={route('card-requests.index')} className="block rounded-control py-2 text-center text-sm font-medium text-(--app-muted-foreground) hover:text-(--app-foreground)">{t('common.cancel')}</Link>
                        </div>
                    </aside>
                    <section className={panel + ' min-w-0 overflow-hidden'} aria-labelledby="employees-heading">
                        <div className="border-b border-(--app-border) p-5 sm:p-6">
                            <div className="flex items-center justify-between gap-3">
                                <h2 id="employees-heading" className="flex items-center gap-3 font-semibold text-(--app-foreground)"><Step number="02" />{label('selectEmployees')}</h2>
                                <span className="rounded-full bg-(--app-surface-muted) px-3 py-1 text-xs tabular-nums text-(--app-muted-foreground)">{selected.size} / 500</span>
                            </div>
                            <label htmlFor="employee-search" className="sr-only">{label('searchEmployees')}</label>
                            <div className="relative mt-5">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="pointer-events-none absolute left-3 top-3 h-5 w-5 text-(--app-muted-foreground)"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg>
                                <input id="employee-search" type="search" className={input + ' pl-10'} placeholder={label('searchEmployees')} value={search} onChange={(event) => setSearch(event.target.value)} />
                            </div>
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <label className="flex cursor-pointer items-center gap-2 text-xs text-(--app-muted-foreground)">
                                    <input type="checkbox" className="rounded-sm border-(--app-border-strong) text-primary" checked={selectedOnly} onChange={(event) => setSelectedOnly(event.target.checked)} />
                                    {label('showSelectedOnly')}
                                </label>
                                <div className="flex flex-wrap gap-1">
                                    <button type="button" className={textButton} disabled={remaining === 0 || selected.size === eligibleEmployees.length} onClick={() => form.setData('employee_ids', [...form.data.employee_ids, ...eligibleEmployees.filter((employee) => !selected.has(employee.id)).slice(0, remaining).map((employee) => employee.id)])}>{label(eligibleEmployees.length > 500 ? 'selectAllEmployeesLimit' : 'selectAllEmployees')}</button>
                                    <button type="button" className={textButton} disabled={remaining === 0 || selectable.length === 0} onClick={() => form.setData('employee_ids', [...form.data.employee_ids, ...selectable.slice(0, remaining).map((employee) => employee.id)])}>{label('selectResults')}</button>
                                    <button type="button" className={textButton} disabled={selected.size === 0} onClick={() => form.setData('employee_ids', [])}>{label('clearSelection')}</button>
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-wrap justify-between gap-2 bg-(--app-surface-muted) px-5 py-2.5 text-xs text-(--app-muted-foreground) sm:px-6">
                            <span>{label('matchingEmployees').replace(':count', String(visibleEmployees.length))}</span>
                            <span aria-live="polite">{countLabel}</span>
                        </div>
                        {employeesTruncated && <p role="status" className="border-b border-(--app-border) px-5 py-2.5 text-xs text-amber-700 dark:text-amber-300 sm:px-6">{label('employeesTruncated').replace(':count', String(employees.length))}</p>}
                        <div className="max-h-128 overflow-y-auto overscroll-contain">
                            {visibleEmployees.map((employee) => {
                                const checked = selected.has(employee.id);
                                return (
                                    <label key={employee.id} className={'flex cursor-pointer items-center gap-3 border-b border-(--app-border) px-5 py-4 transition last:border-0 sm:px-6 ' + (checked ? 'bg-blue-50/70 dark:bg-blue-950/25' : 'hover:bg-(--app-surface-muted)')}>
                                        <input type="checkbox" className="h-4 w-4 shrink-0 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary disabled:opacity-40" checked={checked} disabled={!checked && remaining === 0} onChange={(event) => form.setData('employee_ids', event.target.checked ? [...form.data.employee_ids, employee.id] : form.data.employee_ids.filter((id) => id !== employee.id))} />
                                        <span aria-hidden="true" className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full bg-(--app-surface-muted) text-sm font-semibold text-(--app-muted-foreground) sm:flex">{employee.full_name.trim().slice(0, 1)}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block wrap-break-word text-sm font-semibold text-(--app-foreground)">{employee.full_name}</span>
                                            <span className="mt-1 block wrap-break-word text-xs leading-5 text-(--app-muted-foreground)">{employee.employee_number}{organizationName(employee) && ' · ' + organizationName(employee)}</span>
                                        </span>
                                    </label>
                                );
                            })}
                            {visibleEmployees.length === 0 && <div className="px-6 py-16 text-center"><p className="text-sm font-medium text-(--app-foreground)">{label('noEmployeesFound')}</p><p className="mt-2 text-xs text-(--app-muted-foreground)">{label('adjustEmployeeFilters')}</p></div>}
                        </div>
                        {remaining === 0 && <p role="status" className="border-t border-(--app-border) p-4 text-sm text-amber-700 dark:text-amber-300">{label('selectionLimitReached')}</p>}
                        {Object.entries(form.errors).filter(([key]) => key.startsWith('employee_ids')).map(([key, error]) => <p key={key} role="alert" className="px-5 py-3 text-sm text-red-600 dark:text-red-400">{error}</p>)}
                    </section>
                </fieldset>
            </form>
        </AuthenticatedLayout>
    );
}

function Step({ number }: { number: string }) {
    return <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-sm font-bold text-primary dark:bg-slate-800" aria-hidden="true">{number}</span>;
}
