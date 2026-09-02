import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Briefcase, ClipboardCheckIcon, Inbox } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Summary = {
    total_positions: number;
    filled_positions: number;
    vacant_positions: number;
};

type PositionStatusRow = {
    id: string;
    position_id: string;
    establishment_id: string | null;
    establishment_number: string | null;
    job_position_code: string | null;
    title_en: string | null;
    title_am: string | null;
    grade_level: string | null;
    job_family: string | null;
    organization_name_en: string | null;
    organization_name_am: string | null;
    department_name_en: string | null;
    department_name_am: string | null;
    is_active: boolean;
    total_positions: number;
    filled_positions: number;
    vacant_positions: number;
};

type SelectOption = {
    id: string;
    name_en: string | null;
    name_am: string | null;
    organization_id?: string;
};

type PaginatedPositions = {
    data: PositionStatusRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

type Props = {
    summary: Summary;
    positions: PaginatedPositions;
    organizations: SelectOption[];
    organizationUnits: SelectOption[];
    isOrganizationScoped: boolean;
    filters: Record<string, string>;
};

function metricTone(index: number): string {
    return [
        'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300',
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    ][index];
}

export default function PositionStatus({ summary, positions, organizations, organizationUnits, isOrganizationScoped, filters }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const form = useForm({
        search: filters.search ?? '',
        organization_id: filters.organization_id ?? '',
        organization_unit_id: filters.organization_unit_id ?? '',
        grade_level: filters.grade_level ?? '',
        is_active: filters.is_active ?? '',
        per_page: filters.per_page ?? String(positions.meta.per_page ?? 15),
    });

    const metrics = [
        { label: t('positions.totalJobPositions'), value: summary.total_positions, icon: Briefcase },
        { label: t('positions.filledPositions'), value: summary.filled_positions, icon: ClipboardCheckIcon },
        { label: t('positions.vacantPositions'), value: summary.vacant_positions, icon: Inbox },
    ];

    const inputClass = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function optionLabel(option: SelectOption): string {
        return (useAmharic ? option.name_am : option.name_en) ?? option.name_en ?? option.id;
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('positions.status'), form.data, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        router.get(route('positions.status'), {}, { preserveState: true, preserveScroll: true });
    }

    function goToPage(page: number) {
        router.get(route('positions.status'), { ...form.data, page }, { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('positions.newJobPositionsStatus')} description={t('positions.newJobPositionsStatusDescription')} />}
        >
            <Head title={t('positions.newJobPositionsStatus')} />

            <div className="space-y-6">
                <section className="grid gap-4 md:grid-cols-3">
                    {metrics.map((metric, index) => {
                        const Icon = metric.icon;

                        return (
                            <div key={metric.label} className={`rounded-lg border p-5 ${metricTone(index)}`}>
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium opacity-80">{metric.label}</p>
                                        <p className="mt-2 text-3xl font-semibold">{metric.value.toLocaleString()}</p>
                                    </div>
                                    <span className="flex h-11 w-11 items-center justify-center rounded-lg bg-white/70 dark:bg-white/10">
                                        <Icon className="h-5 w-5" />
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </section>

                <section className="rounded-xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form className={`grid gap-3 ${isOrganizationScoped ? 'lg:grid-cols-4' : 'lg:grid-cols-5'}`} onSubmit={submit}>
                        <input
                            className={`${inputClass} lg:col-span-2`}
                            value={form.data.search}
                            placeholder={t('positions.searchPositions')}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                        {!isOrganizationScoped && (
                            <select
                                className={inputClass}
                                value={form.data.organization_id}
                                onChange={(event) => {
                                    form.setData('organization_id', event.target.value);
                                    form.setData('organization_unit_id', '');
                                }}
                            >
                                <option value="">{t('positions.organization')}</option>
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>{optionLabel(organization)}</option>
                                ))}
                            </select>
                        )}
                        <select
                            className={inputClass}
                            value={form.data.organization_unit_id}
                            onChange={(event) => form.setData('organization_unit_id', event.target.value)}
                        >
                            <option value="">{t('positions.organizationUnit')}</option>
                            {organizationUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>{optionLabel(unit)}</option>
                            ))}
                        </select>
                        <div className="flex gap-2">
                            <select className={`${inputClass} min-w-0 flex-1`} value={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.value)}>
                                <option value="">{t('common.status')}</option>
                                <option value="1">{t('common.active')}</option>
                                <option value="0">{t('common.inactive')}</option>
                            </select>
                            <button type="submit" className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                {t('common.filter')}
                            </button>
                        </div>
                        <div className={`flex gap-2 ${isOrganizationScoped ? 'lg:col-span-4' : 'lg:col-span-5'}`}>
                            <input
                                className={`${inputClass} max-w-xs`}
                                value={form.data.grade_level}
                                placeholder={t('positions.gradeLevel')}
                                onChange={(event) => form.setData('grade_level', event.target.value)}
                            />
                            <select className={`${inputClass} max-w-[120px]`} value={form.data.per_page} onChange={(event) => form.setData('per_page', event.target.value)}>
                                {[10, 15, 25, 50].map((size) => <option key={size} value={size}>{size}</option>)}
                            </select>
                            <button type="button" className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800" onClick={clearFilters}>
                                {t('common.clear')}
                            </button>
                        </div>
                    </form>
                </section>

                <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {positions.data.length === 0 ? (
                        <div className="p-10 text-center text-sm text-gray-500 dark:text-slate-400">{t('positions.noPositionStatusFound')}</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-gray-50 dark:bg-slate-950">
                                    <tr>
                                        {[
                                            ...(isOrganizationScoped ? [] : [t('positions.organization')]),
                                            t('positions.organizationUnit'),
                                            t('positions.jobPositionCode'),
                                            t('positions.positionTitle'),
                                            t('positions.gradeLevel'),
                                            t('common.status'),
                                            t('positions.occupancy'),
                                            '',
                                        ].map((heading, index) => (
                                            <th key={index} className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {positions.data.map((position) => {
                                        const organizationName = (useAmharic ? position.organization_name_am : position.organization_name_en) ?? position.organization_name_en ?? '-';
                                        const departmentName = (useAmharic ? position.department_name_am : position.department_name_en) ?? position.department_name_en ?? t('positions.unassignedDepartment');

                                        return (
                                            <tr key={position.id} className="text-gray-700 dark:text-slate-200">
                                                {!isOrganizationScoped && (
                                                    <td className="whitespace-nowrap px-4 py-3">{organizationName}</td>
                                                )}
                                                <td className="whitespace-nowrap px-4 py-3">{departmentName}</td>
                                                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                                    <Link href={route('positions.show', position.position_id)} className="text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                                        {position.job_position_code ?? position.establishment_number ?? '-'}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3">{(useAmharic ? position.title_am : position.title_en) ?? position.title_en ?? '-'}</td>
                                                <td className="whitespace-nowrap px-4 py-3">{position.grade_level ?? '-'}</td>
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${position.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-300'}`}>
                                                        {position.is_active ? t('common.active') : t('common.inactive')}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {position.vacant_positions > 0 ? (
                                                        <span className="rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/30 dark:text-amber-300">
                                                            {t('positions.vacant')}
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                                                            {t('positions.filled')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    {position.vacant_positions > 0 && position.establishment_id && (
                                                        <Link
                                                            href={route('vacancy-announcements.create', { establishment: position.establishment_id })}
                                                            className="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700"
                                                        >
                                                            {t('vacancies.announceVacancy')}
                                                        </Link>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-gray-100 px-4 py-3 text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            {positions.meta.from ?? 0}-{positions.meta.to ?? 0} / {positions.meta.total}
                        </span>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                disabled={positions.meta.current_page <= 1}
                                onClick={() => goToPage(positions.meta.current_page - 1)}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                            >
                                {t('common.previous')}
                            </button>
                            <span>{t('common.page')} {positions.meta.current_page} {t('common.of')} {positions.meta.last_page}</span>
                            <button
                                type="button"
                                disabled={positions.meta.current_page >= positions.meta.last_page}
                                onClick={() => goToPage(positions.meta.current_page + 1)}
                                className="rounded-lg border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700"
                            >
                                {t('common.next')}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
