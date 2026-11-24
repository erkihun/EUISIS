import ConfirmationDialog from '@/Components/ConfirmationDialog';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { OrganizationPicker } from '@/modules/reporting/components/Pickers';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { OrganizationRef, TemplateSection } from '@/modules/reporting/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Step {
    name_en: string;
    name_am: string;
    permission: string;
}

interface Requirement {
    key: string;
    label_en: string;
    label_am: string;
}

interface Props {
    type: null | {
        id: string; code: string; name_en: string; name_am: string; instructions_en: string | null; instructions_am: string | null; frequency: string;
        receiving_organization_id: string | null; submission_mode: string; confidentiality: string; allow_nil_report: boolean; allow_self_approval: boolean; is_active: boolean;
    };
    receiving: OrganizationRef | null;
    template: null | { version: number; status: 'draft' | 'published'; sections: TemplateSection[]; steps: Step[]; required_attachments: Requirement[] };
    published: { version: number; published_at: string | null }[];
    defaults: { sections: TemplateSection[]; steps: Step[] };
}

const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'ad_hoc'];

function slug(text: string, taken: string[]): string {
    const base = text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[^a-z]+/, '') || 'section';
    let key = base;
    for (let i = 2; taken.includes(key); i++) key = `${base}_${i}`;

    return key;
}

/**
 * A report type and its template. The type's own fields save at once; the
 * template is edited as a working copy and takes effect for NEW reporting
 * periods only when it is published as a new version. Periods already
 * published keep the version they were pinned to.
 */
