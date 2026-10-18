import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Input, Select, actionLinkClassName } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Named = { id: string; code: string; name_en: string; name_am: string | null };
export type OccupationOption = Named & { isco_code: string | null; professions: (Named & { specializations: Named[] })[] };
export type CompetencyOption = Named & { framework_code: string; competency_type_id: string | null };
export type LevelOption = Named & { rank: number };

export type RequirementCatalog = { occupations: OccupationOption[]; competencies: CompetencyOption[]; levels: LevelOption[]; educationLevels: LevelOption[] };

export type OccupationRow = { occupation_id: string; profession_id: string | null; specialization_id: string | null; is_primary: boolean; occupation?: (Named & { isco_code: string | null }) | null; profession?: Named | null; specialization?: Named | null };
export type CompetencyRow = { competency_id: string; minimum_level_id: string; is_mandatory: boolean; competency?: Named | null; minimum_level?: LevelOption | null };
export type QualificationRow = { education_level_id: string; field_of_study: string | null; certification: string | null; license: string | null; experience_years: number; is_mandatory: boolean; education_level?: LevelOption | null };
export type Requirements = { occupations: OccupationRow[]; competencies: CompetencyRow[]; qualifications: QualificationRow[] };

const checkbox = 'rounded-sm border-(--app-border-strong) text-primary focus:ring-primary';

