import { ExportButtons, Filters, OUTCOMES, OversightLayout, StatusBadge, named, pct, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Table, TablePanel, filterInputCls, inputCls, smallBtn, smallPrimaryBtn, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type Option = { id: string; code?: string; name_en: string; name_am: string | null };
type Row = {
    eligibility_id: string; employee_id: string; employee_number: string; name: string | null; name_en: string | null; gender: string;
    eligibility_status: string; outcome: string; organization: Option; unit: Option; position: { title_en: string | null; title_am: string | null };
    form: { name_en: string; name_am: string | null; version_no: number } | null; expected_form: { name_en: string; name_am: string | null } | null;
    form_resolution: string; record_status: string | null; evaluators: { submitted: number; total: number } | null;
    percentage: string | null; reason: { code: string; name_en: string | null; name_am: string | null; source: string | null } | null;
};

type Props = ShellProps & {
    rows: Paginator<Row> | null;
    filters: Record<string, string | undefined>;
    reasons: Option[];
    units: Option[];
    organizations: Option[];
    canRequestExclusion: boolean;
    exceptionReasons: Option[];
};

/**
 * Assessment Coverage / Unassessed Employees: one row per eligible (or
 * excluded) employee with the derived status. Final results appear only
 * with the results permission; criterion responses never appear here.
 */
export default function OversightEmployees(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, rows, filters } = props;
    const unassessedView = filters.outcome === 'unassessed';
    const [excluding, setExcluding] = useState<Row | null>(null);

    return (
        <OversightLayout title={t(unassessedView ? 'assessmentOversight.nav.unassessed' : 'assessmentOversight.nav.coverage')} description={t(unassessedView ? 'assessmentOversight.unassessedHelp' : 'assessmentOversight.coverageHelp')}
            active={unassessedView ? 'unassessed' : 'coverage'} shell={props}
            actions={cycle && can.export ? <ExportButtons report="unassessed" cycle={cycle} filters={{ ...filters, outcome: filters.outcome ?? 'unassessed' }} /> : undefined}>
            <Filters routeName="assessment-oversight.employees" cycle={cycle}>
                <input name="search" aria-label={t('assessmentOversight.searchEmployee')} placeholder={t('assessmentOversight.searchEmployee')} className={filterInputCls} defaultValue={filters.search ?? ''} />
                {props.organizations.length > 1 && (
                    <select name="organization_id" aria-label={t('assessmentOversight.institution')} className={filterInputCls} defaultValue={filters.organization_id ?? ''}>
                        <option value="">{t('assessmentOversight.allInstitutions')}</option>
                        {props.organizations.map((o) => <option key={o.id} value={o.id}>{named(o, locale)}</option>)}
                    </select>
                )}
                {props.organizations.length <= 1 && filters.organization_id && <input type="hidden" name="organization_id" value={filters.organization_id} />}
                {props.units.length > 0 && (
                    <select name="organization_unit_id" aria-label={t('assessmentOversight.unit')} className={filterInputCls} defaultValue={filters.organization_unit_id ?? ''}>
                        <option value="">{t('assessmentOversight.allUnits')}</option>
                        {props.units.map((u) => <option key={u.id} value={u.id}>{named(u, locale)}</option>)}
                    </select>
                )}
                <select name="outcome" aria-label={t('assessmentOversight.outcome')} className={filterInputCls} defaultValue={filters.outcome ?? ''}>
                    <option value="">{t('assessmentOversight.allOutcomes')}</option>
                    <option value="unassessed">{t('assessmentOversight.unassessed')}</option>
                    {OUTCOMES.map((o) => <option key={o} value={o}>{t(`assessmentOversight.outcomes.${o}`)}</option>)}
                </select>
                <select name="eligibility_status" aria-label={t('assessmentOversight.eligibility')} className={filterInputCls} defaultValue={filters.eligibility_status ?? ''}>
                    <option value="">{t('assessmentOversight.allEligibility')}</option>
                    <option value="eligible">{t('assessmentOversight.eligible')}</option>
                    <option value="excluded">{t('assessmentOversight.excluded')}</option>
                </select>
                {can.demographics && (
                    <select name="gender" aria-label={t('assessmentOversight.gender')} className={filterInputCls} defaultValue={filters.gender ?? ''}>
                        <option value="">{t('assessmentOversight.allGenders')}</option>
                        {['male', 'female', 'other', 'unknown'].map((g) => <option key={g} value={g}>{t(`assessmentOversight.genders.${g}`)}</option>)}
                    </select>
                )}
                <select name="reason_id" aria-label={t('assessmentOversight.reason')} className={filterInputCls} defaultValue={filters.reason_id ?? ''}>
                    <option value="">{t('assessmentOversight.allReasons')}</option>
                    {props.reasons.map((r) => <option key={r.id} value={r.id}>{named(r, locale)}</option>)}
                </select>
            </Filters>

            {excluding && cycle && <ExclusionForm row={excluding} cycleId={cycle.id} reasons={props.exceptionReasons} onDone={() => setExcluding(null)} />}

            {rows && (
                <TablePanel page={rows} empty={t('assessmentOversight.noEmployees')}>
                    <Table head={<>
                        <th className={thCls}>{t('assessmentOversight.employeeNumber')}</th>
                        <th className={thCls}>{t('assessmentOversight.employee')}</th>
                        {can.demographics && <th className={thCls}>{t('assessmentOversight.gender')}</th>}
                        <th className={thCls}>{t('assessmentOversight.position')}</th>
                        <th className={thCls}>{t('assessmentOversight.unit')}</th>
                        <th className={thCls}>{t('assessmentOversight.form')}</th>
                        <th className={thCls}>{t('assessmentOversight.outcome')}</th>
                        <th className={thCls}>{t('assessmentOversight.evaluators')}</th>
                        {can.results && <th className={thCls}>{t('assessmentOversight.finalResult')}</th>}
                        <th className={thCls}>{t('assessmentOversight.reason')}</th>
                        {props.canRequestExclusion && <th className={thCls}><span className="sr-only">{t('assessmentOversight.actionsLabel')}</span></th>}
                    </>}>
                        {rows.data.map((row) => (
                            <tr key={row.employee_id}>
                                <td className={`${tdCls} tabular-nums`}>{row.employee_number}</td>
                                <td className={tdCls}>{(locale === 'am' ? row.name : row.name_en) || row.name || row.name_en}<div className="text-xs text-gray-500">{named(row.organization, locale)}</div></td>
                                {can.demographics && <td className={tdCls}>{t(`assessmentOversight.genders.${row.gender}`)}</td>}
                                <td className={tdCls}>{(locale === 'am' && row.position.title_am) || row.position.title_en || '—'}</td>
                                <td className={tdCls}>{row.unit.name_en ? named(row.unit, locale) : '—'}</td>
                                <td className={tdCls}>
                                    {row.form ? `${named(row.form, locale)} v${row.form.version_no}` : row.expected_form ? <span className="text-gray-500">{named(row.expected_form, locale)}</span> : <span className="text-red-600">{t(`assessmentOversight.resolution.${row.form_resolution}`)}</span>}
                                </td>
                                <td className={tdCls}><StatusBadge group="outcomes" value={row.outcome} /></td>
                                <td className={`${tdCls} tabular-nums`}>{row.evaluators ? `${row.evaluators.submitted}/${row.evaluators.total}` : '—'}</td>
                                {can.results && <td className={`${tdCls} tabular-nums`}>{row.percentage ? pct(row.percentage) : '—'}</td>}
                                <td className={tdCls}>{row.reason ? named(row.reason, locale) : '—'}</td>
                                {props.canRequestExclusion && (
                                    <td className={tdCls}>
                                        {row.eligibility_status === 'eligible' && !['assessed', 'approved_exception'].includes(row.outcome) && (
                                            <button type="button" className={smallBtn} onClick={() => setExcluding(row)}>{t('assessmentOversight.actions.requestExclusion')}</button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </Table>
                </TablePanel>
            )}
        </OversightLayout>
    );
}

function ExclusionForm({ row, cycleId, reasons, onDone }: { row: Row; cycleId: string; reasons: Option[]; onDone: () => void }) {
    const { t, locale } = useLocale();
    const form = useForm({ eligibility_id: row.eligibility_id, reason_id: reasons[0]?.id ?? '', note: '' });
    return (
        <form className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
            onSubmit={(e) => { e.preventDefault(); form.post(route('assessment-oversight.exclusions.store', cycleId), { preserveScroll: true, onSuccess: onDone }); }}>
            <h3 className="text-sm font-semibold">{t('assessmentOversight.actions.requestExclusion')}: {row.employee_number} · {(locale === 'am' ? row.name : row.name_en) || row.name}</h3>
            <p className="mt-1 text-xs text-gray-500">{t('assessmentOversight.exclusionRequestHelp')}</p>
            {reasons.length === 0 ? <p className="mt-2 text-sm text-amber-700">{t('assessmentOversight.noExceptionReasons')}</p> : (
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <select aria-label={t('assessmentOversight.reason')} className={inputCls} value={form.data.reason_id} onChange={(e) => form.setData('reason_id', e.target.value)}>
                        {reasons.map((r) => <option key={r.id} value={r.id}>{named(r, locale)}</option>)}
                    </select>
                    <input aria-label={t('assessmentOversight.justification')} placeholder={t('assessmentOversight.justification')} required className={inputCls} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                </div>
            )}
            {Object.values(form.errors).map((e) => <p key={e} className="mt-1 text-sm text-red-600">{e}</p>)}
            <div className="mt-3 flex gap-2">
                <button className={smallPrimaryBtn} disabled={form.processing || reasons.length === 0}>{t('assessmentOversight.actions.sendRequest')}</button>
                <button type="button" className={smallBtn} onClick={onDone}>{t('assessmentOversight.actions.cancel')}</button>
            </div>
        </form>
    );
}
