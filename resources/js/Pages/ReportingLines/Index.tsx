import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Alert, FilterField, FilterPanel, Input, Select, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useState } from 'react';

type OrganizationOption = { id: string; name_en: string; name_am: string | null; code: string | null };
type Holder = { employee_id: string; employee_number: string | null; name: string | null; name_am: string | null; basis: string; basis_label: string };
type Holders = { state: string; holders: Holder[] };
type HeadInfo = {
    position_id: string; title_en: string; title_am: string | null; code: string | null; active: boolean;
    reports_to: { position_id: string | null; title_en: string | null; basis: string | null; basis_label: string | null } | null;
    link_outcome: string | null; link_outcome_label: string | null; link_explanation: string | null;
};
type UnitRow = {
    id: string; code: string | null; name_en: string; name_am: string | null; parent_id: string | null; parent_name_en: string | null; parent_name_am: string | null; depth: number;
    head: HeadInfo | null; several_heads: boolean; holders: Holders; state: string;
};
type Finding = { code: string; severity: string; title: string; unit_id: string | null; unit_name_en: string | null; unit_name_am: string | null; position_id: string | null; position_title_en: string | null; position_title_am: string | null; detail: string; count: number | null; extra: string | null };
type Overview = {
    organization_head: {
        position: { position_id: string; title_en: string; title_am: string | null; code: string | null; active: boolean } | null;
        several: boolean; holders: Holders; state: string;
        reports_to: { outcome: string; outcome_label: string; explanation: string; organization_name_en: string | null; organization_name_am: string | null; position_title_en: string | null } | null;
    };
    units: UnitRow[];
    diagnostics: { summary: { errors: number; warnings: number; units: number; units_with_head: number; positions: number }; findings: Finding[]; head_indexes: Record<string, boolean> };
    acting_active: number;
    delegations_active: number;
};

const STATE_TONES: Record<string, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
    held: 'success', acting: 'info', vacant: 'warning', missing: 'warning', inactive: 'danger', ambiguous: 'danger', several_heads: 'danger',
};