/** The three requirement sets of an activity or task, replaced together. */
export default function RequirementsForm({ subjectType, subjectId, title, requirements, catalog, onClose }: {
    subjectType: 'activity' | 'task'; subjectId: string; title: string; requirements: Requirements; catalog: RequirementCatalog; onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<{ occupations: OccupationRow[]; competencies: CompetencyRow[]; qualifications: QualificationRow[] }>({
        occupations: requirements.occupations.map((r) => ({ occupation_id: r.occupation_id, profession_id: r.profession_id ?? '', specialization_id: r.specialization_id ?? '', is_primary: r.is_primary })),
        competencies: requirements.competencies.map((r) => ({ competency_id: r.competency_id, minimum_level_id: r.minimum_level_id, is_mandatory: r.is_mandatory })),
        qualifications: requirements.qualifications.map((r) => ({ education_level_id: r.education_level_id, field_of_study: r.field_of_study ?? '', certification: r.certification ?? '', license: r.license ?? '', experience_years: r.experience_years, is_mandatory: r.is_mandatory })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const emptyCatalog = catalog.occupations.length === 0 && catalog.competencies.length === 0 && catalog.educationLevels.length === 0;

    type Key = 'occupations' | 'competencies' | 'qualifications';
    const setRow = (key: Key, index: number, patch: Record<string, unknown>) =>
        form.setData(key, (form.data[key] as Record<string, unknown>[]).map((row, i) => (i === index ? { ...row, ...patch } : row)) as never);
    const removeRow = (key: Key, index: number) => form.setData(key, (form.data[key] as unknown[]).filter((_, i) => i !== index) as never);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            occupations: data.occupations.map((r) => ({ ...r, profession_id: r.profession_id || null, specialization_id: r.specialization_id || null })),
            competencies: data.competencies,
            qualifications: data.qualifications.map((r) => ({ ...r, field_of_study: r.field_of_study || null, certification: r.certification || null, license: r.license || null, experience_years: Number(r.experience_years || 0) })),
        }));
        form.put(route('organization-design.requirements.sync', { subjectType, subject: subjectId }), { preserveScroll: true, onSuccess: onClose });
    }

    const section = (heading: string, onAdd: () => void, body: React.ReactNode) => (
        <section className="rounded-card border border-(--app-border) p-3">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-(--app-foreground)">{heading}</h3>
                <Button type="button" size="sm" variant="outline" onClick={onAdd}>{tt('requirements.addRow')}</Button>
            </div>
            <div className="mt-2 space-y-2">{body}</div>
        </section>
    );

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-(--app-foreground)">{tt('requirements.edit')} · {title}</h2>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('requirements.help')}</p>
                {emptyCatalog && <p className="mt-2 text-sm text-(--app-warning,inherit)">{tt('requirements.noCatalog')}</p>}

                <div className="mt-5 space-y-4">
                    {section(tt('requirements.occupations'), () => form.setData('occupations', [...form.data.occupations, { occupation_id: catalog.occupations[0]?.id ?? '', profession_id: '', specialization_id: '', is_primary: form.data.occupations.length === 0 }]),
                        form.data.occupations.map((row, i) => {
                            const occupation = catalog.occupations.find((o) => o.id === row.occupation_id);
                            const profession = occupation?.professions.find((p) => p.id === row.profession_id);
                            return (
                                <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                    <FormField id={`req-occ-${i}`} label={tt('fields.occupation')} required error={errors[`occupations.${i}.occupation_id`]}>
                                        {({ id }) => <Select id={id} value={row.occupation_id} onChange={(e) => setRow('occupations', i, { occupation_id: e.target.value, profession_id: '', specialization_id: '' })}><option value="">—</option>{catalog.occupations.map((o) => <option key={o.id} value={o.id}>{o.isco_code ?? o.code} · {nameOf(o, locale)}</option>)}</Select>}
                                    </FormField>
                                    <FormField id={`req-prof-${i}`} label={tt('fields.profession')} error={errors[`occupations.${i}.profession_id`]}>
                                        {({ id }) => <Select id={id} value={row.profession_id ?? ''} disabled={!occupation || occupation.professions.length === 0} onChange={(e) => setRow('occupations', i, { profession_id: e.target.value, specialization_id: '' })}><option value="">—</option>{(occupation?.professions ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} · {nameOf(p, locale)}</option>)}</Select>}
                                    </FormField>
                                    <FormField id={`req-spec-${i}`} label={tt('fields.specialization')} error={errors[`occupations.${i}.specialization_id`]}>
                                        {({ id }) => <Select id={id} value={row.specialization_id ?? ''} disabled={!profession || profession.specializations.length === 0} onChange={(e) => setRow('occupations', i, { specialization_id: e.target.value })}><option value="">—</option>{(profession?.specializations ?? []).map((s) => <option key={s.id} value={s.id}>{s.code} · {nameOf(s, locale)}</option>)}</Select>}
                                    </FormField>
                                    <div className="flex items-end gap-3 pb-2">
                                        <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_primary} onChange={(e) => setRow('occupations', i, { is_primary: e.target.checked })} />{tt('requirements.primary')}</label>
                                        <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeRow('occupations', i)}>{tt('functions.remove')}</button>
                                    </div>
                                </div>
                            );
                        }))}

                    {section(tt('requirements.competencies'), () => form.setData('competencies', [...form.data.competencies, { competency_id: '', minimum_level_id: catalog.levels[0]?.id ?? '', is_mandatory: true }]),
                        form.data.competencies.map((row, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                <FormField id={`req-comp-${i}`} label={tt('fields.competency')} required error={errors[`competencies.${i}.competency_id`]}>
                                    {({ id }) => <Select id={id} value={row.competency_id} onChange={(e) => setRow('competencies', i, { competency_id: e.target.value })}><option value="">—</option>{catalog.competencies.map((c) => <option key={c.id} value={c.id}>{c.framework_code} · {c.code} · {nameOf(c, locale)}</option>)}</Select>}
                                </FormField>
                                <FormField id={`req-lvl-${i}`} label={tt('fields.minimumLevel')} required error={errors[`competencies.${i}.minimum_level_id`]}>
                                    {({ id }) => <Select id={id} value={row.minimum_level_id} onChange={(e) => setRow('competencies', i, { minimum_level_id: e.target.value })}><option value="">—</option>{catalog.levels.map((l) => <option key={l.id} value={l.id}>{l.rank} · {nameOf(l, locale)}</option>)}</Select>}
                                </FormField>
                                <div className="flex items-end gap-3 pb-2">
                                    <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_mandatory} onChange={(e) => setRow('competencies', i, { is_mandatory: e.target.checked })} />{tt('requirements.mandatory')}</label>
                                    <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeRow('competencies', i)}>{tt('functions.remove')}</button>
                                </div>
                            </div>
                        )))}

                    {section(tt('requirements.qualifications'), () => form.setData('qualifications', [...form.data.qualifications, { education_level_id: catalog.educationLevels[0]?.id ?? '', field_of_study: '', certification: '', license: '', experience_years: 0, is_mandatory: true }]),
                        form.data.qualifications.map((row, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-3">
                                <FormField id={`req-edu-${i}`} label={tt('fields.educationLevel')} required error={errors[`qualifications.${i}.education_level_id`]}>
                                    {({ id }) => <Select id={id} value={row.education_level_id} onChange={(e) => setRow('qualifications', i, { education_level_id: e.target.value })}><option value="">—</option>{catalog.educationLevels.map((l) => <option key={l.id} value={l.id}>{nameOf(l, locale)}</option>)}</Select>}
                                </FormField>
                                <FormField id={`req-fos-${i}`} label={tt('fields.fieldOfStudy')} error={errors[`qualifications.${i}.field_of_study`]}>{({ id }) => <Input id={id} value={row.field_of_study ?? ''} maxLength={255} onChange={(e) => setRow('qualifications', i, { field_of_study: e.target.value })} />}</FormField>
                                <FormField id={`req-years-${i}`} label={tt('fields.experienceYears')} error={errors[`qualifications.${i}.experience_years`]}>{({ id }) => <Input id={id} type="number" min={0} max={60} value={String(row.experience_years)} onChange={(e) => setRow('qualifications', i, { experience_years: Number(e.target.value || 0) })} />}</FormField>
                                <FormField id={`req-cert-${i}`} label={tt('fields.certification')} error={errors[`qualifications.${i}.certification`]}>{({ id }) => <Input id={id} value={row.certification ?? ''} maxLength={255} onChange={(e) => setRow('qualifications', i, { certification: e.target.value })} />}</FormField>
                                <FormField id={`req-lic-${i}`} label={tt('fields.license')} error={errors[`qualifications.${i}.license`]}>{({ id }) => <Input id={id} value={row.license ?? ''} maxLength={255} onChange={(e) => setRow('qualifications', i, { license: e.target.value })} />}</FormField>
                                <div className="flex items-end gap-3 pb-2">
                                    <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_mandatory} onChange={(e) => setRow('qualifications', i, { is_mandatory: e.target.checked })} />{tt('requirements.mandatory')}</label>
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

/** Compact read-only rendering of a subject's requirements for the tree. */
export function RequirementsSummary({ requirements }: { requirements: Requirements }) {
    const { locale, tt } = useDesignText();
    const total = requirements.occupations.length + requirements.competencies.length + requirements.qualifications.length;
    if (total === 0) return <p className="text-xs text-(--app-muted-foreground)">{tt('requirements.none')}</p>;
    const label = (x: Named | null | undefined) => (x ? nameOf(x, locale) : '—');
    return (
        <dl className="grid gap-1 text-xs sm:grid-cols-3">
            <div>
                <dt className="font-medium text-(--app-muted-foreground)">{tt('requirements.occupations')}</dt>
                {requirements.occupations.map((r, i) => <dd key={i}>{r.occupation?.isco_code ? `${r.occupation.isco_code} · ` : ''}{label(r.occupation)}{r.profession ? ` › ${label(r.profession)}` : ''}{r.specialization ? ` › ${label(r.specialization)}` : ''}{r.is_primary ? ` (${tt('requirements.primary')})` : ''}</dd>)}
            </div>
            <div>
                <dt className="font-medium text-(--app-muted-foreground)">{tt('requirements.competencies')}</dt>
                {requirements.competencies.map((r, i) => <dd key={i}>{label(r.competency)} ≥ {r.minimum_level ? `${r.minimum_level.rank} ${nameOf(r.minimum_level, locale)}` : '—'}{r.is_mandatory ? '' : ` (${tt('requirements.optional')})`}</dd>)}
            </div>
            <div>
                <dt className="font-medium text-(--app-muted-foreground)">{tt('requirements.qualifications')}</dt>
                {requirements.qualifications.map((r, i) => <dd key={i}>{label(r.education_level)}{r.field_of_study ? ` · ${r.field_of_study}` : ''}{r.experience_years ? ` · ${tt('requirements.years').replace(':count', String(r.experience_years))}` : ''}{r.certification ? ` · ${r.certification}` : ''}</dd>)}
            </div>
        </dl>
    );
}