export default function TypeForm({ type, receiving, template, published, defaults }: Props): JSX.Element {
    const { rt, moment } = useReporting();
    const [organization, setOrganization] = useState<OrganizationRef | null>(receiving);
    const [publishing, setPublishing] = useState(false);
    const form = useForm({
        code: type?.code ?? '', name_en: type?.name_en ?? '', name_am: type?.name_am ?? '', instructions_en: type?.instructions_en ?? '', instructions_am: type?.instructions_am ?? '',
        frequency: type?.frequency ?? 'monthly', receiving_organization_id: type?.receiving_organization_id ?? null as string | null,
        submission_mode: type?.submission_mode ?? 'either', confidentiality: type?.confidentiality ?? 'internal',
        allow_nil_report: type?.allow_nil_report ?? false, allow_self_approval: type?.allow_self_approval ?? false, is_active: type?.is_active ?? true,
    });
    const tpl = useForm({
        sections: (template?.sections ?? defaults.sections).map((s) => ({ ...s, guidance_en: s.guidance_en ?? '', guidance_am: s.guidance_am ?? '' })),
        steps: template?.steps ?? defaults.steps,
        required_attachments: template?.required_attachments ?? [] as Requirement[],
    });
    const error = (errors: Record<string, string>, key: string): JSX.Element | null => (errors[key] ? <p className={errorCls} role="alert">{errors[key]}</p> : null);

    const saveType = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, receiving_organization_id: organization?.id ?? null, instructions_en: data.instructions_en || null, instructions_am: data.instructions_am || null }));
        if (type) form.put(route('reporting.types.update', type.id), { preserveScroll: true });
        else form.post(route('reporting.types.store'));
    };
    const saveTemplate = (event: FormEvent) => {
        event.preventDefault();
        if (!type) return;
        // Empty guidance is sent as an empty string; the server stores it as null.
        tpl.put(route('reporting.types.template.save', type.id), { preserveScroll: true });
    };
    const publish = () => type && router.post(route('reporting.types.template.publish', type.id), {}, { preserveScroll: true, onFinish: () => setPublishing(false) });

    const setSection = (index: number, patch: Partial<(typeof tpl.data.sections)[number]>) => tpl.setData('sections', tpl.data.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    const move = (index: number, by: number) => {
        const next = [...tpl.data.sections];
        const target = index + by;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        tpl.setData('sections', next);
    };
    const field = (id: string, key: keyof typeof form.data, text: string, props: Record<string, unknown> = {}) => (
        <div>
            <label htmlFor={id} className={labelCls}>{text}</label>
            <input id={id} type="text" className={inputCls} value={String(form.data[key] ?? '')} onChange={(e) => form.setData(key, e.target.value as never)} {...props} />
            {error(form.errors as Record<string, string>, key)}
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={type ? rt('types.edit') : rt('types.add')} description={type ? type.name_en : rt('types.addDescription')} backHref={route('reporting.types.index')} />}>
            <Head title={type ? rt('types.edit') : rt('types.add')} />
            <ActionErrors except={Object.keys(form.errors)} />
            <div className="space-y-5 pb-4">
                <form onSubmit={saveType} className={cx(panelCls, 'space-y-4 p-4')} aria-labelledby="tf-type">
                    <h2 id="tf-type" className={sectionTitleCls}>{rt('types.sectionType')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {field('tf-name-am', 'name_am', rt('types.nameAm'), { required: true, maxLength: 200, lang: 'am' })}
                        {field('tf-name-en', 'name_en', rt('types.nameEn'), { required: true, maxLength: 200, lang: 'en' })}
                        {field('tf-code', 'code', rt('types.code'), { required: true, maxLength: 40, pattern: '[A-Za-z0-9][A-Za-z0-9_\\-]*', 'aria-describedby': 'tf-code-help' })}
                        <div>
                            <label htmlFor="tf-frequency" className={labelCls}>{rt('types.frequency')}</label>
                            <select id="tf-frequency" className={inputCls} value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                                {FREQUENCIES.map((value) => <option key={value} value={value}>{rt(`frequency.${value}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="tf-mode" className={labelCls}>{rt('types.submissionMode')}</label>
                            <select id="tf-mode" className={inputCls} value={form.data.submission_mode} onChange={(e) => form.setData('submission_mode', e.target.value)}>
                                {['either', 'structured', 'upload'].map((value) => <option key={value} value={value}>{rt(`types.mode.${value}`)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="tf-conf" className={labelCls}>{rt('types.confidentiality')}</label>
                            <select id="tf-conf" className={inputCls} value={form.data.confidentiality} onChange={(e) => form.setData('confidentiality', e.target.value)} aria-describedby="tf-conf-help">
                                <option value="internal">{rt('types.internal')}</option>
                                <option value="confidential">{rt('types.confidential')}</option>
                            </select>
                            <p id="tf-conf-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('types.confidentialityHelp')}</p>
                        </div>
                    </div>
                    <p id="tf-code-help" className={cx(mutedCls, 'text-xs')}>{rt('types.codeHelp')}</p>
                    <div className="max-w-xl">
                        <OrganizationPicker label={rt('types.receiving')} value={organization} onChange={setOrganization} help={rt('types.receivingHelp')} error={form.errors.receiving_organization_id} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label htmlFor="tf-ins-am" className={labelCls}>{rt('types.instructionsAm')}</label>
                            <textarea id="tf-ins-am" lang="am" className={textareaCls} rows={3} maxLength={5000} value={form.data.instructions_am} onChange={(e) => form.setData('instructions_am', e.target.value)} />
                        </div>
                        <div>
                            <label htmlFor="tf-ins-en" className={labelCls}>{rt('types.instructionsEn')}</label>
                            <textarea id="tf-ins-en" lang="en" className={textareaCls} rows={3} maxLength={5000} value={form.data.instructions_en} onChange={(e) => form.setData('instructions_en', e.target.value)} />
                        </div>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className={labelCls}>{rt('types.options')}</legend>
                        {(['allow_nil_report', 'allow_self_approval', 'is_active'] as const).map((key) => (
                            <label key={key} className="flex items-start gap-3 text-sm">
                                <input type="checkbox" className="mt-1" checked={form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
                                <span>{rt(`types.option.${key}`)}<span className={cx(mutedCls, 'block text-xs')}>{rt(`types.option.${key}Help`)}</span></span>
                            </label>
                        ))}
                    </fieldset>
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{rt(type ? 'common.save' : 'types.createAndContinue')}</button>
                </form>

                {!type ? <Notice>{rt('types.templateAfterSave')}</Notice> : (
                    <form onSubmit={saveTemplate} className={cx(panelCls, 'space-y-5 p-4')} aria-labelledby="tf-template">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="tf-template" className={sectionTitleCls}>{rt('types.sectionTemplate')}</h2>
                            <div className="flex flex-wrap items-center gap-2">
                                {template && <span className={badge(template.status === 'draft' ? 'warning' : 'success')}>{rt(template.status === 'draft' ? 'types.workingCopy' : 'types.publishedVersion', { n: template.version })}</span>}
                                {published.map((version) => <span key={version.version} className={badge('neutral')} title={moment(version.published_at)}>{rt('common.version', { n: version.version })}</span>)}
                            </div>
                        </div>
                        <Notice>{rt('types.templateNote')}</Notice>
                        <ActionErrors except={[]} />

                        <fieldset>
                            <legend className="text-sm font-semibold">{rt('types.sections')}</legend>
                            <ol className="mt-2 space-y-3">
                                {tpl.data.sections.map((section, index) => (
                                    <li key={section.key} className="rounded-control border border-(--app-border) p-3">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label htmlFor={`ts-am-${section.key}`} className={labelCls}>{index + 1}. {rt('types.sectionTitleAm')}</label>
                                                <input id={`ts-am-${section.key}`} lang="am" type="text" className={inputCls} required maxLength={200} value={section.title_am} onChange={(e) => setSection(index, { title_am: e.target.value })} />
                                            </div>
                                            <div>
                                                <label htmlFor={`ts-en-${section.key}`} className={labelCls}>{rt('types.sectionTitleEn')}</label>
                                                <input id={`ts-en-${section.key}`} lang="en" type="text" className={inputCls} required maxLength={200} value={section.title_en} onChange={(e) => setSection(index, { title_en: e.target.value })} />
                                            </div>
                                            <div>
                                                <label htmlFor={`ts-gam-${section.key}`} className={labelCls}>{rt('types.guidanceAm')}</label>
                                                <input id={`ts-gam-${section.key}`} lang="am" type="text" className={inputCls} maxLength={2000} value={section.guidance_am ?? ''} onChange={(e) => setSection(index, { guidance_am: e.target.value })} />
                                            </div>
                                            <div>
                                                <label htmlFor={`ts-gen-${section.key}`} className={labelCls}>{rt('types.guidanceEn')}</label>
                                                <input id={`ts-gen-${section.key}`} lang="en" type="text" className={inputCls} maxLength={2000} value={section.guidance_en ?? ''} onChange={(e) => setSection(index, { guidance_en: e.target.value })} />
                                            </div>
                                        </div>
                                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={section.is_required} onChange={(e) => setSection(index, { is_required: e.target.checked })} />{rt('types.sectionRequired')}</label>
                                            <span className="flex gap-1.5">
                                                <button type="button" className={secondaryBtn} disabled={index === 0} onClick={() => move(index, -1)} aria-label={rt('types.moveUp', { n: index + 1 })}>↑</button>
                                                <button type="button" className={secondaryBtn} disabled={index === tpl.data.sections.length - 1} onClick={() => move(index, 1)} aria-label={rt('types.moveDown', { n: index + 1 })}>↓</button>
                                                <button type="button" className={secondaryBtn} disabled={tpl.data.sections.length === 1} onClick={() => tpl.setData('sections', tpl.data.sections.filter((_, i) => i !== index))}>{rt('common.remove')}</button>
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                            <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => tpl.setData('sections', [...tpl.data.sections, { key: slug(`section ${tpl.data.sections.length + 1}`, tpl.data.sections.map((s) => s.key)), title_en: '', title_am: '', guidance_en: '', guidance_am: '', is_required: true }])}>{rt('types.addSection')}</button>
                            {error(tpl.errors as Record<string, string>, 'sections')}
                        </fieldset>

                        <fieldset>
                            <legend className="text-sm font-semibold">{rt('types.steps')}</legend>
                            <p className={cx(mutedCls, 'text-xs')}>{rt('types.stepsHelp')}</p>
                            <ol className="mt-2 space-y-2">
                                {tpl.data.steps.map((step, index) => (
                                    <li key={index} className="grid gap-2 rounded-control border border-(--app-border) p-3 sm:grid-cols-[1fr_1fr_12rem_auto]">
                                        <div>
                                            <label htmlFor={`st-am-${index}`} className={labelCls}>{index + 1}. {rt('types.stepNameAm')}</label>
                                            <input id={`st-am-${index}`} lang="am" type="text" className={inputCls} required maxLength={200} value={step.name_am} onChange={(e) => tpl.setData('steps', tpl.data.steps.map((s, i) => (i === index ? { ...s, name_am: e.target.value } : s)))} />
                                        </div>
                                        <div>
                                            <label htmlFor={`st-en-${index}`} className={labelCls}>{rt('types.stepNameEn')}</label>
                                            <input id={`st-en-${index}`} lang="en" type="text" className={inputCls} required maxLength={200} value={step.name_en} onChange={(e) => tpl.setData('steps', tpl.data.steps.map((s, i) => (i === index ? { ...s, name_en: e.target.value } : s)))} />
                                        </div>
                                        <div>
                                            <label htmlFor={`st-p-${index}`} className={labelCls}>{rt('types.stepPermission')}</label>
                                            <select id={`st-p-${index}`} className={inputCls} value={step.permission} onChange={(e) => tpl.setData('steps', tpl.data.steps.map((s, i) => (i === index ? { ...s, permission: e.target.value } : s)))}>
                                                <option value="reporting.review">{rt('types.permissionReview')}</option>
                                                <option value="reporting.approve">{rt('types.permissionApprove')}</option>
                                            </select>
                                        </div>
                                        <div className="flex items-end">
                                            <button type="button" className={secondaryBtn} disabled={tpl.data.steps.length === 1} onClick={() => tpl.setData('steps', tpl.data.steps.filter((_, i) => i !== index))}>{rt('common.remove')}</button>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                            <button type="button" className={cx(secondaryBtn, 'mt-2')} disabled={tpl.data.steps.length >= 6} onClick={() => tpl.setData('steps', [...tpl.data.steps, { name_en: '', name_am: '', permission: 'reporting.approve' }])}>{rt('types.addStep')}</button>
                        </fieldset>

                        <fieldset>
                            <legend className="text-sm font-semibold">{rt('types.requiredAttachments')}</legend>
                            <p className={cx(mutedCls, 'text-xs')}>{rt('types.requiredAttachmentsHelp')}</p>
                            <ul className="mt-2 space-y-2">
                                {tpl.data.required_attachments.map((requirement, index) => (
                                    <li key={requirement.key} className="grid gap-2 rounded-control border border-(--app-border) p-3 sm:grid-cols-[1fr_1fr_auto]">
                                        <div>
                                            <label htmlFor={`ra-am-${requirement.key}`} className={labelCls}>{rt('types.attachmentAm')}</label>
                                            <input id={`ra-am-${requirement.key}`} lang="am" type="text" className={inputCls} required maxLength={200} value={requirement.label_am} onChange={(e) => tpl.setData('required_attachments', tpl.data.required_attachments.map((r, i) => (i === index ? { ...r, label_am: e.target.value } : r)))} />
                                        </div>
                                        <div>
                                            <label htmlFor={`ra-en-${requirement.key}`} className={labelCls}>{rt('types.attachmentEn')}</label>
                                            <input id={`ra-en-${requirement.key}`} lang="en" type="text" className={inputCls} required maxLength={200} value={requirement.label_en} onChange={(e) => tpl.setData('required_attachments', tpl.data.required_attachments.map((r, i) => (i === index ? { ...r, label_en: e.target.value } : r)))} />
                                        </div>
                                        <div className="flex items-end">
                                            <button type="button" className={secondaryBtn} onClick={() => tpl.setData('required_attachments', tpl.data.required_attachments.filter((_, i) => i !== index))}>{rt('common.remove')}</button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => tpl.setData('required_attachments', [...tpl.data.required_attachments, { key: slug(`attachment ${tpl.data.required_attachments.length + 1}`, tpl.data.required_attachments.map((r) => r.key)), label_en: '', label_am: '' }])}>{rt('types.addAttachment')}</button>
                        </fieldset>

                        <div className="flex flex-wrap gap-2 border-t border-(--app-border) pt-4">
                            <button type="submit" className={secondaryBtn} disabled={tpl.processing}>{rt('types.saveWorkingCopy')}</button>
                            <button type="button" className={primaryBtn} disabled={tpl.isDirty || template?.status !== 'draft'} title={tpl.isDirty ? rt('consolidation.saveFirst') : undefined} onClick={() => setPublishing(true)}>{rt('types.publish')}</button>
                            <a href={route('reporting.types.template.document', type.id)} className={secondaryBtn}>{rt('types.downloadTemplate')}</a>
                        </div>
                    </form>
                )}
            </div>

            <ConfirmationDialog open={publishing} onClose={() => setPublishing(false)} onConfirm={publish} title={rt('types.publishTitle')} description={rt('types.publishDescription')} confirmLabel={rt('types.publish')} />
        </AuthenticatedLayout>
    );
}
