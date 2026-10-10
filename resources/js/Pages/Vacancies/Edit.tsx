import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { FormEventHandler, useMemo } from 'react';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
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

type AnnouncementPosition = {
    id: string;
    position_establishment_id: string;
    organization_id: string;
    organization_unit_id: string | null;
    vacancy_slots: number;
};

type Announcement = {
    id: string;
    announcement_number: string;
    title_en: string;
    title_am: string | null;
    description_en: string | null;
    description_am: string | null;
    application_opens_at: string | null;
    application_closes_at: string | null;
    eligibility_rules: string[] | null;
    positions: AnnouncementPosition[];
};

type VacancyPositionForm = {
    organization_id: string;
    organization_unit_id: string;
    position_establishment_id: string;
    vacancy_slots: number;
};

type Props = {
    announcement: Announcement;
    organizations: Org[];
    units: Unit[];
    establishments: Establishment[];
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

export default function VacanciesEdit({ announcement, organizations, units, establishments }: Props) {
    const { t } = useLocale();

    const { data, setData, patch, processing, errors } = useForm({
        title_en: announcement.title_en,
        title_am: announcement.title_am ?? '',
        description_en: announcement.description_en ?? '',
        description_am: announcement.description_am ?? '',
        application_opens_at: announcement.application_opens_at ?? '',
        application_closes_at: announcement.application_closes_at ?? '',
        eligibility_rules: announcement.eligibility_rules ?? ([] as string[]),
        positions: announcement.positions.length > 0
            ? announcement.positions.map((position) => ({
                organization_id: position.organization_id,
                organization_unit_id: position.organization_unit_id ?? '',
                position_establishment_id: position.position_establishment_id,
                vacancy_slots: position.vacancy_slots,
            }))
            : [emptyPosition()],
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
        patch(route('vacancy-announcements.update', announcement.id));
    };

    const labelCls = 'block text-sm font-medium text-[color:var(--app-foreground)]';
    const inputCls = cx(inputClassName, 'mt-1 block w-full');
    const errorCls = 'mt-1 text-xs text-red-500';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('vacancy-announcements.show', announcement.id)}
                    title={t('vacancies.editAnnouncement')}
                    description={announcement.announcement_number}
                />
            }
        >
            <Head title={t('vacancies.editAnnouncement')} />

            <div className="mx-auto max-w-5xl">
                <form onSubmit={submit} className="space-y-6 rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
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
                            <h3 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('vacancies.eligibilityRules')}</h3>
                            <button
                                type="button"
                                onClick={() => setData('eligibility_rules', [...data.eligibility_rules, ''])}
                                className={buttonClassName({ variant: 'outline', size: 'sm' })}
                            >
                                {t('vacancies.addRule')}
                            </button>
                        </div>
                        {data.eligibility_rules.length === 0 && (
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">—</p>
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
                                    className={buttonClassName({ variant: 'outline' })}
                                >
                                    {t('common.remove')}
                                </button>
                            </div>
                        ))}
                    </section>

                    <section className="space-y-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-[color:var(--app-foreground)]">{t('vacancies.includedPositions')}</h3>
                                <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('vacancies.totalVacancySlots')}: {totalSlots}</p>
                            </div>
                            <button type="button" onClick={addPosition} className={buttonClassName({ variant: 'outline' })}>
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
                                <div key={index} className="rounded-lg border border-[color:var(--app-border)] p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('vacancies.positionLine')} {index + 1}</span>
                                        {data.positions.length > 1 && (
                                            <button type="button" onClick={() => removePosition(index)} className="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400">
                                                {t('common.remove')}
                                            </button>
                                        )}
                                    </div>

                                    <div className="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_140px]">
                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.organization')}</label>
                                            <select className={inputCls} value={position.organization_id} onChange={(event) => updatePosition(index, { organization_id: event.target.value, organization_unit_id: '', position_establishment_id: '' })} required>
                                                <option value="">-</option>
                                                {organizations.map((organization) => (
                                                    <option key={organization.id} value={organization.id}>{organization.name_en}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.organizationUnit')}</label>
                                            <select className={inputCls} value={position.organization_unit_id} onChange={(event) => updatePosition(index, { organization_unit_id: event.target.value, position_establishment_id: '' })} disabled={! position.organization_id}>
                                                <option value="">-</option>
                                                {filteredUnits.map((unit) => (
                                                    <option key={unit.id} value={unit.id}>{unit.name_en}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label className={labelCls}>{t('positionEstablishments.position')}</label>
                                            <select className={inputCls} value={position.position_establishment_id} onChange={(event) => updatePosition(index, { position_establishment_id: event.target.value })} disabled={! position.organization_id} required>
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
                                            <input type="number" min={1} className={inputCls} value={position.vacancy_slots} onChange={(event) => updatePosition(index, { vacancy_slots: Number(event.target.value) })} required />
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
                        <button type="submit" disabled={processing || data.positions.some((position) => ! position.position_establishment_id)} className={buttonClassName({ variant: 'primary' })}>
                            {t('common.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
