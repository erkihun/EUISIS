import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

type AuditLog = {
    id: string;
    event_type: string;
    actor_name?: string | null;
    actor_user_id?: string | null;
    auditable_type?: string | null;
    auditable_id?: string | null;
    old_values?: Record<string, unknown> | null;
    new_values?: Record<string, unknown> | null;
    created_at: string;
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

/** Convert enum value (dots/underscores) to a flat i18n key. */
function eventKey(eventType: string): string {
    return eventType.replace(/\./g, '_');
}

function ValuesDetail({
    old_values,
    new_values,
    t,
}: {
    old_values?: Record<string, unknown> | null;
    new_values?: Record<string, unknown> | null;
    t: (key: string) => string;
}) {
    const [open, setOpen] = useState(false);
    if (!old_values && !new_values) return null;

    return (
        <div className="mt-1">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="text-xs text-blue-600 hover:underline dark:text-blue-400"
                aria-expanded={open}
            >
                {open ? '▲' : '▼'} {open ? t('auditLogs.hide') : t('auditLogs.show')}
            </button>
            {open && (
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {old_values && (
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('auditLogs.before')}
                            </p>
                            <pre className="mt-1 overflow-x-auto rounded-lg bg-gray-100 p-2 text-xs text-gray-700 dark:bg-slate-800 dark:text-slate-300">
                                {JSON.stringify(old_values, null, 2)}
                            </pre>
                        </div>
                    )}
                    {new_values && (
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400">
                                {t('auditLogs.after')}
                            </p>
                            <pre className="mt-1 overflow-x-auto rounded-lg bg-gray-100 p-2 text-xs text-gray-700 dark:bg-slate-800 dark:text-slate-300">
                                {JSON.stringify(new_values, null, 2)}
                            </pre>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export default function AuditLogsIndex({
    auditLogs,
    meta,
    filters,
}: {
    auditLogs: AuditLog[];
    meta?: Meta;
    filters?: { search?: string; event_type?: string; from?: string; to?: string };
}) {
    const { t } = useLocale();
    const filterForm = useForm({
        search: filters?.search ?? '',
        event_type: filters?.event_type ?? '',
        from: filters?.from ?? '',
        to: filters?.to ?? '',
    });

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

    function submitFilters(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        router.get(route('audit-logs.index'), filterForm.data as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function goToPage(page: number) {
        router.get(route('audit-logs.index'), { ...filterForm.data, page: String(page) }, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function eventLabel(eventType: string): string {
        const key = `auditLogs.events.${eventKey(eventType)}` as Parameters<typeof t>[0];
        const translated = t(key);
        return translated === key
            ? eventType.replace(/[._-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
            : translated;
    }

    const hasActiveFilters = Object.values(filters ?? {}).some(Boolean);

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('auditLogs.title')}
                    description={t('auditLogs.readOnly')}
                />
            }
        >
            <Head title={t('auditLogs.title')} />

            <div className="space-y-4">
                {/* Filters */}
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                    onSubmit={submitFilters}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('auditLogs.actor')}
                        </label>
                        <input
                            className={inputCls}
                            placeholder={t('auditLogs.searchLogs')}
                            value={filterForm.data.search}
                            onChange={(e) => filterForm.setData('search', e.target.value)}
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('auditLogs.event')}
                        </label>
                        <input
                            className={inputCls}
                            placeholder={t('auditLogs.eventPlaceholder')}
                            value={filterForm.data.event_type}
                            onChange={(e) => filterForm.setData('event_type', e.target.value)}
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('common.effectiveFrom')}
                        </label>
                        <LocalizedDatePicker
                            className={inputCls}
                            value={filterForm.data.from}
                            onChange={(iso) => filterForm.setData('from', iso)}
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">
                            {t('common.effectiveTo')}
                        </label>
                        <LocalizedDatePicker
                            className={inputCls}
                            value={filterForm.data.to}
                            onChange={(iso) => filterForm.setData('to', iso)}
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="submit"
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        >
                            {t('common.filter')}
                        </button>
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={() => router.get(route('audit-logs.index'), {}, { preserveState: true })}
                                className="text-sm text-gray-500 underline hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                            >
                                {t('common.reset')}
                            </button>
                        )}
                    </div>
                </form>

                {/* Table */}
                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    {auditLogs.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('auditLogs.noLogs')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                    <tr>
                                        {[
                                            t('auditLogs.event'),
                                            t('auditLogs.actor'),
                                            t('auditLogs.subject'),
                                            t('auditLogs.timestamp'),
                                            t('auditLogs.changes'),
                                        ].map((h) => (
                                            <th
                                                key={h}
                                                className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400"
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {auditLogs.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="text-gray-700 align-top hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800/40"
                                        >
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    status={log.event_type}
                                                    label={eventLabel(log.event_type)}
                                                />
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-gray-500 dark:text-slate-400">
                                                {log.actor_name ?? log.actor_user_id ?? t('auditLogs.system')}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-400 dark:text-slate-500">
                                                {log.auditable_type && (
                                                    <span>
                                                        {log.auditable_type}
                                                        {log.auditable_id && (
                                                            <span className="font-mono"> #{log.auditable_id}</span>
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-xs text-gray-400 dark:text-slate-500">
                                                <LocalizedDateDisplay value={log.created_at} withTime />
                                            </td>
                                            <td className="px-4 py-3">
                                                <ValuesDetail
                                                    old_values={log.old_values}
                                                    new_values={log.new_values}
                                                    t={t}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-gray-600 dark:text-slate-400">
                        <p className="text-xs">
                            {(meta.current_page - 1) * meta.per_page + 1}–{Math.min(meta.current_page * meta.per_page, meta.total)} / {meta.total}
                        </p>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => goToPage(meta.current_page - 1)}
                                disabled={meta.current_page <= 1}
                                className="rounded px-2 py-1 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-slate-800"
                            >
                                {t('common.previous')}
                            </button>
                            {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
                                <button
                                    key={page}
                                    onClick={() => goToPage(page)}
                                    className={`min-w-[2rem] rounded px-2 py-1 font-medium ${
                                        page === meta.current_page
                                            ? 'bg-blue-600 text-white'
                                            : 'hover:bg-gray-100 dark:hover:bg-slate-800'
                                    }`}
                                    aria-current={page === meta.current_page ? 'page' : undefined}
                                >
                                    {page}
                                </button>
                            ))}
                            <button
                                onClick={() => goToPage(meta.current_page + 1)}
                                disabled={meta.current_page >= meta.last_page}
                                className="rounded px-2 py-1 hover:bg-gray-100 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-slate-800"
                            >
                                {t('common.next')}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
