import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEvent } from 'react';

type Employee = {
    id: string;
    employee_number: string;
    full_name: string;
    status: string;
    current_assignment?: { organization?: { name_en: string } | null } | null;
};

type PageProps = {
    employees: Employee[];
    requestTypes: string[];
};

export default function CardRequestCreate({ employees, requestTypes }: PageProps) {
    const { t } = useLocale();
    const idCards = new Proxy({} as Record<string, string>, { get: (_, k) => t(`idCards.${String(k)}`) });

    const form = useForm({
        employee_id: employees[0]?.id ?? '',
        request_type: 'new',
        reason: '',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        form.post(route('card-requests.store'));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={idCards.createRequest ?? 'Create Card Request'}
                    description=""
                />
            }
        >
            <Head title={idCards.createRequest ?? 'Create Card Request'} />

            <div className="max-w-2xl">
                <div className="rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {/* Employee */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                                {t('entitlements.employee')}
                            </label>
                            <select
                                className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                                value={form.data.employee_id}
                                onChange={(e) => form.setData('employee_id', e.target.value)}
                            >
                                {employees.map((emp) => (
                                    <option key={emp.id} value={emp.id}>
                                        {emp.employee_number} · {emp.full_name}
                                        {emp.current_assignment?.organization?.name_en ? ` · ${emp.current_assignment.organization.name_en}` : ''}
                                    </option>
                                ))}
                            </select>
                            {form.errors.employee_id && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.employee_id}</p>
                            )}
                        </div>

                        {/* Request type */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                                {idCards.requestType ?? 'Request Type'}
                            </label>
                            <select
                                className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                                value={form.data.request_type}
                                onChange={(e) => form.setData('request_type', e.target.value)}
                            >
                                {requestTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {idCards[`requestType_${type}`] ?? type}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Reason */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                                {idCards.requestReason ?? 'Reason'} <span className="text-gray-400">(optional)</span>
                            </label>
                            <textarea
                                className="mt-1 min-h-[80px] w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
                                placeholder={idCards.requestReason}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-3 pt-2">
                            <Link
                                href={route('card-requests.index')}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300"
                            >
                                {t('common.cancel')}
                            </Link>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                            >
                                {form.processing ? t('common.saving') : (idCards.submitRequest ?? 'Submit Request')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
