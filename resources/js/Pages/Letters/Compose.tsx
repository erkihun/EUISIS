import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useLetters } from '@/Components/letters/helpers';
import SearchableSelect from '@/Components/ui/SearchableSelect';
import { Alert, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FileClassOption, LayoutOption, LetterFull, OrganizationOption, PersonOption, Placeholder, TemplateOption, UnitOption } from '@/types/letters';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    mode: 'create' | 'edit';
    letter: LetterFull | null;
    direction: 'outgoing' | 'internal';
    organizations: OrganizationOption[];
    selected_organization_id: string;
    units: UnitOption[];
    people: PersonOption[];
    templates: TemplateOption[];
    all_organizations: OrganizationOption[];
    priorities: string[];
    built_in_placeholders: string[];
    file_classes: FileClassOption[];
    layouts: Record<string, LayoutOption[]>;
}

/** Composing an outgoing or internal letter, from a template or blank, and editing a draft. Numbers are drawn at dispatch. */
export default function Compose({ mode, letter, direction: initialDirection, organizations, selected_organization_id, units, people, templates, all_organizations, priorities, built_in_placeholders, file_classes, layouts }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const editing = mode === 'edit' && letter !== null;
    const [toSystem, setToSystem] = useState(editing ? letter.recipient_organization !== null || letter.external_party_name === null : true);
    const [templateId, setTemplateId] = useState('');
    const [placeholders, setPlaceholders] = useState<Placeholder[]>(editing ? letter.template?.placeholders ?? [] : []);
    const [showBuiltIn, setShowBuiltIn] = useState(false);

    const form = useForm({
        direction: editing ? letter.direction : initialDirection,
        organization_id: editing ? letter.organization.id : selected_organization_id,
        unit_id: editing ? letter.unit?.id ?? '' : '',
        recipient_organization_id: editing ? letter.recipient_organization?.id ?? '' : '',
        external_party_name: editing ? letter.external_party_name ?? '' : '',
        external_party_address: editing ? letter.external_party_address ?? '' : '',
        external_party_email: editing ? letter.external_party_email ?? '' : '',
        file_class_id: editing ? letter.file_class?.id ?? '' : '',
        letterhead_id: editing ? letter.letterhead?.id ?? '' : '',
        subject: editing ? letter.raw_subject ?? '' : '',
        summary: editing ? letter.summary ?? '' : '',
        body: editing ? letter.raw_body ?? '' : '',
        language: (editing ? letter.language : (locale === 'am' ? 'am' : 'en')) as string,
        priority: (editing ? letter.priority : 'normal') as string,
        classification: (editing ? letter.classification : 'internal') as string,
        file_code: editing ? letter.file_code ?? '' : '',
        copies: (editing ? letter.copies.map((c) => ({ organization_id: c.organization_id ?? '', external_name: c.external_name ?? '', external_address: c.external_address ?? '' })) : []) as { organization_id: string; external_name: string; external_address: string }[],
        letter_date: editing ? letter.letter_date ?? '' : new Date().toISOString().slice(0, 10),
        template_version_id: '',
        template_context: editing ? { ...letter.template_context } : ({} as Record<string, string>),
        signatory_user_id: editing ? (letter.signatory?.id ? String(letter.signatory.id) : '') : '',
    });
    const internal = form.data.direction === 'internal';

    function changeOrganization(id: string) {
        form.setData('organization_id', id);
        router.get(route('letters.compose'), { organization_id: id, direction: form.data.direction }, { preserveState: true, preserveScroll: true, only: ['units', 'people', 'templates', 'selected_organization_id'] });
    }

    function pickTemplate(versionId: string) {
        setTemplateId(versionId);
        const template = templates.find((t) => t.version_id === versionId);
        if (!template) {
            form.setData({ ...form.data, template_version_id: '', template_context: {} });
            setPlaceholders([]);
            return;
        }
        const context: Record<string, string> = {};
        for (const p of template.placeholders) context[p.key] = '';
        form.setData({ ...form.data, template_version_id: template.version_id, subject: template.subject, body: template.body, language: template.language, template_context: context });
        setPlaceholders(template.placeholders);
    }

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            unit_id: d.unit_id || null,
            recipient_organization_id: !internal && toSystem ? d.recipient_organization_id || null : null,
            external_party_name: !internal && !toSystem ? d.external_party_name || null : null,
            external_party_address: !internal && !toSystem ? d.external_party_address || null : null,
            external_party_email: !internal && !toSystem ? d.external_party_email || null : null,
            file_class_id: d.file_class_id || null,
            letterhead_id: d.letterhead_id || null,
            template_version_id: d.template_version_id || null,
            signatory_user_id: d.signatory_user_id ? Number(d.signatory_user_id) : null,
            template_context: Object.keys(d.template_context).length ? d.template_context : null,
            file_code: d.file_code || null,
            copies: internal ? [] : d.copies.filter((c) => c.organization_id || c.external_name.trim()).map((c) => ({ organization_id: c.organization_id || null, external_name: c.organization_id ? null : c.external_name, external_address: c.organization_id ? null : c.external_address || null })),
        }));
        if (editing) {
            form.put(route('letters.update', letter.id));
        } else {
            form.post(route('letters.compose.store'));
        }
    }

    const recipientOptions = all_organizations.filter((o) => o.id !== form.data.organization_id).map((o) => ({ value: o.id, label: pick(o), keywords: `${o.code} ${o.name_en} ${o.name_am ?? ''}` }));
    const setCopy = (index: number, patch: Partial<{ organization_id: string; external_name: string; external_address: string }>) =>
        form.setData('copies', form.data.copies.map((c, i) => (i === index ? { ...c, ...patch } : c)));
    const peopleOptions = people.map((p) => ({ value: String(p.id), label: p.name }));
    const title = editing ? lt('compose.editTitle') : internal ? lt('compose.titleInternal') : lt('compose.title');

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={lt('compose.description')} backHref={editing ? route('letters.show', letter.id) : route('letters.index')} />}>
            <Head title={title} />
            <form onSubmit={save} className="space-y-5" aria-label={title}>
                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('common.sender')}</h2>
                    {!editing && (
                        <fieldset className="flex flex-wrap gap-4 sm:col-span-2">
                            <legend className="sr-only">{lt('common.direction')}</legend>
                            <label className="flex items-center gap-2 text-sm"><input type="radio" name="direction" checked={!internal} onChange={() => form.setData('direction', 'outgoing')} /> {lt('direction.outgoing')}</label>
                            <label className="flex items-center gap-2 text-sm"><input type="radio" name="direction" checked={internal} onChange={() => form.setData('direction', 'internal')} /> {lt('direction.internal')}</label>
                        </fieldset>
                    )}
                    <div>
                        <label htmlFor="lc-org" className={labelCls}>{lt('common.institution')}</label>
                        <select id="lc-org" className={inputCls} value={form.data.organization_id} onChange={(e) => changeOrganization(e.target.value)} required disabled={editing || organizations.length === 1}>
                            {organizations.length !== 1 && <option value="">—</option>}
                            {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                        {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="lc-unit" className={labelCls}>{internal ? lt('compose.toUnit') : lt('common.unit')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <select id="lc-unit" className={inputCls} value={form.data.unit_id} onChange={(e) => form.setData('unit_id', e.target.value)} disabled={units.length === 0}>
                            <option value="">—</option>
                            {units.map((u) => <option key={u.id} value={u.id}>{pick(u)}</option>)}
                        </select>
                        {internal && <p className={cx(mutedCls, 'mt-1')}>{lt('compose.internalHelp')}</p>}
                    </div>
                </section>

                {!internal && (
                    <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                        <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('common.recipient')}</h2>
                        <fieldset className="flex flex-wrap gap-4 sm:col-span-2">
                            <legend className="sr-only">{lt('common.recipient')}</legend>
                            <label className="flex items-center gap-2 text-sm"><input type="radio" name="recipient_kind" checked={toSystem} onChange={() => setToSystem(true)} /> {lt('compose.toInSystem')}</label>
                            <label className="flex items-center gap-2 text-sm"><input type="radio" name="recipient_kind" checked={!toSystem} onChange={() => setToSystem(false)} /> {lt('compose.toOutside')}</label>
                        </fieldset>
                        {toSystem ? (
                            <div className="sm:col-span-2">
                                <label htmlFor="lc-recipient" className={labelCls}>{lt('compose.recipientInstitution')}</label>
                                <SearchableSelect id="lc-recipient" value={form.data.recipient_organization_id} options={recipientOptions} onChange={(v) => form.setData('recipient_organization_id', v)} placeholder={lt('common.choose')} />
                                {form.errors.recipient_organization_id && <p className={errorCls}>{form.errors.recipient_organization_id}</p>}
                            </div>
                        ) : (
                            <>
                                <div>
                                    <label htmlFor="lc-ext-name" className={labelCls}>{lt('common.externalParty')}</label>
                                    <input id="lc-ext-name" className={inputCls} value={form.data.external_party_name} onChange={(e) => form.setData('external_party_name', e.target.value)} maxLength={255} />
                                    {form.errors.external_party_name && <p className={errorCls}>{form.errors.external_party_name}</p>}
                                </div>
                                <div>
                                    <label htmlFor="lc-ext-addr" className={labelCls}>{lt('common.externalAddress')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                                    <input id="lc-ext-addr" className={inputCls} value={form.data.external_party_address} onChange={(e) => form.setData('external_party_address', e.target.value)} maxLength={500} />
                                </div>
                                <div>
                                    <label htmlFor="lc-ext-email" className={labelCls}>{lt('show.externalEmail')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                                    <input id="lc-ext-email" type="email" className={inputCls} value={form.data.external_party_email} onChange={(e) => form.setData('external_party_email', e.target.value)} maxLength={160} />
                                    <p className={cx(mutedCls, 'mt-1')}>{lt('show.dispatchEmailHelp')}</p>
                                    {form.errors.external_party_email && <p className={errorCls}>{form.errors.external_party_email}</p>}
                                </div>
                            </>
                        )}

                        <div className="sm:col-span-2 border-t border-(--app-border) pt-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold">{lt('compose.copies')}</h3>
                                <button type="button" className={secondaryBtn} onClick={() => form.setData('copies', [...form.data.copies, { organization_id: '', external_name: '', external_address: '' }])}>{lt('compose.addCopy')}</button>
                            </div>
                            <p className={cx(mutedCls, 'mt-1')}>{lt('compose.copiesHelp')}</p>
                            {form.data.copies.map((copy, i) => (
                                <div key={i} className="mt-2 grid gap-2 rounded-control border border-(--app-border) p-3 sm:grid-cols-3">
                                    <div>
                                        <label htmlFor={`lc-cc-org-${i}`} className={labelCls}>{lt('compose.copyInstitution')}</label>
                                        <SearchableSelect id={`lc-cc-org-${i}`} value={copy.organization_id} options={recipientOptions} onChange={(v) => setCopy(i, { organization_id: v, external_name: '', external_address: '' })} placeholder={lt('compose.copyExternalOr')} />
                                    </div>
                                    <div>
                                        <label htmlFor={`lc-cc-name-${i}`} className={labelCls}>{lt('common.externalParty')}</label>
                                        <input id={`lc-cc-name-${i}`} className={inputCls} value={copy.external_name} disabled={copy.organization_id !== ''} onChange={(e) => setCopy(i, { external_name: e.target.value })} maxLength={255} />
                                    </div>
                                    <div className="flex items-end gap-2">
                                        <div className="grow">
                                            <label htmlFor={`lc-cc-addr-${i}`} className={labelCls}>{lt('common.externalAddress')}</label>
                                            <input id={`lc-cc-addr-${i}`} className={inputCls} value={copy.external_address} disabled={copy.organization_id !== ''} onChange={(e) => setCopy(i, { external_address: e.target.value })} maxLength={500} />
                                        </div>
                                        <button type="button" className={secondaryBtn} onClick={() => form.setData('copies', form.data.copies.filter((_, j) => j !== i))}>{lt('common.remove')}</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('compose.content')}</h2>
                    {!editing && (
                        <div className="sm:col-span-2">
                            <label htmlFor="lc-template" className={labelCls}>{lt('common.template')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                            <select id="lc-template" className={inputCls} value={templateId} onChange={(e) => pickTemplate(e.target.value)} disabled={templates.length === 0 || !form.data.organization_id}>
                                <option value="">{lt('compose.blank')}</option>
                                {templates.map((t) => <option key={t.version_id} value={t.version_id}>{t.code} · {locale === 'am' && t.title_am ? t.title_am : t.title_en}{t.platform ? ` (${lt('common.platform')})` : ''}</option>)}
                            </select>
                            <p className={cx(mutedCls, 'mt-1')}>{templates.length === 0 && form.data.organization_id ? lt('compose.noTemplates') : lt('compose.templateHelp')}</p>
                        </div>
                    )}
                    {editing && letter.template && (
                        <Alert tone="info" className="sm:col-span-2">{lt('show.templateUsed', { code: letter.template.code ?? '', version: letter.template.version_no })}</Alert>
                    )}
                    <div className="sm:col-span-2">
                        <label htmlFor="lc-subject" className={labelCls}>{lt('common.subject')}</label>
                        <input id="lc-subject" className={inputCls} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} required maxLength={500} />
                        {form.errors.subject && <p className={errorCls}>{form.errors.subject}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <RichTextEditor id="lc-body" label={lt('compose.body')} value={form.data.body} onChange={(html) => form.setData('body', html)} error={form.errors.body} locale={form.data.language as 'am' | 'en'} minHeight={320} maxLength={50000} />
                        <button type="button" className="mt-1 text-xs underline" onClick={() => setShowBuiltIn((v) => !v)}>{lt('compose.builtIn')}</button>
                        {showBuiltIn && (
                            <p className={cx(mutedCls, 'mt-1 break-words')}>{lt('compose.placeholderHelp')} {built_in_placeholders.map((k) => `{{ ${k} }}`).join('  ')}</p>
                        )}
                    </div>
                    {placeholders.length > 0 && (
                        <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                            <legend className={cx(sectionTitleCls, 'mb-1')}>{lt('compose.fillPlaceholders')}</legend>
                            {placeholders.map((p) => (
                                <div key={p.key}>
                                    <label htmlFor={`lc-ph-${p.key}`} className={labelCls}>{locale === 'am' && p.label_am ? p.label_am : p.label_en}{p.required && ' *'} <span className={mutedCls}>{`{{ ${p.key} }}`}</span></label>
                                    <input id={`lc-ph-${p.key}`} className={inputCls} value={form.data.template_context[p.key] ?? ''} required={p.required}
                                        onChange={(e) => form.setData('template_context', { ...form.data.template_context, [p.key]: e.target.value })} maxLength={2000} />
                                </div>
                            ))}
                        </fieldset>
                    )}
                    <div className="sm:col-span-2">
                        <label htmlFor="lc-summary" className={labelCls}>{lt('register.summary')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <textarea id="lc-summary" rows={2} className={textareaCls} value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} maxLength={5000} />
                    </div>
                </section>

                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2 lg:col-span-4')}>{lt('compose.signatureAndDispatch')}</h2>
                    <div className="sm:col-span-2">
                        <label htmlFor="lc-signatory" className={labelCls}>{lt('common.signatory')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <SearchableSelect id="lc-signatory" value={form.data.signatory_user_id} options={peopleOptions} onChange={(v) => form.setData('signatory_user_id', v)} placeholder={lt('compose.signatoryHelp')} disabled={peopleOptions.length === 0} />
                        {form.errors.signatory_user_id && <p className={errorCls}>{form.errors.signatory_user_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="lc-date" className={labelCls}>{lt('compose.letterDate')}</label>
                        <LocalizedDatePicker id="lc-date" className={inputCls} value={form.data.letter_date} onChange={(iso) => form.setData('letter_date', iso)} />
                    </div>
                    <div>
                        <label htmlFor="lc-priority" className={labelCls}>{lt('common.priority')}</label>
                        <select id="lc-priority" className={inputCls} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            {priorities.map((p) => <option key={p} value={p}>{lt(`priority.${p}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="lc-lang" className={labelCls}>{lt('common.language')}</label>
                        <select id="lc-lang" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            <option value="am">{lt('language.am')}</option>
                            <option value="en">{lt('language.en')}</option>
                        </select>
                        <p className={cx(mutedCls, 'mt-1')}>{lt('compose.languageHelp')}</p>
                    </div>
                    <div>
                        <label htmlFor="lc-class" className={labelCls}>{lt('common.classification')}</label>
                        <select id="lc-class" className={inputCls} value={form.data.classification} onChange={(e) => form.setData('classification', e.target.value)}>
                            {['public', 'internal', 'confidential', 'secret'].map((c) => <option key={c} value={c}>{lt(`classification.${c}`)}</option>)}
                        </select>
                    </div>
                    {(layouts[form.data.organization_id] ?? []).length > 1 && (
                        <div>
                            <label htmlFor="lc-layout" className={labelCls}>{lt('show.layout')}</label>
                            <select id="lc-layout" className={inputCls} value={form.data.letterhead_id} onChange={(e) => form.setData('letterhead_id', e.target.value)}>
                                <option value="">{lt('show.layoutDefault')}</option>
                                {(layouts[form.data.organization_id] ?? []).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                            </select>
                            {form.errors.letterhead_id && <p className={errorCls}>{form.errors.letterhead_id}</p>}
                        </div>
                    )}
                    {file_classes.length > 0 && (
                        <div>
                            <label htmlFor="lc-file-class" className={labelCls}>{lt('show.fileClass')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                            <select id="lc-file-class" className={inputCls} value={form.data.file_class_id} onChange={(e) => form.setData('file_class_id', e.target.value)}>
                                <option value="">—</option>
                                {file_classes.map((c) => <option key={c.id} value={c.id}>{c.code} · {(locale === 'am' ? c.title_am ?? c.title_en : c.title_en ?? c.title_am) ?? ''}</option>)}
                            </select>
                            {form.errors.file_class_id && <p className={errorCls}>{form.errors.file_class_id}</p>}
                        </div>
                    )}
                    <div>
                        <label htmlFor="lc-file" className={labelCls}>{lt('common.fileCode')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id="lc-file" className={inputCls} value={form.data.file_code} onChange={(e) => form.setData('file_code', e.target.value)} maxLength={60} placeholder={lt('register.fileCodePlaceholder')} />
                    </div>
                </section>

                <div className="flex flex-wrap gap-2">
                    <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.organization_id}>{lt('compose.saveDraft')}</button>
                    <Link className={secondaryBtn} href={editing ? route('letters.show', letter.id) : route('letters.index')}>{lt('common.cancel')}</Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
