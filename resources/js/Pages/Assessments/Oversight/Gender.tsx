import { ExportButtons, GenderTable, OversightLayout, named, pct, type Metrics, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Section, Table, tdCls, thCls } from '@/Components/performance/ui';
import { useChartColors } from '@/hooks/useChartColors';
import { useLocale } from '@/hooks/useLocale';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type Props = ShellProps & {
    totals: Metrics | null;
    institutions: Metrics[];
    filters: Record<string, string>;
    labels: Record<string, { code?: string; name_en?: string; name_am?: string | null }>;
};

/** Gender Analysis from Employee master data; unknown values reported, small groups suppressed when configured. */
export default function OversightGender(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, totals, institutions, labels } = props;
    const colors = useChartColors();
    const cell = (m: Metrics, g: 'male' | 'female' | 'unknown' | 'other') => m.gender?.[g];

    return (
        <OversightLayout title={t('assessmentOversight.nav.gender')} description={t('assessmentOversight.genderHelp')} active="gender" shell={props}
            actions={cycle && can.export ? <ExportButtons report="gender" cycle={cycle} filters={props.filters} /> : undefined}>
            {cycle && cycle.small_group_threshold === null && <p role="note" className="text-xs text-gray-500">{t('assessmentOversight.needsDecision.small_group_threshold')}</p>}
            {totals && (
                <div className="grid gap-4 xl:grid-cols-2">
                    <Section title={t('assessmentOversight.scopeTotal')}><GenderTable gender={totals.gender} /></Section>
                    <Section title={t('assessmentOversight.charts.genderCoverage')}>
                        <div className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={institutions.slice(0, 20).map((m) => ({ name: labels[m.group_key ?? '']?.code ?? '', male: Number(cell(m, 'male')?.coverage_percent ?? 0), female: Number(cell(m, 'female')?.coverage_percent ?? 0) }))}>
                                    <CartesianGrid stroke={colors.grid} vertical={false} />
                                    <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                    <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} />
                                    <Tooltip formatter={(v) => `${v}%`} />
                                    <Legend formatter={(v) => t(`assessmentOversight.genders.${v}`)} />
                                    <Bar dataKey="male" fill={colors.primary} />
                                    <Bar dataKey="female" fill={colors.accent} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </Section>
                </div>
            )}
            <Section title={t('assessmentOversight.byInstitution')} flush>
                <Table head={<>
                    <th className={thCls}>{t('assessmentOversight.institution')}</th>
                    <th className={thCls}>{t('assessmentOversight.eligibleMale')}</th>
                    <th className={thCls}>{t('assessmentOversight.eligibleFemale')}</th>
                    <th className={thCls}>{t('assessmentOversight.maleAssessed')}</th>
                    <th className={thCls}>{t('assessmentOversight.femaleAssessed')}</th>
                    <th className={thCls}>{t('assessmentOversight.maleCoverage')}</th>
                    <th className={thCls}>{t('assessmentOversight.femaleCoverage')}</th>
                    <th className={thCls}>{t('assessmentOversight.unknownEligible')}</th>
                </>}>
                    {institutions.map((m) => {
                        const show = (v: number | null | undefined, suppressed?: boolean) => (suppressed ? t('assessmentOversight.suppressedShort') : (v ?? '—'));
                        const male = cell(m, 'male');
                        const female = cell(m, 'female');
                        const unknown = cell(m, 'unknown');
                        const other = cell(m, 'other');
                        return (
                            <tr key={m.group_key ?? 'x'}>
                                <td className={tdCls}>{named(labels[m.group_key ?? ''], locale)}</td>
                                <td className={`${tdCls} tabular-nums`}>{show(male?.eligible, male?.suppressed)}</td>
                                <td className={`${tdCls} tabular-nums`}>{show(female?.eligible, female?.suppressed)}</td>
                                <td className={`${tdCls} tabular-nums`}>{show(male?.assessed, male?.suppressed)}</td>
                                <td className={`${tdCls} tabular-nums`}>{show(female?.assessed, female?.suppressed)}</td>
                                <td className={`${tdCls} tabular-nums`}>{male?.suppressed ? '—' : pct(male?.coverage_percent ?? null)}</td>
                                <td className={`${tdCls} tabular-nums`}>{female?.suppressed ? '—' : pct(female?.coverage_percent ?? null)}</td>
                                <td className={`${tdCls} tabular-nums`}>{unknown?.suppressed || other?.suppressed ? t('assessmentOversight.suppressedShort') : (unknown?.eligible ?? 0) + (other?.eligible ?? 0)}</td>
                            </tr>
                        );
                    })}
                </Table>
            </Section>
        </OversightLayout>
    );
}
