import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Input, Select, actionLinkClassName } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import type { CompetencyOption, LevelOption } from './RequirementsForm';
import { nameOf, useDesignText } from './ui';

export type PositionCompetencyRow = { competency_id: string; minimum_level_id: string; is_mandatory: boolean };
export type PositionQualificationRow = { education_level_id: string; field_of_study: string; certification: string; license: string; experience_years: number; is_mandatory: boolean };
export type PositionRequirementCatalog = { competencies: CompetencyOption[]; levels: LevelOption[]; educationLevels: LevelOption[] };

const checkbox = 'rounded-sm border-(--app-border-strong) text-primary focus:ring-primary';

/** The written (manual) requirements of a position, replaced as sets; derived rows are untouched. */
export default function PositionRequirementsForm({ positionId, competencies, qualifications, catalog, onClose }: {
    positionId: string; competencies: PositionCompetencyRow[]; qualifications: PositionQualificationRow[]; catalog: PositionRequirementCatalog; onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<{ competencies: PositionCompetencyRow[]; qualifications: PositionQualificationRow[] }>({ competencies, qualifications });
    const errors = form.errors as Record<string, string | undefined>;
    type Key = 'competencies' | 'qualifications';
    const setRow = (key: Key, index: number, patch: Record<string, unknown>) =>
        form.setData(key, (form.data[key] as Record<string, unknown>[]).map((row, i) => (i === index ? { ...row, ...patch } : row)) as never);
    const removeRow = (key: Key, index: number) => form.setData(key, (form.data[key] as unknown[]).filter((_, i) => i !== index) as never);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            competencies: data.competencies,
            qualifications: data.qualifications.map((r) => ({ ...r, field_of_study: r.field_of_study || null, certification: r.certification || null, license: r.license || null, experience_years: Number(r.experience_years || 0) })),
        }));
        form.put(route('organization-design.position-requirements.sync', positionId), { preserveScroll: true, onSuccess: onClose });
    }

    const section = (heading: string, addLabel: string, onAdd: () => void, body: React.ReactNode) => (
        <section className="rounded-card border border-(--app-border) p-3">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{heading}</h3>
                <Button type="button" size="sm" variant="outline" onClick={onAdd}>{addLabel}</Button>
            </div>
            <div className="mt-2 space-y-2">{body}</div>
        </section>
    );

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{tt('positionDesign.editRequirements')}</h2>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('positionDesign.requirementsHelp')}</p>
                <div className="mt-5 space-y-4">
                    {section(tt('positionDesign.competencies'), tt('positionDesign.addCompetency'), () => form.setData('competencies', [...form.data.competencies, { competency_id: '', minimum_level_id: catalog.levels[0]?.id ?? '', is_mandatory: true }]),
                        form.data.competencies.map((row, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                <FormField id={`preq-comp-${i}`} label={tt('fields.competency')} required error={errors[`competencies.${i}.competency_id`]}>
                                    {({ id }) => <Select id={id} value={row.competency_id} onChange={(e) => setRow('competencies', i, { competency_id: e.target.value })}><option value="">—</option>{catalog.competencies.map((c) => <option key={c.id} value={c.id}>{c.framework_code} · {c.code} · {nameOf(c, locale)}</option>)}</Select>}
                                </FormField>
                                <FormField id={`preq-lvl-${i}`} label={tt('fields.minimumLevel')} required error={errors[`competencies.${i}.minimum_level_id`]}>
                                    {({ id }) => <Select id={id} value={row.minimum_level_id} onChange={(e) => setRow('competencies', i, { minimum_level_id: e.target.value })}><option value="">—</option>{catalog.levels.map((l) => <option key={l.id} value={l.id}>{l.rank} · {nameOf(l, locale)}</option>)}</Select>}
                                </FormField>
                                <div className="flex items-end gap-3 pb-2">
                                    <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_mandatory} onChange={(e) => setRow('competencies', i, { is_mandatory: e.target.checked })} />{tt('positionDesign.mandatory')}</label>
                                    <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeRow('competencies', i)}>{tt('functions.remove')}</button>
                                </div>
                            </div>
                        )))}

                    {section(tt('positionDesign.qualifications'), tt('positionDesign.addQualification'), () => form.setData('qualifications', [...form.data.qualifications, { education_level_id: catalog.educationLevels[0]?.id ?? '', field_of_study: '', certification: '', license: '', experience_years: 0, is_mandatory: true }]),
                        form.data.qualifications.map((row, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-3">
                                <FormField id={`preq-edu-${i}`} label={tt('fields.educationLevel')} required error={errors[`qualifications.${i}.education_level_id`]}>
                                    {({ id }) => <Select id={id} value={row.education_level_id} onChange={(e) => setRow('qualifications', i, { education_level_id: e.target.value })}><option value="">—</option>{catalog.educationLevels.map((l) => <option key={l.id} value={l.id}>{nameOf(l, locale)}</option>)}</Select>}
                                </FormField>
                                <FormField id={`preq-fos-${i}`} label={tt('fields.fieldOfStudy')} error={errors[`qualifications.${i}.field_of_study`]}>{({ id }) => <Input id={id} value={row.field_of_study} maxLength={255} onChange={(e) => setRow('qualifications', i, { field_of_study: e.target.value })} />}</FormField>
                                <FormField id={`preq-years-${i}`} label={tt('fields.experienceYears')} error={errors[`qualifications.${i}.experience_years`]}>{({ id }) => <Input id={id} type="number" min={0} max={60} value={String(row.experience_years)} onChange={(e) => setRow('qualifications', i, { experience_years: Number(e.target.value || 0) })} />}</FormField>
                                <FormField id={`preq-cert-${i}`} label={tt('fields.certification')} error={errors[`qualifications.${i}.certification`]}>{({ id }) => <Input id={id} value={row.certification} maxLength={255} onChange={(e) => setRow('qualifications', i, { certification: e.target.value })} />}</FormField>
                                <FormField id={`preq-lic-${i}`} label={tt('fields.license')} error={errors[`qualifications.${i}.license`]}>{({ id }) => <Input id={id} value={row.license} maxLength={255} onChange={(e) => setRow('qualifications', i, { license: e.target.value })} />}</FormField>
                                <div className="flex items-end gap-3 pb-2">
                                    <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_mandatory} onChange={(e) => setRow('qualifications', i, { is_mandatory: e.target.checked })} />{tt('positionDesign.mandatory')}</label>
                                    <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeRow('qualifications', i)}>{tt('functions.remove')}</button>
                                </div>
                            </div>
                        )))}
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
