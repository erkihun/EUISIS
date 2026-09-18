import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler } from 'react';

type Org = { id: string; name_en: string; name_am: string | null };
type Pos = {
    id: string;
    title_en: string;
    title_am: string | null;
    grade_level: string | null;
    organization_id: string;
    available_slots: number;
};

type Props = { organizations: Org[]; positions: Pos[] };

type PositionRow = {
    organization_id: string;
    position_id: string;
    grade_level: string;
    salary_min: string;
    salary_max: string;
    vacancy_count: number;
};

const emptyRow = (): PositionRow => ({
    organization_id: '',
    position_id:     '',
    grade_level:     '',
    salary_min:      '',
    salary_max:      '',
    vacancy_count:   1,
});

export default function TransferAnnouncementCreate({ organizations, positions }: Props) {
    const { locale, t } = useLocale();
    const useAmharic = locale === 'am';

    const { data, setData, post, processing, errors } = useForm({
        positions:          [emptyRow()] as PositionRow[],
        eligibility_rules:  [] as string[],
        required_documents: [] as string[],
        opening_date:       '',
        closing_date:       '',
    });

    const totalVacancies = data.positions.reduce((sum, r) => sum + (Number(r.vacancy_count) || 0), 0);

    function updateRow(index: number, patch: Partial<PositionRow>) {
        setData('positions', data.positions.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function handlePositionSelect(index: number, positionId: string) {
        const pos = positions.find((p) => p.id === positionId);
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

    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300';
    const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-50';
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('transfer-announcements.index')}
                    title={t('transfers.createAnnouncement')}
                    description={t('transfers.multiInstitutionHint')}
                />
            }
        >
            <Head title={t('transfers.createAnnouncement')} />

            <div className="mx-auto max-w-5xl">
                <form onSubmit={submit} className="space-y-6 rounded-card border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">

                    {/* ── Positions ── */}
                    <section className="space-y-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                                {t('transfers.includedPositions')}
                            </h3>
                            <button
                                type="button"
                                onClick={addRow}
                                className="inline-flex items-center justify-center rounded-lg border border-blue-300 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-950"
                            >
                                + {t('transfers.addPosition')}
                            </button>
                        </div>

                        {data.positions.map((row, index) => {
                            const filteredPositions = row.organization_id
                                ? positions.filter((p) => p.organization_id === row.organization_id)
                                : positions;


                            return (
                                <div key={index} className="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold text-gray-800 dark:text-slate-200">
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
                                                    <option key={p.id} value={p.id}>
                                                        {(useAmharic ? p.title_am : null) ?? p.title_en}
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
                                                className={`${inputCls} bg-gray-50 dark:bg-slate-900`}
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
                                </div>
                            );
                        })}

                        {/* Computed total */}
                        <div className="flex items-center justify-end gap-2 rounded-lg bg-gray-50 px-4 py-2 dark:bg-slate-800">
                            <span className="text-sm text-gray-500 dark:text-slate-400">{t('transfers.totalVacancies')}:</span>
                            <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">{totalVacancies}</span>
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
                    <section className="space-y-2">
                        <div className="flex items-center justify-between">
                            <label className={labelCls}>{t('transfers.eligibilityRules')}</label>
                            <button
                                type="button"
                                onClick={() => setData('eligibility_rules', [...data.eligibility_rules, ''])}
                                className="text-xs text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                            >
                                + {t('transfers.addEligibilityRule')}
                            </button>
                        </div>
                        {data.eligibility_rules.map((rule, i) => (
                            <div key={i} className="flex gap-2">
                                <input
                                    type="text"
                                    className={`${inputCls} flex-1`}
                                    value={rule}
                                    onChange={(e) => {
                                        const next = [...data.eligibility_rules];
                                        next[i] = e.target.value;
                                        setData('eligibility_rules', next);
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => setData('eligibility_rules', data.eligibility_rules.filter((_, j) => j !== i))}
                                    className="rounded-lg border border-red-200 px-3 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400"
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
                                    className="rounded-lg border border-red-200 px-3 text-sm text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400"
                                >
                                    {t('common.remove')}
                                </button>
                            </div>
                        ))}
                    </section>

                    {/* ── Submit ── */}
                    <div className="flex items-center justify-end gap-3 border-t border-gray-100 pt-4 dark:border-slate-800">
                        <a
                            href={route('transfer-announcements.index')}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </a>
                        <button
                            type="submit"
                            disabled={processing || data.positions.some((r) => !r.organization_id || !r.position_id)}
                            className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                        >
                            {processing ? t('common.saving') : t('common.save')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
