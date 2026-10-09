import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler, useMemo } from 'react';

type Org = { id: string; name_en: string };
type Unit = { id: string; name_en: string; organization_id: string };
type Pos = { id: string; title_en: string };

type Establishment = {
    id: string;
    organization_id: string;
    organization_unit_id: string | null;
    position_id: string;
    approved_slots: number;
    position: Pos | null;
};

type VacancyPositionForm = {
    organization_id: string;
    organization_unit_id: string;
    position_establishment_id: string;
    vacancy_slots: number;
};

type Props = {
    organizations: Org[];
    units: Unit[];
    establishments: Establishment[];
    selectedEstablishmentId: string | null;
};

const emptyPosition = (): VacancyPositionForm => ({
    organization_id: '',
    organization_unit_id: '',
    position_establishment_id: '',
    vacancy_slots: 1,
});

const datePart = (value: string): string => value ? value.slice(0, 10) : '';
const timePart = (value: string): string => value.length >= 16 ? value.slice(11, 16) : '08:30';
const combineDateTime = (date: string, time: string): string => date ? `${date}T${time || '08:30'}` : '';

export default function VacanciesCreate({ organizations, units, establishments, selectedEstablishmentId }: Props) {
    const { t } = useLocale();

    const selectedEstablishment = selectedEstablishmentId
        ? establishments.find((establishment) => establishment.id === selectedEstablishmentId)
        : null;

    const { data, setData, post, processing, errors } = useForm({
        title_en: '',
        title_am: '',
        description_en: '',
        description_am: '',
        application_opens_at: '',
        application_closes_at: '',
        eligibility_rules: [] as string[],
        positions: [
            selectedEstablishment
                ? {
                    organization_id: selectedEstablishment.organization_id,
                    organization_unit_id: selectedEstablishment.organization_unit_id ?? '',
                    position_establishment_id: selectedEstablishment.id,
                    vacancy_slots: 1,
                }
                : emptyPosition(),
        ],
    });

    const selectedEstablishmentIds = useMemo(
        () => data.positions.map((position) => position.position_establishment_id).filter(Boolean),
        [data.positions],
    );

    const totalSlots = useMemo(
        () => data.positions.reduce((sum, position) => sum + Number(position.vacancy_slots || 0), 0),
        [data.positions],
    );

    const updatePosition = (index: number, next: Partial<VacancyPositionForm>) => {
        setData('positions', data.positions.map((position, positionIndex) => (
            positionIndex === index ? { ...position, ...next } : position
        )));
    };

    const addPosition = () => {
        setData('positions', [...data.positions, emptyPosition()]);
    };

    const removePosition = (index: number) => {
        setData('positions', data.positions.filter((_, positionIndex) => positionIndex !== index));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('vacancy-announcements.store'));
    };

    const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300';
    const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-50';
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('vacancy-announcements.index')}
                    title={t('vacancies.createAnnouncement')}
                    description={t('vacancies.multiOrganizationHint')}
                />
            }
        >
            <Head title={t('vacancies.createAnnouncement')} />

            <div className="mx-auto max-w-5xl">
                <form onSubmit={submit} className="space-y-6 rounded-card border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label className={labelCls}>{t('vacancies.titleEn')}</label>
                            <input type="text" className={inputCls} value={data.title_en} onChange={(event) => setData('title_en', event.target.value)} required />
                            {errors.title_en && <p className={errorCls}>{errors.title_en}</p>}
                        </div>

                        <div>
                            <label className={labelCls}>{t('vacancies.titleAm')}</label>
                            <input type="text" className={inputCls} value={data.title_am} onChange={(event) => setData('title_am', event.target.value)} />
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label className={labelCls}>{t('vacancies.descriptionEn')}</label>
                            <textarea rows={3} className={inputCls} value={data.description_en} onChange={(event) => setData('description_en', event.target.value)} />
                        </div>

                        <div>
                            <label className={labelCls}>{t('vacancies.descriptionAm')}</label>
                            <textarea rows={3} className={inputCls} value={data.description_am} onChange={(event) => setData('description_am', event.target.value)} />
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label className={labelCls}>{t('vacancies.applicationOpensAt')}</label>
                            <div className="mt-1 grid gap-2 sm:grid-cols-[1fr_auto]">
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={datePart(data.application_opens_at)}
                                    onChange={(date) => setData('application_opens_at', combineDateTime(date, timePart(data.application_opens_at)))}
                                />
                                <LocalizedTimePicker
                                    className={inputCls}
                                    value={timePart(data.application_opens_at)}
                                    onChange={(time) => setData('application_opens_at', combineDateTime(datePart(data.application_opens_at), time))}
                                />
                            </div>
                        </div>
                        <div>
                            <label className={labelCls}>{t('vacancies.applicationClosesAt')}</label>
                            <div className="mt-1 grid gap-2 sm:grid-cols-[1fr_auto]">
                                <LocalizedDatePicker
                                    className={inputCls}
                                    value={datePart(data.application_closes_at)}
                                    onChange={(date) => setData('application_closes_at', combineDateTime(date, timePart(data.application_closes_at)))}
                                />
                                <LocalizedTimePicker
                                    className={inputCls}
                                    value={timePart(data.application_closes_at)}
                                    onChange={(time) => setData('application_closes_at', combineDateTime(datePart(data.application_closes_at), time))}
                                />
                            </div>
                        </div>
                    </div>

                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.eligibilityRules')}</h3>
                            <button
                                type="button"
                                onClick={() => setData('eligibility_rules', [...data.eligibility_rules, ''])}
                                className="inline-flex items-center justify-center rounded-lg border border-blue-300 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-950"
                            >
                                {t('vacancies.addRule')}
                            </button>
                        </div>
                        {data.eligibility_rules.length === 0 && (
                            <p className="text-sm text-gray-400 dark:text-slate-500">—</p>
                        )}
                        {data.eligibility_rules.map((rule, index) => (
                            <div key={index} className="flex gap-2">
                                <input
                                    type="text"
                                    className={`${inputCls} flex-1`}
                                    value={rule}
                                    onChange={(event) => {
                                        const next = [...data.eligibility_rules];
                                        next[index] = event.target.value;
                                        setData('eligibility_rules', next);
                                    }}
                                />
                                <button
                                    type="button"
                                    onClick={() => setData('eligibility_rules', data.eligibility_rules.filter((_, i) => i !== index))}
                                    className="rounded-lg border border-red-200 px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
                                >
                                    {t('common.remove')}
                                </button>
                            </div>
                        ))}
                    </section>

                    <section className="space-y-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('vacancies.includedPositions')}</h3>
                                <p className="text-sm text-gray-500 dark:text-slate-400">{t('vacancies.totalVacancySlots')}: {totalSlots}</p>
                            </div>
                            <button type="button" onClick={addPosition} className="inline-flex items-center justify-center rounded-lg border border-blue-300 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-950">
                                {t('vacancies.addPosition')}
                            </button>
                        </div>

                        {data.positions.map((position, index) => {
                            const filteredUnits = units.filter((unit) => unit.organization_id === position.organization_id);
                            const filteredEstablishments = establishments.filter((establishment) => (
                                establishment.organization_id === position.organization_id
                                && (position.organization_unit_id === '' || establishment.organization_unit_id === position.organization_unit_id)
                                && (
                                    establishment.id === position.position_establishment_id
                                    || ! selectedEstablishmentIds.includes(establishment.id)
                                )
                            ));

                            return (
                                <div key={index} className="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold text-gray-800 dark:text-slate-200">{t('vacancies.positionLine')} {index + 1}</span>
                                        {data.positions.length > 1 && (
                                            <button type="button" onClick={() => removePosition(index)} className="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400">
                                                {t('common.remove')}
                                            </button>
                                        )}
                                    </div>

                                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_140px]">
                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.organization')}</label>
                                            <select
                                                className={inputCls}
                                                value={position.organization_id}
                                                onChange={(event) => updatePosition(index, {
                                                    organization_id: event.target.value,
                                                    organization_unit_id: '',
                                                    position_establishment_id: '',
                                                })}
                                                required
                                            >
                                                <option value="">-</option>
                                                {organizations.map((organization) => (
                                                    <option key={organization.id} value={organization.id}>{organization.name_en}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.organizationUnit')}</label>
                                            <select
                                                className={inputCls}
                                                value={position.organization_unit_id}
                                                onChange={(event) => updatePosition(index, {
                                                    organization_unit_id: event.target.value,
                                                    position_establishment_id: '',
                                                })}
                                                disabled={! position.organization_id}
                                            >
                                                <option value="">-</option>
                                                {filteredUnits.map((unit) => (
                                                    <option key={unit.id} value={unit.id}>{unit.name_en}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.position')}</label>
                                            <select
                                                className={inputCls}
                                                value={position.position_establishment_id}
                                                onChange={(event) => updatePosition(index, { position_establishment_id: event.target.value })}
                                                disabled={! position.organization_id}
                                                required
                                            >
                                                <option value="">-</option>
                                                {filteredEstablishments.map((establishment) => (
                                                    <option key={establishment.id} value={establishment.id}>
                                                        {establishment.position?.title_en ?? establishment.id}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className={labelCls}>{t('vacancies.vacancySlots')}</label>
                                            <input
                                                type="number"
                                                min={1}
                                                className={inputCls}
                                                value={position.vacancy_slots}
                                                onChange={(event) => updatePosition(index, { vacancy_slots: Number(event.target.value) })}
                                                required
                                            />
                                        </div>
                                    </div>

                                    {errors[`positions.${index}.position_establishment_id`] && (
                                        <p className={errorCls}>{errors[`positions.${index}.position_establishment_id`]}</p>
                                    )}
                                    {errors[`positions.${index}.vacancy_slots`] && (
                                        <p className={errorCls}>{errors[`positions.${index}.vacancy_slots`]}</p>
                                    )}
                                </div>
                            );
                        })}
                        {errors.positions && <p className={errorCls}>{errors.positions}</p>}
                    </section>

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={processing || data.positions.some((position) => ! position.position_establishment_id)}
                            className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                        >
                            {t('common.save')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
