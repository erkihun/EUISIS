import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableRowClassName } from '@/Components/ui';
type Party = {
    id: string;
    name_en: string | null;
    name_am: string | null;
    code: string | null;
};

type ReportingLine = {
    id: string;
    source_type: 'institution_office' | 'organization_unit';
    source?: Party | null;
    target_type: string;
    target_id: string;
    target: Party | null;
    relationship_type: string;
    relationship_label: string;
    is_primary: boolean;
    status: string;
    effective_from: string | null;
    effective_to: string | null;
};

type Filters = {
    q: string | null;
    source_type: string | null;
    relationship_type: string | null;
    target_type: string | null;
};

type Options = {
    sourceTypes: string[];
    relationshipTypes: string[];
    targetTypes: string[];
};

/** Laravel pagination labels arrive HTML-encoded. */
function paginationLabel(label: string): string {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&amp;/g, '&');
}

export default function ReportingLinesIndex({
    lines,
    filters,
    options,
    summary,
}: {
    lines: {
        data: ReportingLine[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: { total: number; from: number | null; to: number | null; current_page: number; last_page: number };
    };
    filters: Filters;
    options: Options;
    summary: Array<{ relationship_type: string; count: number }>;
}) {
    const { t, locale } = useLocale();
    const [form, setForm] = useState({
        q: filters.q ?? '',
        source_type: filters.source_type ?? '',
        relationship_type: filters.relationship_type ?? '',
        target_type: filters.target_type ?? '',
    });

    const hasFilters = Boolean(form.q || form.source_type || form.relationship_type || form.target_type);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(route('reporting-lines.index'), form, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function reset() {
        setForm({ q: '', source_type: '', relationship_type: '', target_type: '' });
        router.get(route('reporting-lines.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    /** The source page a row links back to, so a line is never a dead end. */
    function sourceHref(line: ReportingLine): string | null {
        if (!line.source?.id) return null;
        return line.source_type === 'institution_office'
            ? route('institution-offices.show', line.source.id)
            : route('organization-units.show', line.source.id);
    }

    function partyLabel(party: Party | null | undefined, fallback: string): { name: string; code: string | null } {
        if (!party) return { name: fallback, code: null };
        return { name: localizedName(party.name_en, party.name_am, locale) || fallback, code: party.code };
    }

    const inputCls = cx(inputClassName, 'w-full');
    const labelCls = 'mb-1 block text-xs font-medium text-[color:var(--app-muted-foreground)]';
    const thCls = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[color:var(--app-muted-foreground)]';
    const tdCls = 'px-4 py-3 text-sm text-[color:var(--app-foreground)]';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('nav.reportingLines')}
                    description={t('relationships.reportingLinesDescription')}
                />
            }
        >
            <Head title={t('nav.reportingLines')} />

            <div className="space-y-6">
                {/* Counts per relationship type — the shape of the network at a glance. */}
                <div className="flex flex-wrap gap-2">
                    <span className="inline-flex items-center gap-2 rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-1.5 text-sm">
                        <span className="text-[color:var(--app-muted-foreground)]">{t('relationships.totalReportingLines')}</span>
                        <span className="font-semibold tabular-nums text-[color:var(--app-foreground)]">{lines.meta.total}</span>
                    </span>
                    {summary.map((entry) => (
                        <span
                            key={entry.relationship_type}
                            className="inline-flex items-center gap-2 rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-3 py-1.5 text-sm"
                        >
                            <span className="text-[color:var(--app-muted-foreground)]">{t(`relationships.types.${entry.relationship_type}`)}</span>
                            <span className="font-semibold tabular-nums text-[color:var(--app-foreground)]">{entry.count}</span>
                        </span>
                    ))}
                </div>

                <form onSubmit={submit} className="rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label htmlFor="reporting-lines-q" className={labelCls}>{t('common.search')}</label>
                            <input
                                id="reporting-lines-q"
                                className={inputCls}
                                value={form.q}
                                onChange={(e) => setForm({ ...form, q: e.target.value })}
                                placeholder={t('relationships.searchSourcePlaceholder')}
                            />
                        </div>
                        <div>
                            <label htmlFor="reporting-lines-source-type" className={labelCls}>{t('relationships.sourceType')}</label>
                            <select
                                id="reporting-lines-source-type"
                                className={inputCls}
                                value={form.source_type}
                                onChange={(e) => setForm({ ...form, source_type: e.target.value })}
                            >
                                <option value="">{t('common.all')}</option>
                                {options.sourceTypes.map((type) => (
                                    <option key={type} value={type}>{t(`relationships.sourceTypes.${type}`)}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="reporting-lines-type" className={labelCls}>{t('relationships.type')}</label>
                            <select
                                id="reporting-lines-type"
                                className={inputCls}
                                value={form.relationship_type}
                                onChange={(e) => setForm({ ...form, relationship_type: e.target.value })}
                            >
                                <option value="">{t('common.all')}</option>
                                {options.relationshipTypes.map((type) => (
                                    <option key={type} value={type}>{t(`relationships.types.${type}`)}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="reporting-lines-target-type" className={labelCls}>{t('relationships.targetType')}</label>
                            <select
                                id="reporting-lines-target-type"
                                className={inputCls}
                                value={form.target_type}
                                onChange={(e) => setForm({ ...form, target_type: e.target.value })}
                            >
                                <option value="">{t('common.all')}</option>
                                {options.targetTypes.map((type) => (
                                    <option key={type} value={type}>{t(`relationships.targetTypes.${type}`)}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="mt-4 flex items-center gap-2">
                        <button
                            type="submit"
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {t('common.filter')}
                        </button>
                        {hasFilters && (
                            <button
                                type="button"
                                onClick={reset}
                                className={buttonClassName({ variant: 'outline' })}
                            >
                                {t('common.reset')}
                            </button>
                        )}
                    </div>
                </form>

                <div className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    {lines.data.length === 0 ? (
                        <EmptyState
                            title={t('relationships.noReportingLines')}
                            description={t('relationships.noReportingLinesHint')}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={cx(tableClassName, 'min-w-full text-left')}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={thCls}>{t('relationships.source')}</th>
                                        <th className={thCls}>{t('relationships.type')}</th>
                                        <th className={thCls}>{t('relationships.target')}</th>
                                        <th className={thCls}>{t('relationships.effectiveFrom')}</th>
                                        <th className={thCls}>{t('relationships.effectiveTo')}</th>
                                        <th className={thCls}>{t('relationships.status')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {lines.data.map((line) => {
                                        const source = partyLabel(line.source, '—');
                                        const target = partyLabel(line.target, line.target_id);
                                        const href = sourceHref(line);

                                        return (
                                            <tr key={line.id} className={tableRowClassName}>
                                                <td className={tableCellClassName}>
                                                    <div className="font-medium text-[color:var(--app-foreground)]">
                                                        {href ? (
                                                            <Link href={href} className="hover:text-[color:var(--color-primary)] hover:underline">
                                                                {source.name}
                                                            </Link>
                                                        ) : source.name}
                                                    </div>
                                                    <div className="text-xs text-[color:var(--app-muted-foreground)]">
                                                        {source.code && <span className="font-mono">{source.code} · </span>}
                                                        {t(`relationships.sourceTypes.${line.source_type}`)}
                                                    </div>
                                                </td>
                                                <td className={tdCls}>
                                                    <span className="font-medium text-[color:var(--app-foreground)]">
                                                        {t(`relationships.types.${line.relationship_type}`)}
                                                    </span>
                                                    {line.is_primary && (
                                                        <span className="ms-2 rounded-control bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-xs text-[color:var(--app-muted-foreground)]">
                                                            {t('relationships.primary')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className={tableCellClassName}>
                                                    <div className="font-medium text-[color:var(--app-foreground)]">{target.name}</div>
                                                    <div className="text-xs text-[color:var(--app-muted-foreground)]">
                                                        {target.code && <span className="font-mono">{target.code} · </span>}
                                                        {t(`relationships.targetTypes.${line.target_type}`)}
                                                    </div>
                                                </td>
                                                <td className={tdCls}><LocalizedDateDisplay value={line.effective_from} /></td>
                                                <td className={tdCls}><LocalizedDateDisplay value={line.effective_to} /></td>
                                                <td className={tableCellClassName}>
                                                    <StatusBadge status={line.status} label={t(`relationships.statuses.${line.status}`)} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {lines.meta.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <span className="text-sm text-[color:var(--app-muted-foreground)]">
                            {lines.meta.from}–{lines.meta.to} / {lines.meta.total}
                        </span>
                        <div className="flex flex-wrap gap-1">
                            {lines.links.map((link, index) => (
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        preserveScroll
                                        className={`rounded-control px-3 py-1.5 text-sm ${
                                            link.active
                                                ? 'bg-[color:var(--color-primary)] text-white'
                                                : 'border border-[color:var(--app-border)] text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]'
                                        }`}
                                    >
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span key={index} className="rounded-control px-3 py-1.5 text-sm text-gray-300 dark:text-slate-600">
                                        {paginationLabel(link.label)}
                                    </span>
                                )
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