export default function ReportingLinesIndex({ organizations, selected_organization_id, as_of, overview, structural_reporting_enabled, can }: {
    organizations: OrganizationOption[];
    selected_organization_id: string | null;
    as_of: string;
    overview: Overview | null;
    structural_reporting_enabled: boolean;
    can: { manage: boolean; edit_positions: boolean };
}) {
    const { t, locale } = useLocale();
    const [organizationId, setOrganizationId] = useState(selected_organization_id ?? '');
    const [asOf, setAsOf] = useState(as_of);
    const [severity, setSeverity] = useState('');

    function submit() {
        router.get(route('reporting-lines.overview'), { organization_id: organizationId, as_of: asOf }, { preserveState: true, preserveScroll: true });
    }

    const holderText = (holders: Holders): string => {
        if (holders.holders.length === 0) return t('reportingLines.states.vacant');
        return holders.holders.map((h) => `${locale === 'am' ? (h.name_am ?? h.name) : h.name}${h.basis === 'acting' ? ` (${h.basis_label})` : ''}`).join(', ');
    };
    const stateLabel = (state: string): string => t(`reportingLines.states.${state}`);
    const findings = overview ? overview.diagnostics.findings.filter((f) => severity === '' || f.severity === severity) : [];

    return (
        <AuthenticatedLayout header={<PageHeader title={t('reportingLines.title')} description={t('reportingLines.overviewDescription')} />}>
            <Head title={t('reportingLines.title')} />
            <div className="space-y-6">
                <Alert tone={structural_reporting_enabled ? 'success' : 'info'} title={structural_reporting_enabled ? t('reportingLines.structuralEnabled') : t('reportingLines.structuralDisabled')}>
                    {t('reportingLines.structuralHint')}
                </Alert>

                <FilterPanel onSubmit={submit} hasActiveFilters={false}>
                    <FilterField label={t('reportingLines.organization')} wide>
                        <Select value={organizationId} onChange={(e) => setOrganizationId(e.target.value)}>
                            {organizations.map((o) => <option key={o.id} value={o.id}>{localizedName(o.name_en, o.name_am, locale)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('reportingLines.asOf')}>
                        <Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
                    </FilterField>
                </FilterPanel>

                {!overview ? (
                    <EmptyState title={t('reportingLines.pickOrganization')} />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            {[
                                [t('reportingLines.units'), overview.diagnostics.summary.units],
                                [t('reportingLines.unitsWithHead'), overview.diagnostics.summary.units_with_head],
                                [t('reportingLines.positions'), overview.diagnostics.summary.positions],
                                [t('reportingLines.errors'), overview.diagnostics.summary.errors],
                                [t('reportingLines.warnings'), overview.diagnostics.summary.warnings],
                            ].map(([label, value]) => (
                                <div key={String(label)} className="rounded-panel border border-(--app-border) bg-(--app-surface) p-4">
                                    <div className="text-xs text-(--app-muted-foreground)">{label}</div>
                                    <div className="mt-1 text-2xl font-semibold tabular-nums text-(--app-foreground)">{value}</div>
                                </div>
                            ))}
                        </div>

                        {/* Organization head */}
                        <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.organizationHead')}</h3>
                                <span className={badgeClassName(STATE_TONES[overview.organization_head.state] ?? 'neutral')}>{stateLabel(overview.organization_head.state)}</span>
                            </div>
                            {overview.organization_head.position ? (
                                <dl className="mt-3 grid gap-x-8 gap-y-3 sm:grid-cols-3">
                                    <div>
                                        <dt className="text-xs text-(--app-muted-foreground)">{t('reportingLines.headPosition')}</dt>
                                        <dd className="text-sm text-(--app-foreground)">
                                            {can.edit_positions ? <Link href={route('positions.show', overview.organization_head.position.position_id)} className="text-primary hover:underline">{localizedName(overview.organization_head.position.title_en, overview.organization_head.position.title_am, locale)}</Link> : localizedName(overview.organization_head.position.title_en, overview.organization_head.position.title_am, locale)}
                                            {overview.organization_head.position.code && <span className="ml-2 text-xs text-(--app-muted-foreground)">{overview.organization_head.position.code}</span>}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-(--app-muted-foreground)">{t('reportingLines.holder')}</dt>
                                        <dd className="text-sm text-(--app-foreground)">{holderText(overview.organization_head.holders)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-(--app-muted-foreground)">{t('reportingLines.reportsTo')}</dt>
                                        <dd className="text-sm text-(--app-foreground)">
                                            {overview.organization_head.reports_to?.position_title_en
                                                ? `${overview.organization_head.reports_to.position_title_en} · ${localizedName(overview.organization_head.reports_to.organization_name_en ?? '', overview.organization_head.reports_to.organization_name_am, locale)}`
                                                : overview.organization_head.reports_to?.outcome_label}
                                            {overview.organization_head.reports_to && <div className="text-xs text-(--app-muted-foreground)">{overview.organization_head.reports_to.explanation}</div>}
                                        </dd>
                                    </div>
                                </dl>
                            ) : (
                                <p className="mt-2 text-sm text-(--app-muted-foreground)">{t('reportingLines.noOrganizationHead')}</p>
                            )}
                        </section>

                        {/* Units */}
                        <section className={tableContainerClassName}>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-3">
                                <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.unitHeads')}</h3>
                                <div className="flex gap-2 text-xs text-(--app-muted-foreground)">
                                    <Link href={route('reporting-lines.acting.index', { organization_id: organizationId })} className="text-primary hover:underline">{t('reportingLines.actingAppointments')} ({overview.acting_active})</Link>
                                    <Link href={route('reporting-lines.delegations.index', { organization_id: organizationId })} className="text-primary hover:underline">{t('reportingLines.delegations')} ({overview.delegations_active})</Link>
                                </div>
                            </div>
                            {overview.units.length === 0 ? (
                                <EmptyState title={t('reportingLines.noUnits')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                                        <thead className={tableHeadClassName}>
                                            <tr>
                                                {[t('reportingLines.unit'), t('reportingLines.headPosition'), t('reportingLines.holder'), t('reportingLines.reportsTo'), t('reportingLines.state')].map((h, i) => (
                                                    <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className={tableBodyClassName}>
                                            {overview.units.map((u) => (
                                                <tr key={u.id} className={tableRowClassName}>
                                                    <td className={cx(tableCellClassName, 'font-medium')} style={{ paddingLeft: `${1 + u.depth * 1.25}rem` }}>
                                                        {localizedName(u.name_en, u.name_am, locale)}
                                                        {u.parent_name_en && <div className="text-xs font-normal text-(--app-muted-foreground)">{t('reportingLines.parentUnit')}: {localizedName(u.parent_name_en, u.parent_name_am, locale)}</div>}
                                                    </td>
                                                    <td className={tableCellClassName}>
                                                        {u.head ? (
                                                            <>
                                                                {can.edit_positions ? <Link href={route('positions.edit', u.head.position_id)} className="text-primary hover:underline">{localizedName(u.head.title_en, u.head.title_am, locale)}</Link> : localizedName(u.head.title_en, u.head.title_am, locale)}
                                                                {u.head.code && <span className="ml-2 text-xs text-(--app-muted-foreground)">{u.head.code}</span>}
                                                            </>
                                                        ) : (
                                                            <span className="text-(--app-muted-foreground)">{t('reportingLines.states.missing')}</span>
                                                        )}
                                                    </td>
                                                    <td className={tableCellClassName}>{u.head ? holderText(u.holders) : '—'}</td>
                                                    <td className={tableCellClassName}>
                                                        {u.head?.reports_to ? (
                                                            <>
                                                                {u.head.reports_to.title_en}
                                                                {u.head.reports_to.basis_label && <div className="text-xs text-(--app-muted-foreground)">{u.head.reports_to.basis_label}</div>}
                                                            </>
                                                        ) : (
                                                            <span className="text-xs text-(--app-muted-foreground)">{u.head?.link_outcome_label ?? '—'}</span>
                                                        )}
                                                    </td>
                                                    <td className={tableCellClassName}><span className={badgeClassName(STATE_TONES[u.state] ?? 'neutral')}>{stateLabel(u.state)}</span></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        {/* Diagnostics */}
                        <section className={tableContainerClassName}>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-3">
                                <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.diagnostics')}</h3>
                                <Select value={severity} onChange={(e) => setSeverity(e.target.value)} className="w-auto">
                                    <option value="">{t('common.allStatuses')}</option>
                                    <option value="error">{t('reportingLines.severityError')}</option>
                                    <option value="warning">{t('reportingLines.severityWarning')}</option>
                                </Select>
                            </div>
                            {findings.length === 0 ? (
                                <EmptyState title={t('reportingLines.noFindings')} />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                                        <thead className={tableHeadClassName}>
                                            <tr>
                                                {[t('reportingLines.severity'), t('reportingLines.finding'), t('reportingLines.unit'), t('reportingLines.position'), t('reportingLines.detail')].map((h, i) => (
                                                    <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className={tableBodyClassName}>
                                            {findings.map((f, i) => (
                                                <tr key={`${f.code}-${i}`} className={tableRowClassName}>
                                                    <td className={tableCellClassName}><span className={badgeClassName(f.severity === 'error' ? 'danger' : 'warning')}>{f.severity === 'error' ? t('reportingLines.severityError') : t('reportingLines.severityWarning')}</span></td>
                                                    <td className={cx(tableCellClassName, 'font-medium')}>{f.title}{f.count !== null && f.count !== undefined ? ` (${f.count})` : ''}{f.extra ? ` · ${f.extra}` : ''}</td>
                                                    <td className={tableCellClassName}>{f.unit_name_en ? localizedName(f.unit_name_en, f.unit_name_am, locale) : '—'}</td>
                                                    <td className={tableCellClassName}>
                                                        {f.position_id && can.edit_positions
                                                            ? <Link href={route('positions.edit', f.position_id)} className="text-primary hover:underline">{localizedName(f.position_title_en ?? '', f.position_title_am, locale)}</Link>
                                                            : (f.position_title_en ? localizedName(f.position_title_en, f.position_title_am, locale) : '—')}
                                                    </td>
                                                    <td className={cx(tableCellClassName, 'text-xs text-(--app-muted-foreground)')}>{f.detail}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        {can.manage && (
                            <div className="flex flex-wrap gap-2">
                                <Link href={route('reporting-lines.acting.index', { organization_id: organizationId })} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('reportingLines.manageActing')}</Link>
                                <Link href={route('reporting-lines.delegations.index', { organization_id: organizationId })} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('reportingLines.manageDelegations')}</Link>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
