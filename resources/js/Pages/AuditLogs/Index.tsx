import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
                className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                aria-expanded={open}
            >
                {open ? '▲' : '▼'} {open ? t('auditLogs.hide') : t('auditLogs.show')}
            </button>
            {open && (
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {old_values && (
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('auditLogs.before')}
                            </p>
                            <pre className="mt-1 overflow-x-auto rounded-lg bg-[color:var(--app-surface-muted)] p-2 text-xs text-[color:var(--app-foreground)]">
                                {JSON.stringify(old_values, null, 2)}
                            </pre>
                        </div>
                    )}
                    {new_values && (
                        <div>
                            <p className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                                {t('auditLogs.after')}
                            </p>
                            <pre className="mt-1 overflow-x-auto rounded-lg bg-[color:var(--app-surface-muted)] p-2 text-xs text-[color:var(--app-foreground)]">
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

    const inputCls = inputClassName;

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
                    className="flex flex-wrap items-end gap-3 rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4"
                    onSubmit={submitFilters}
                >
                    <div>
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                            {t('common.effectiveFrom')}
                        </label>
                        <LocalizedDatePicker
                            className={inputCls}
                            value={filterForm.data.from}
                            onChange={(iso) => filterForm.setData('from', iso)}
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
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
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {t('common.filter')}
                        </button>
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={() => router.get(route('audit-logs.index'), {}, { preserveState: true })}
                                className="text-sm text-[color:var(--app-muted-foreground)] underline hover:text-[color:var(--app-foreground)]"
                            >
                                {t('common.reset')}
                            </button>
                        )}
                    </div>
                </form>

                {/* Table */}
                <div className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {auditLogs.length === 0 ? (
                        <div className="p-6">
                            <EmptyState title={t('auditLogs.noLogs')} />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
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
                                                className={cx(tableHeaderCellClassName, 'text-left')}
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {auditLogs.map((log) => (
                                        <tr
                                            key={log.id}
                                            className={tableRowClassName}
                                        >
                                            <td className={tableCellClassName}>
                                                <StatusBadge
                                                    status={log.event_type}
                                                    label={eventLabel(log.event_type)}
                                                />
                                            </td>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-muted-foreground)]')}>
                                                {log.actor_name ?? log.actor_user_id ?? t('auditLogs.system')}
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-xs text-[color:var(--app-muted-foreground)]')}>
                                                {log.auditable_type && (
                                                    <span>
                                                        {log.auditable_type}
                                                        {log.auditable_id && (
                                                            <span className="font-mono"> #{log.auditable_id}</span>
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap text-xs text-[color:var(--app-muted-foreground)]')}>
                                                <LocalizedDateDisplay value={log.created_at} withTime />
                                            </td>
                                            <td className={tableCellClassName}>
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
                    <div className="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-[color:var(--app-muted-foreground)]">
                        <p className="text-xs">
                            {(meta.current_page - 1) * meta.per_page + 1}–{Math.min(meta.current_page * meta.per_page, meta.total)} / {meta.total}
                        </p>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => goToPage(meta.current_page - 1)}
                                disabled={meta.current_page <= 1}
                                className="rounded px-2 py-1 hover:bg-[color:var(--app-surface-muted)] disabled:pointer-events-none disabled:opacity-40"
                            >
                                {t('common.previous')}
                            </button>
                            {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
                                <button
                                    key={page}
                                    onClick={() => goToPage(page)}
                                    className={`min-w-[2rem] rounded px-2 py-1 font-medium ${
                                        page === meta.current_page
                                            ? 'bg-[color:var(--color-primary)] text-white'
                                            : 'hover:bg-[color:var(--app-surface-muted)]'
                                    }`}
                                    aria-current={page === meta.current_page ? 'page' : undefined}
                                >
                                    {page}
                                </button>
                            ))}
                            <button
                                onClick={() => goToPage(meta.current_page + 1)}
                                disabled={meta.current_page >= meta.last_page}
                                className="rounded px-2 py-1 hover:bg-[color:var(--app-surface-muted)] disabled:pointer-events-none disabled:opacity-40"
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
