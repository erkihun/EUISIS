import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';

import { FilterField, FilterPanel, Input, ListPagination, Select, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, type LaravelPageMeta } from '@/Components/ui';
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

export default function ReportingLinesIndex({
    lines,
    filters,
    options,
    summary,
}: {
    lines: {
        data: ReportingLine[];
        meta: LaravelPageMeta;
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

    const hasFilters = Boolean(filters.q || filters.source_type || filters.relationship_type || filters.target_type);

    function submit() {
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

    const thCls = cx(tableHeaderCellClassName, 'text-left');
    const tdCls = tableCellClassName;

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
                    <span className="inline-flex items-center gap-2 rounded-control border border-(--app-border) bg-(--app-surface) px-3 py-1.5 text-sm">
                        <span className="text-(--app-muted-foreground)">{t('relationships.totalReportingLines')}</span>
                        <span className="font-semibold tabular-nums text-(--app-foreground)">{lines.meta.total}</span>
                    </span>
                    {summary.map((entry) => (
                        <span
                            key={entry.relationship_type}
                            className="inline-flex items-center gap-2 rounded-control border border-(--app-border) bg-(--app-surface) px-3 py-1.5 text-sm"
                        >
                            <span className="text-(--app-muted-foreground)">{t(`relationships.types.${entry.relationship_type}`)}</span>
                            <span className="font-semibold tabular-nums text-(--app-foreground)">{entry.count}</span>
                        </span>
                    ))}
                </div>

                <FilterPanel onSubmit={submit} onReset={reset} hasActiveFilters={hasFilters}>
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={form.q} onChange={(e) => setForm({ ...form, q: e.target.value })} placeholder={t('relationships.searchSourcePlaceholder')} />
                    </FilterField>
                    <FilterField label={t('relationships.sourceType')}>
                        <Select value={form.source_type} onChange={(e) => setForm({ ...form, source_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {options.sourceTypes.map((type) => (
                                <option key={type} value={type}>{t(`relationships.sourceTypes.${type}`)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('relationships.type')}>
                        <Select value={form.relationship_type} onChange={(e) => setForm({ ...form, relationship_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {options.relationshipTypes.map((type) => (
                                <option key={type} value={type}>{t(`relationships.types.${type}`)}</option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('relationships.targetType')}>
                        <Select value={form.target_type} onChange={(e) => setForm({ ...form, target_type: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {options.targetTypes.map((type) => (
                                <option key={type} value={type}>{t(`relationships.targetTypes.${type}`)}</option>
                            ))}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
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
                                                    <div className="font-medium text-(--app-foreground)">
                                                        {href ? (
                                                            <Link href={href} className="hover:text-primary hover:underline">
                                                                {source.name}
                                                            </Link>
                                                        ) : source.name}
                                                    </div>
                                                    <div className="text-xs text-(--app-muted-foreground)">
                                                        {source.code && <span className="font-mono">{source.code} · </span>}
                                                        {t(`relationships.sourceTypes.${line.source_type}`)}
                                                    </div>
                                                </td>
                                                <td className={tdCls}>
                                                    <span className="font-medium text-(--app-foreground)">
                                                        {t(`relationships.types.${line.relationship_type}`)}
                                                    </span>
                                                    {line.is_primary && (
                                                        <span className="ms-2 rounded-control bg-(--app-surface-muted) px-2 py-0.5 text-xs text-(--app-muted-foreground)">
                                                            {t('relationships.primary')}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className={tableCellClassName}>
                                                    <div className="font-medium text-(--app-foreground)">{target.name}</div>
                                                    <div className="text-xs text-(--app-muted-foreground)">
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
                    <ListPagination meta={lines.meta} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
