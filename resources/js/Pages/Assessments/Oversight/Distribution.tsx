import { ExportButtons, Filters, OversightLayout, named, pct, type Distribution, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Section, Table, filterInputCls, tdCls, thCls } from '@/Components/performance/ui';
import { useChartColors } from '@/hooks/useChartColors';
import { useLocale } from '@/hooks/useLocale';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type Props = ShellProps & {
    data: Distribution | null;
    filters: { organization_id?: string; breakdown?: string | null };
    labels: Record<string, { name_en?: string; name_am?: string | null; code?: string; version_no?: number }>;
};

/** Result Distribution over the cycle's pinned band policy. Counts only; never an employee league table. */
export default function OversightDistribution(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, data, filters, labels } = props;
    const colors = useChartColors();
    const bandLabel = (b: { label_en: string; label_am: string | null }) => (locale === 'am' && b.label_am) || b.label_en;
    const groupLabel = (key: string) => filters.breakdown === 'gender' ? t(`assessmentOversight.genders.${key || 'unknown'}`) : (key ? named(labels[key] ?? { name_en: key }, locale) : t('assessmentOversight.noUnit'));

    return (
        <OversightLayout title={t('assessmentOversight.nav.distribution')} description={t('assessmentOversight.distributionHelp')} active="distribution" shell={props}
            actions={cycle && can.export ? <ExportButtons report="distribution" cycle={cycle} filters={filters} /> : undefined}>
            <Filters routeName="assessment-oversight.distribution" cycle={cycle} keep={{ organization_id: filters.organization_id }}>
                <select name="breakdown" aria-label={t('assessmentOversight.breakdown')} className={filterInputCls} defaultValue={filters.breakdown ?? ''}>
                    <option value="">{t('assessmentOversight.noBreakdown')}</option>
                    <option value="organization_id">{t('assessmentOversight.institution')}</option>
                    <option value="organization_unit_id">{t('assessmentOversight.unit')}</option>
                    {can.demographics && <option value="gender">{t('assessmentOversight.gender')}</option>}
                    <option value="form_version_id">{t('assessmentOversight.form')}</option>
                </select>
            </Filters>

            {data && !data.policy && <Section title={t('assessmentOversight.nav.distribution')}><p className="text-sm text-amber-700">{t('assessmentOversight.noBandPolicy')}</p></Section>}
            {data && data.policy && (
                <>
                    <div className="grid gap-4 xl:grid-cols-2">
                        <Section title={`${named(data.policy, locale)} v${data.policy.version_no}`} description={t('assessmentOversight.distributionBase').replace(':assessed', String(data.assessed))} flush>
                            <Table head={<>
                                <th className={thCls}>{t('assessmentOversight.band')}</th>
                                <th className={thCls}>{t('assessmentOversight.range')}</th>
                                <th className={thCls}>{t('assessmentOversight.employees')}</th>
                                <th className={thCls}>{t('assessmentOversight.percentOfAssessed')}</th>
                            </>}>
                                {data.bands.map((b) => (
                                    <tr key={b.code}>
                                        <td className={tdCls}>{bandLabel(b)}</td>
                                        <td className={`${tdCls} tabular-nums text-xs`}>{b.min_inclusive ? '[' : '('}{Number(b.min_score)} – {Number(b.max_score)}{b.max_inclusive ? ']' : ')'}</td>
                                        <td className={`${tdCls} tabular-nums`}>{b.count}</td>
                                        <td className={`${tdCls} tabular-nums`}>{pct(b.percent)}</td>
                                    </tr>
                                ))}
                                {data.unclassified > 0 && <tr><td className={`${tdCls} text-amber-700`} colSpan={2}>{t('assessmentOversight.unclassified')}</td><td className={tdCls}>{data.unclassified}</td><td className={tdCls} /></tr>}
                            </Table>
                        </Section>
                        <Section title={t('assessmentOversight.charts.resultDistribution')}>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={data.bands.map((b) => ({ name: bandLabel(b), count: b.count }))}>
                                        <CartesianGrid stroke={colors.grid} vertical={false} />
                                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                        <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                                        <Tooltip />
                                        <Bar dataKey="count" fill={colors.primary} radius={[3, 3, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </Section>
                    </div>

                    {filters.breakdown && Object.keys(data.breakdown).length > 0 && (
                        <Section title={t('assessmentOversight.breakdownBy').replace(':dimension', t(filters.breakdown === 'organization_id' ? 'assessmentOversight.institution' : filters.breakdown === 'organization_unit_id' ? 'assessmentOversight.unit' : filters.breakdown === 'gender' ? 'assessmentOversight.gender' : 'assessmentOversight.form'))} flush>
                            <Table head={<>
                                <th className={thCls}>{t('assessmentOversight.group')}</th>
                                {data.bands.map((b) => <th key={b.code} className={thCls}>{bandLabel(b)}</th>)}
                                <th className={thCls}>{t('assessmentOversight.total')}</th>
                            </>}>
                                {Object.entries(data.breakdown).map(([key, counts]) => (
                                    <tr key={key}>
                                        <td className={tdCls}>{groupLabel(key)}</td>
                                        {data.bands.map((b) => <td key={b.code} className={`${tdCls} tabular-nums`}>{counts[b.code] ?? 0}</td>)}
                                        <td className={`${tdCls} tabular-nums font-medium`}>{Object.values(counts).reduce((a, n) => a + n, 0)}</td>
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
