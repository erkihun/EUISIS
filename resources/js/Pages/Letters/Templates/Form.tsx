import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { dangerBtn, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { OrganizationOption, Placeholder, TemplateFull } from '@/types/letters';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';

interface Props {
    mode: 'create' | 'edit';
    template: TemplateFull | null;
    owners: { platform: boolean; organizations: OrganizationOption[] };
    categories: string[];
    built_in_placeholders: string[];
}

/** The template editor (a full page): identity, the draft text with its placeholders, and the custom placeholders the composer will be asked for. */
export default function TemplateForm({ mode, template, owners, categories, built_in_placeholders }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const editing = mode === 'edit' && template !== null;
    const form = useForm({
        owner_organization_id: editing ? template.owner_organization_id ?? '' : (owners.platform ? '' : owners.organizations[0]?.id ?? ''),
        code: editing ? template.code : '',
        title_en: editing ? template.title_en : '',
        title_am: editing ? template.title_am ?? '' : '',
        category: (editing ? template.category : 'general') as string,
        language: (editing ? template.language : (locale === 'am' ? 'am' : 'en')) as string,
        subject: editing ? template.draft_subject ?? '' : '',
        body: editing ? template.draft_body ?? '' : '',
        placeholders: (editing ? template.draft_placeholders : []) as Placeholder[],
    });
    const title = editing ? lt('templates.editTitle') : lt('templates.createTitle');

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({ ...d, owner_organization_id: d.owner_organization_id || null, title_am: d.title_am || null }));
        if (editing) {
            form.put(route('letters.templates.update', template.id));
        } else {
            form.post(route('letters.templates.store'));
        }
    }

    function setPlaceholder(index: number, patch: Partial<Placeholder>) {
        form.setData('placeholders', form.data.placeholders.map((p, i) => (i === index ? { ...p, ...patch } : p)));
    }

    const errors = form.errors as Record<string, string>;

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={lt('templates.description')} backHref={editing ? route('letters.templates.show', template.id) : route('letters.templates.index')} />}>
            <Head title={title} />
            <ActionErrors />
            <form onSubmit={save} className="space-y-5" aria-label={title}>
                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('templates.identity')}</h2>
                    <div>
                        <label htmlFor="tf-owner" className={labelCls}>{lt('templates.owner')}</label>
                        <select id="tf-owner" className={inputCls} value={form.data.owner_organization_id} onChange={(e) => form.setData('owner_organization_id', e.target.value)} disabled={editing}>
                            {owners.platform && <option value="">{lt('templates.platform')}</option>}
                            {owners.organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                        <p className={cx(mutedCls, 'mt-1')}>{lt('templates.ownerHelp')}</p>
                        {errors.owner_organization_id && <p className={errorCls}>{errors.owner_organization_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="tf-code" className={labelCls}>{lt('templates.code')}</label>
                        <input id="tf-code" className={cx(inputCls, 'uppercase')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} required maxLength={60} pattern="[A-Za-z0-9_-]+" />
                        <p className={cx(mutedCls, 'mt-1')}>{lt('templates.codeHelp')}</p>
                        {errors.code && <p className={errorCls}>{errors.code}</p>}
                    </div>
                    <div>
                        <label htmlFor="tf-title-en" className={labelCls}>{lt('templates.titleEn')}</label>
                        <input id="tf-title-en" className={inputCls} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} required maxLength={200} />
                        {errors.title_en && <p className={errorCls}>{errors.title_en}</p>}
                    </div>
                    <div>
                        <label htmlFor="tf-title-am" className={labelCls}>{lt('templates.titleAm')}</label>
                        <input id="tf-title-am" className={inputCls} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} maxLength={200} lang="am" />
                    </div>
                    <div>
                        <label htmlFor="tf-category" className={labelCls}>{lt('common.category')}</label>
                        <select id="tf-category" className={inputCls} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                            {categories.map((c) => <option key={c} value={c}>{lt(`templateCategory.${c}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="tf-lang" className={labelCls}>{lt('common.language')}</label>
                        <select id="tf-lang" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            <option value="am">{lt('language.am')}</option>
                            <option value="en">{lt('language.en')}</option>
                        </select>
                    </div>
                </section>

                <section className={cx(panelCls, 'space-y-4 p-4')}>
                    <h2 className={sectionTitleCls}>{lt('templates.text')}</h2>
                    <div>
                        <label htmlFor="tf-subject" className={labelCls}>{lt('templates.subject')}</label>
                        <input id="tf-subject" className={inputCls} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} maxLength={500} lang={form.data.language} />
                        {errors.subject && <p className={errorCls}>{errors.subject}</p>}
                    </div>
                    <RichTextEditor id="tf-body" label={lt('templates.body')} value={form.data.body} onChange={(html) => form.setData('body', html)} error={errors.body} locale={form.data.language as 'am' | 'en'} minHeight={360} maxLength={50000}
                        helperText={lt('templates.bodyHelp')} />
                    <details className="text-sm">
                        <summary className="cursor-pointer font-medium">{lt('templates.builtIn')}</summary>
                        <p className={cx(mutedCls, 'mt-1')}>{lt('templates.builtInHelp')}</p>
                        <p className="mt-1 break-words font-mono text-xs">{built_in_placeholders.map((k) => `{{ ${k} }}`).join('   ')}</p>
                    </details>
                </section>

                <section className={cx(panelCls, 'space-y-3 p-4')}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className={sectionTitleCls}>{lt('templates.placeholders')}</h2>
                        <button type="button" className={secondaryBtn} onClick={() => form.setData('placeholders', [...form.data.placeholders, { key: '', label_en: '', label_am: '', required: true }])}>{lt('templates.addPlaceholder')}</button>
                    </div>
                    <p className={mutedCls}>{lt('templates.placeholdersHelp')}</p>
                    {errors.placeholders && <p className={errorCls}>{errors.placeholders}</p>}
                    {form.data.placeholders.map((p, i) => (
                        <div key={i} className="grid gap-2 rounded-control border border-(--app-border) p-3 sm:grid-cols-4">
                            <div>
                                <label htmlFor={`tf-ph-key-${i}`} className={labelCls}>{lt('templates.key')}</label>
                                <input id={`tf-ph-key-${i}`} className={cx(inputCls, 'font-mono')} value={p.key} onChange={(e) => setPlaceholder(i, { key: e.target.value })} required pattern="[A-Za-z][A-Za-z0-9_]*" maxLength={60} />
                                {errors[`placeholders.${i}.key`] && <p className={errorCls}>{errors[`placeholders.${i}.key`]}</p>}
                            </div>
                            <div>
                                <label htmlFor={`tf-ph-en-${i}`} className={labelCls}>{lt('templates.labelEn')}</label>
                                <input id={`tf-ph-en-${i}`} className={inputCls} value={p.label_en} onChange={(e) => setPlaceholder(i, { label_en: e.target.value })} maxLength={120} />
                            </div>
                            <div>
                                <label htmlFor={`tf-ph-am-${i}`} className={labelCls}>{lt('templates.labelAm')}</label>
                                <input id={`tf-ph-am-${i}`} className={inputCls} value={p.label_am ?? ''} onChange={(e) => setPlaceholder(i, { label_am: e.target.value })} maxLength={120} lang="am" />
                            </div>
                            <div className="flex items-end justify-between gap-2">
                                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={p.required} onChange={(e) => setPlaceholder(i, { required: e.target.checked })} /> {lt('templates.required')}</label>
                                <button type="button" className={dangerBtn} onClick={() => form.setData('placeholders', form.data.placeholders.filter((_, j) => j !== i))}>{lt('common.remove')}</button>
                            </div>
                        </div>
                    ))}
                </section>

                <div className="flex flex-wrap gap-2">
                    <button type="submit" className={primaryBtn} disabled={form.processing}>{lt('templates.save')}</button>
                    <Link className={secondaryBtn} href={editing ? route('letters.templates.show', template.id) : route('letters.templates.index')}>{lt('common.cancel')}</Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
