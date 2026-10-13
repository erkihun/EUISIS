import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler, useMemo, useState } from 'react';

import { badgeClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';
type Org = { id: string; name_en: string; name_am: string | null };
type Pos = {
    id: string;
    title_en: string;
    title_am: string | null;
    grade_level: string | null;
    organization_id: string;
    organization_unit_id: string | null;
    organization_unit_name: string | null;
    code: string | null;
    available_slots: number;
};

type Props = { organizations: Org[]; positions: Pos[]; positionsTruncated?: boolean };

type PositionRow = {
    organization_id: string;
    position_id: string;
    grade_level: string;
    salary_min: string;
    salary_max: string;
    vacancy_count: number;
};

type EligibilityRule = {
    type: 'employment_status' | 'current_grade' | 'current_organization' | 'current_position' | 'minimum_service_months';
    operator: 'equals' | 'in' | 'greater_than_or_equal';
    value: string;
};

const emptyRow = (): PositionRow => ({
    organization_id: '',
    position_id:     '',
    grade_level:     '',
    salary_min:      '',
    salary_max:      '',
    vacancy_count:   1,
});

export default function TransferAnnouncementCreate({ organizations, positions, positionsTruncated = false }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';
    const [positionQuery, setPositionQuery] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        positions:          [emptyRow()] as PositionRow[],
        eligibility_rules:  [] as EligibilityRule[],
        required_documents: [] as string[],
        opening_date:       '',
        closing_date:       '',
    });

    const totalVacancies = data.positions.reduce((sum, r) => sum + (Number(r.vacancy_count) || 0), 0);
    const selectedPositionIds = new Set(data.positions.map((row) => row.position_id).filter(Boolean));
    const positionMatches = useMemo(() => {
        const query = positionQuery.trim().toLocaleLowerCase();
        if (!query) return positions;
        return positions.filter((position) => [position.code, position.title_en, position.title_am, position.organization_unit_name]
            .some((value) => value?.toLocaleLowerCase().includes(query)));
    }, [positionQuery, positions]);

    function updateRow(index: number, patch: Partial<PositionRow>) {
        setData('positions', data.positions.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function handlePositionSelect(index: number, positionId: string) {
        const pos = positions.find((p) => p.id === positionId);
        if (positionId && selectedPositionIds.has(positionId) && data.positions[index].position_id !== positionId) return;
        updateRow(index, {
            position_id: positionId,
            grade_level: pos?.grade_level ?? '',
            // salary stays as manually entered; grade auto-populates
        });
    }

    function handleOrgChange(index: number, orgId: string) {
        updateRow(index, { organization_id: orgId, position_id: '', grade_level: '', vacancy_count: 1 });
    }

    function addRow() {
        setData('positions', [...data.positions, emptyRow()]);
    }

    function removeRow(index: number) {
        setData('positions', data.positions.filter((_, i) => i !== index));
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('transfer-announcements.store'));
    };

    const labelCls = 'block text-sm font-medium text-[color:var(--app-foreground)]';
    const inputCls = cx(inputClassName, 'mt-1 block w-full');
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout contentWidth="form"
            header={
                <PageHeader
                    backHref={route('transfer-announcements.index')}
                    title={t('transfers.createAnnouncement')}
                    description={t('transfers.createAnnouncementHint')}
                    actions={<>
                        <a href={route('transfer-announcements.index')} className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
                            {t('common.cancel')}
                        </a>
                        <button type="submit" form="transfer-announcement-draft" disabled={processing} className={buttonClassName({ variant: 'primary' })}>
                            {processing ? t('common.saving') : t('transfers.saveDraft')}
                        </button>
                    </>}
                />
            }
        >
            <Head title={t('transfers.createAnnouncement')} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                <form id="transfer-announcement-draft" onSubmit={submit} className="space-y-6 rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">

                    <section className="border-b border-[color:var(--app-border)] pb-5">
                        <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('transfers.announcementInformation')}</h2>
                        <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('transfers.draftAnnouncementHint')}</p>
                    </section>

                    {/* ── Positions ── */}
                    <section className="space-y-4" aria-labelledby="vacant-positions-heading">
                        {positionsTruncated && <p role="status" className="text-xs text-amber-700 dark:text-amber-300">{t('transfers.positionsTruncated').replace(':count', String(positions.length))}</p>}
                        <div className="flex items-center justify-between">
                            <h3 id="vacant-positions-heading" className="text-base font-semibold text-[color:var(--app-foreground)]">
                                {t('transfers.includedPositions')}
                            </h3>
                            <button
                                type="button"
                                onClick={addRow}
                                className={buttonClassName({ variant: 'outline' })}
                            >
                                + {t('transfers.addPosition')}
                            </button>
                        </div>

                        <div>
                            <label htmlFor="position-search" className={labelCls}>{t('transfers.searchPositions')}</label>
                            <input id="position-search" value={positionQuery} onChange={(event) => setPositionQuery(event.target.value)} className={inputCls} placeholder={t('transfers.searchPositionsHint')} />
                        </div>

                        {data.positions.map((row, index) => {
                            const filteredPositions = row.organization_id
                                ? positionMatches.filter((p) => p.organization_id === row.organization_id)
                                : positionMatches;


                            return (
                                <div key={index} className="rounded-lg border border-[color:var(--app-border)] p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold text-[color:var(--app-foreground)]">
                                            {t('transfers.positionLine')} {index + 1}
                                        </span>
                                        {data.positions.length > 1 && (
                                            <button
                                                type="button"
                                                onClick={() => removeRow(index)}
                                                className="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400"
                                            >
                                                {t('common.remove')}
                                            </button>
                                        )}
                                    </div>

                                    {/* Row 1: Org + Position */}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className={labelCls}>{t('transfers.organization')}</label>
                                            <select
                                                className={inputCls}
                                                value={row.organization_id}
                                                onChange={(e) => handleOrgChange(index, e.target.value)}
                                                required
                                            >
                                                <option value="">—</option>
                                                {organizations.map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {(useAmharic ? o.name_am : null) ?? o.name_en}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className={labelCls}>{t('transfers.position')}</label>
                                            <select
                                                className={inputCls}
                                                value={row.position_id}
                                                onChange={(e) => handlePositionSelect(index, e.target.value)}
                                                disabled={!row.organization_id}
                                                required
                                            >
                                                <option value="">—</option>
                                                {filteredPositions.map((p) => (
                                                    <option key={p.id} value={p.id} disabled={selectedPositionIds.has(p.id) && row.position_id !== p.id}>
                                                        {p.code ? `${p.code} — ` : ''}
                                                        {(useAmharic ? p.title_am : null) ?? p.title_en}
                                                        {p.organization_unit_name ? ` · ${p.organization_unit_name}` : ''}
                                                        {p.available_slots > 0 ? ` (${p.available_slots} ${t('transfers.availableSlots')})` : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>

                                    {/* Row 2: Grade (auto) + Salary min/max + Vacancy count */}
                                    <div className="mt-3 grid gap-4 sm:grid-cols-4">
                                        <div>
                                            <label className={labelCls}>{t('transfers.gradeLevel')}</label>
                                            <input
                                                type="text"
                                                className={`${inputCls} bg-[color:var(--app-surface-muted)]`}
                                                value={row.grade_level}
                                                readOnly
                                                placeholder="—"
                                            />
                                        </div>
                                        <div>
                                            <label className={labelCls}>{t('transfers.salaryMin')}</label>
                                            <input
                                                type="number"
                                                min={0}
                                                className={inputCls}
                                                value={row.salary_min}
                                                onChange={(e) => updateRow(index, { salary_min: e.target.value })}
                                                placeholder="0.00"
                                            />
                                        </div>
                                        <div>
                                            <label className={labelCls}>{t('transfers.salaryMax')}</label>
                                            <input
                                                type="number"
                                                min={0}
                                                className={inputCls}
                                                value={row.salary_max}
                                                onChange={(e) => updateRow(index, { salary_max: e.target.value })}
                                                placeholder="0.00"
                                            />
                                        </div>
                                        <div>
                                            <label className={labelCls}>{t('transfers.numberOfVacancies')}</label>
                                            <input
                                                type="number"
                                                min={1}
                                                max={positions.find((p) => p.id === row.position_id)?.available_slots ?? undefined}
                                                className={inputCls}
                                                value={row.vacancy_count}
                                                onChange={(e) => updateRow(index, { vacancy_count: Number(e.target.value) })}
                                                required
                                            />
                                        </div>
                                    </div>

                                    {errors[`positions.${index}.organization_id`] && (
                                        <p className={errorCls}>{errors[`positions.${index}.organization_id`]}</p>
                                    )}
                                    {errors[`positions.${index}.position_id`] && (
                                        <p className={errorCls}>{errors[`positions.${index}.position_id`]}</p>
                                    )}
                                    {errors[`positions.${index}.vacancy_count`] && <p className={errorCls}>{errors[`positions.${index}.vacancy_count`]}</p>}
                                </div>
                            );
                        })}

                        {/* Computed total */}
                        <div className="flex items-center justify-end gap-2 rounded-lg bg-[color:var(--app-surface-muted)] px-4 py-2">
                            <span className="text-sm text-[color:var(--app-muted-foreground)]">{t('transfers.totalVacancies')}:</span>
                            <span className="text-sm font-semibold text-[color:var(--app-foreground)]">{totalVacancies}</span>
                        </div>
                    </section>

                    {/* ── Dates ── */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className={labelCls}>{t('transfers.openingDate')}</label>
                            <LocalizedDatePicker
                                className={inputCls}
                                value={data.opening_date}
                                onChange={(v) => setData('opening_date', v)}
                            />
                            {errors.opening_date && <p className={errorCls}>{errors.opening_date}</p>}
                        </div>
                        <div>
                            <label className={labelCls}>{t('transfers.closingDate')}</label>
                            <LocalizedDatePicker
                                className={inputCls}
                                value={data.closing_date}
                                onChange={(v) => setData('closing_date', v)}
                            />
                            {errors.closing_date && <p className={errorCls}>{errors.closing_date}</p>}
                        </div>
                    </div>

                    {/* ── Eligibility Rules ── */}
                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <label className={labelCls}>{t('transfers.eligibilityRules')}</label>
                            <button
                                type="button"
                                onClick={() => setData('eligibility_rules', [...data.eligibility_rules, { type: 'employment_status', operator: 'equals', value: 'active' }])}
                                className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                            >
                                + {t('transfers.addEligibilityRule')}
                            </button>
                        </div>
                        {data.eligibility_rules.map((rule, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                <select aria-label={t('transfers.ruleType')} className={inputCls} value={rule.type} onChange={(e) => { const next = [...data.eligibility_rules]; next[i] = { ...rule, type: e.target.value as EligibilityRule['type'] }; setData('eligibility_rules', next); }}>
                                    <option value="employment_status">{t('transfers.ruleEmploymentStatus')}</option><option value="current_grade">{t('transfers.ruleCurrentGrade')}</option><option value="current_organization">{t('transfers.ruleCurrentOrganization')}</option><option value="current_position">{t('transfers.ruleCurrentPosition')}</option><option value="minimum_service_months">{t('transfers.ruleMinimumService')}</option>
                                </select>
                                <select aria-label={t('transfers.ruleOperator')} className={inputCls} value={rule.operator} onChange={(e) => { const next = [...data.eligibility_rules]; next[i] = { ...rule, operator: e.target.value as EligibilityRule['operator'] }; setData('eligibility_rules', next); }}>
                                    <option value="equals">{t('transfers.ruleEquals')}</option><option value="in">{t('transfers.ruleIn')}</option><option value="greater_than_or_equal">{t('transfers.ruleAtLeast')}</option>
                                </select>
                                <input aria-label={t('transfers.ruleValue')} className={inputCls} value={rule.value} onChange={(e) => { const next = [...data.eligibility_rules]; next[i] = { ...rule, value: e.target.value }; setData('eligibility_rules', next); }} />
                                <button
                                    type="button"
                                    onClick={() => setData('eligibility_rules', data.eligibility_rules.filter((_, j) => j !== i))}
                                    className={buttonClassName({ variant: 'outline' })}
                                >
                                    {t('common.remove')}
                                </button>
                            </div>
                        ))}
                    </section>

                    {/* ── Required Documents ── */}
                    <section className="space-y-2">
                        <div className="flex items-center justify-between">
                            <label className={labelCls}>{t('transfers.requiredDocuments')}</label>
                            <button
                                type="button"
                                onClick={() => setData('required_documents', [...data.required_documents, ''])}
                                className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                            >
                                + {t('transfers.addDocument')}
                            </button>
                        </div>
                        {data.required_documents.map((doc, i) => (
                            <div key={i} className="flex gap-2">
                                <input
                                    type="text"
                                    className={`${inputCls} flex-1`}
                                    value={doc}
                                    onChange={(e) => {
                                        const next = [...data.required_documents];
                                        next[i] = e.target.value;
                                        setData('required_documents', next);
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => setData('required_documents', data.required_documents.filter((_, j) => j !== i))}
                                    className={buttonClassName({ variant: 'outline' })}
                                >
                                    {t('common.remove')}
                                </button>
                            </div>
                        ))}
                    </section>

                    {/* ── Submit ── */}
                    <div className="flex items-center justify-end gap-3 border-t border-[color:var(--app-border)] pt-4">
                        <a
                            href={route('transfer-announcements.index')}
                            className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                        >
                            {t('common.cancel')}
                        </a>
                        <button
                            type="submit"
                            disabled={processing || data.positions.some((r) => !r.organization_id || !r.position_id)}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {processing ? t('common.saving') : t('transfers.saveDraft')}
                        </button>
                    </div>
                </form>
                <aside className="self-start rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 xl:sticky xl:top-6">
                    <div className="flex items-center justify-between border-b border-[color:var(--app-border)] pb-4">
                        <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('transfers.draftSummary')}</h2>
                        <span className={badgeClassName('warning')}>{t('transfers.statusDraft')}</span>
                    </div>
                    <dl className="mt-4 space-y-3 text-sm">
                        <div className="flex justify-between gap-4"><dt className="text-[color:var(--app-muted-foreground)]">{t('transfers.includedPositions')}</dt><dd className="font-semibold text-[color:var(--app-foreground)]">{data.positions.filter((row) => row.position_id).length}</dd></div>
                        <div className="flex justify-between gap-4"><dt className="text-[color:var(--app-muted-foreground)]">{t('transfers.totalVacancies')}</dt><dd className="font-semibold text-[color:var(--app-foreground)]">{totalVacancies}</dd></div>
                        <div className="flex justify-between gap-4"><dt className="text-[color:var(--app-muted-foreground)]">{t('transfers.openingDate')}</dt><dd className="font-medium text-[color:var(--app-foreground)]">{data.opening_date || '—'}</dd></div>
                        <div className="flex justify-between gap-4"><dt className="text-[color:var(--app-muted-foreground)]">{t('transfers.closingDate')}</dt><dd className="font-medium text-[color:var(--app-foreground)]">{data.closing_date || '—'}</dd></div>
                    </dl>
                    <div className="mt-5 border-t border-[color:var(--app-border)] pt-4 text-sm">
                        <p className="font-medium text-[color:var(--app-foreground)]">{t('transfers.validationSummary')}</p>
                        <ul className="mt-2 space-y-1.5 text-[color:var(--app-muted-foreground)]">
                            <li>{data.positions.every((row) => row.organization_id && row.position_id) ? '✓' : '•'} {t('transfers.includedPositions')}</li>
                            <li>{data.opening_date && data.closing_date ? '✓' : '•'} {t('transfers.applicationPeriod')}</li>
                            <li>{data.eligibility_rules.length ? '✓' : '•'} {t('transfers.eligibilityRules')}</li>
                        </ul>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
