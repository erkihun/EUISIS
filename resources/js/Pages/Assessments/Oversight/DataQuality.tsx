import { ExportButtons, OversightLayout, SeverityBadge, named, oversightHref, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Section, Table, TablePanel, linkBtn, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Link } from '@inertiajs/react';

type Issue = {
    code: string; severity: string; is_blocking: boolean; organization: { id: string; name_en: string | null; name_am: string | null };
    employee: { id: string; number: string; name: string | null; name_en: string | null } | null; record_id: string | null; details: string | number | null; resolution_hint: string;
};

type Props = ShellProps & {
    summary: null | {
        rules: Record<string, { severity: string; count: number }>;
        organizations: Record<string, { blocking: number; warning: number; info: number; total: number }>;
        cycle: Array<{ code: string; severity: string }>;
    };
    issues: Paginator<Issue> | null;
    filters: { code?: string; organization_id?: string; severity?: string };
    labels: Record<string, { code?: string; name_en?: string; name_am?: string | null }>;
};

const ORDER = { blocking: 0, warning: 1, info: 2 } as Record<string, number>;

/** Data Quality: normalized issues with severity; only blocking ones stop an institution submission. */
export default function OversightDataQuality(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, summary, issues, filters, labels } = props;
    const rules = summary ? Object.entries(summary.rules).filter(([, r]) => !filters.severity || r.severity === filters.severity).sort(([, a], [, b]) => ORDER[a.severity] - ORDER[b.severity] || b.count - a.count) : [];

    return (
        <OversightLayout title={t('assessmentOversight.nav.dataQuality')} description={t('assessmentOversight.dataQualityHelp')} active="dataQuality" shell={props}
            actions={cycle && can.export ? <ExportButtons report="data_quality" cycle={cycle} filters={{ organization_id: filters.organization_id }} /> : undefined}>
            {filters.organization_id && <p className="text-sm">{t('assessmentOversight.institution')}: <b>{named(labels[filters.organization_id], locale)}</b> · <Link className={linkBtn} href={oversightHref('assessment-oversight.data-quality', cycle)}>{t('assessmentOversight.clearFilters')}</Link></p>}
            {summary && (
                <>
                    {summary.cycle.length > 0 && (
                        <Section title={t('assessmentOversight.cycleIssues')}>
                            <ul className="space-y-1 text-sm">{summary.cycle.map((i) => <li key={i.code} className="flex items-center gap-2"><SeverityBadge severity={i.severity} />{t(`assessmentOversight.rules.${i.code}`)} — <span className="text-gray-500">{t(`assessmentOversight.hints.${i.code}`)}</span></li>)}</ul>
                        </Section>
                    )}
                    <Section title={t('assessmentOversight.rulesTitle')} flush>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.issue')}</th>
                            <th className={thCls}>{t('assessmentOversight.severityLabel')}</th>
                            <th className={thCls}>{t('assessmentOversight.count')}</th>
                            <th className={thCls}>{t('assessmentOversight.howToResolve')}</th>
                        </>}>
                            {rules.map(([code, rule]) => (
                                <tr key={code} className={filters.code === code ? 'bg-gray-50 dark:bg-slate-800/50' : undefined}>
                                    <td className={tdCls}>
                                        {rule.count > 0
                                            ? <Link className="font-medium text-[color:var(--color-primary)] hover:underline" href={oversightHref('assessment-oversight.data-quality', cycle, { code, organization_id: filters.organization_id })}>{t(`assessmentOversight.rules.${code}`)}</Link>
                                            : <span className="text-gray-500">{t(`assessmentOversight.rules.${code}`)}</span>}
                                        <div className="text-xs text-gray-400">{code}</div>
                                    </td>
                                    <td className={tdCls}><SeverityBadge severity={rule.severity} /></td>
                                    <td className={`${tdCls} tabular-nums`}>{rule.count}</td>
                                    <td className={`${tdCls} text-xs text-gray-600 dark:text-slate-400`}>{t(`assessmentOversight.hints.${code}`)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Section>

                    {issues && filters.code && (
                        <>
                            <h2 className="text-sm font-semibold">{t(`assessmentOversight.rules.${filters.code}`)}</h2>
                            <TablePanel page={issues} empty={t('assessmentOversight.noIssues')}>
                                <Table head={<>
                                    <th className={thCls}>{t('assessmentOversight.institution')}</th>
                                    <th className={thCls}>{t('assessmentOversight.employee')}</th>
                                    <th className={thCls}>{t('assessmentOversight.details')}</th>
                                </>}>
                                    {issues.data.map((i, n) => (
                                        <tr key={`${i.employee?.id ?? 'x'}-${i.record_id ?? n}`}>
                                            <td className={tdCls}>{named(i.organization, locale)}</td>
                                            <td className={tdCls}>{i.employee ? `${i.employee.number} · ${(locale === 'am' ? i.employee.name : i.employee.name_en) || i.employee.name}` : '—'}</td>
                                            <td className={`${tdCls} text-xs`}>{i.details ?? '—'}</td>
                                        </tr>
                                    ))}
                                </Table>
                            </TablePanel>
                        </>
                    )}

                    {!filters.organization_id && Object.keys(summary.organizations).length > 0 && (
                        <Section title={t('assessmentOversight.byInstitution')} flush>
                            <Table head={<>
                                <th className={thCls}>{t('assessmentOversight.institution')}</th>
                                <th className={thCls}>{t('assessmentOversight.severity.blocking')}</th>
                                <th className={thCls}>{t('assessmentOversight.severity.warning')}</th>
                                <th className={thCls}>{t('assessmentOversight.severity.info')}</th>
                            </>}>
                                {Object.entries(summary.organizations).sort(([, a], [, b]) => b.blocking - a.blocking).map(([id, c]) => (
                                    <tr key={id}>
                                        <td className={tdCls}><Link className="hover:underline" href={oversightHref('assessment-oversight.data-quality', cycle, { organization_id: id })}>{named(labels[id], locale)}</Link></td>
                                        <td className={`${tdCls} tabular-nums ${c.blocking > 0 ? 'font-semibold text-red-600' : ''}`}>{c.blocking}</td>
                                        <td className={`${tdCls} tabular-nums`}>{c.warning}</td>
                                        <td className={`${tdCls} tabular-nums`}>{c.info}</td>
                                    </tr>
                                ))}
                            </Table>
                        </Section>
                    )}
                </>
            )}
        </OversightLayout>
    );
}
