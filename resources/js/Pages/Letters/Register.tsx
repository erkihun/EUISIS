import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import FilePicker from '@/Components/letters/FilePicker';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, textareaCls, useLetters } from '@/Components/letters/helpers';
import SearchableSelect from '@/Components/ui/SearchableSelect';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FileClassOption, OrganizationOption, ReplyStandard, UnitOption } from '@/types/letters';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    organizations: OrganizationOption[];
    selected_organization_id: string;
    units: UnitOption[];
    all_organizations: OrganizationOption[];
    priorities: string[];
    file_classes: FileClassOption[];
    standard: (ReplyStandard & { source: string }) | null;
}

/** Registering a letter that arrived from outside the system: the institution's own number is drawn on save. */
export default function Register({ organizations, selected_organization_id, units, all_organizations, priorities, file_classes, standard }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const [fromSystem, setFromSystem] = useState(false);
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({
        organization_id: selected_organization_id,
        unit_id: '',
        sender_organization_id: '',
        external_party_name: '',
        external_party_address: '',
        external_reference: '',
        subject: '',
        summary: '',
        language: locale === 'am' ? 'am' : 'en',
        priority: 'normal',
        classification: 'internal',
        file_code: '',
        file_class_id: '',
        physical_location: '',
        letter_date: today,
        received_on: today,
        due_on: '',
        files: [] as File[],
    });

    function changeOrganization(id: string) {
        form.setData('organization_id', id);
        router.get(route('letters.register'), { organization_id: id }, { preserveState: true, preserveScroll: true, only: ['units', 'selected_organization_id'] });
    }

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            unit_id: d.unit_id || null,
            sender_organization_id: fromSystem ? d.sender_organization_id || null : null,
            external_party_name: fromSystem ? null : d.external_party_name,
            external_party_address: fromSystem ? null : d.external_party_address || null,
            due_on: d.due_on || null,
            received_on: d.received_on || null,
            file_code: d.file_code || null,
            file_class_id: d.file_class_id || null,
            physical_location: d.physical_location || null,
        }));
        form.post(route('letters.register.store'), { forceFormData: true });
    }

    const senderOptions = all_organizations.filter((o) => o.id !== form.data.organization_id).map((o) => ({ value: o.id, label: pick(o), keywords: `${o.code} ${o.name_en} ${o.name_am ?? ''}` }));

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('register.title')} description={lt('register.description')} backHref={route('letters.index')} />}>
            <Head title={lt('register.title')} />
            <ActionErrors />
            <form onSubmit={save} className="space-y-5" aria-label={lt('register.title')}>
                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('common.institution')}</h2>
                    <div>
                        <label htmlFor="lr-org" className={labelCls}>{lt('register.receivingInstitution')}</label>
                        <select id="lr-org" className={inputCls} value={form.data.organization_id} onChange={(e) => changeOrganization(e.target.value)} required disabled={organizations.length === 1}>
                            {organizations.length !== 1 && <option value="">—</option>}
                            {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                        </select>
                        {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                    </div>
                    <div>
                        <label htmlFor="lr-unit" className={labelCls}>{lt('common.unit')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <select id="lr-unit" className={inputCls} value={form.data.unit_id} onChange={(e) => form.setData('unit_id', e.target.value)} disabled={units.length === 0}>
                            <option value="">—</option>
                            {units.map((u) => <option key={u.id} value={u.id}>{pick(u)}</option>)}
                        </select>
                    </div>
                </section>

                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('common.sender')}</h2>
                    <fieldset className="flex flex-wrap gap-4 sm:col-span-2">
                        <legend className="sr-only">{lt('common.sender')}</legend>
                        <label className="flex items-center gap-2 text-sm"><input type="radio" name="sender_kind" checked={!fromSystem} onChange={() => setFromSystem(false)} /> {lt('register.fromOutside')}</label>
                        <label className="flex items-center gap-2 text-sm"><input type="radio" name="sender_kind" checked={fromSystem} onChange={() => setFromSystem(true)} /> {lt('register.fromInSystem')}</label>
                    </fieldset>
                    {fromSystem ? (
                        <div className="sm:col-span-2">
                            <label htmlFor="lr-sender" className={labelCls}>{lt('register.senderInstitution')}</label>
                            <SearchableSelect id="lr-sender" value={form.data.sender_organization_id} options={senderOptions} onChange={(v) => form.setData('sender_organization_id', v)} placeholder={lt('common.choose')} />
                            {form.errors.sender_organization_id && <p className={errorCls}>{form.errors.sender_organization_id}</p>}
                        </div>
                    ) : (
                        <>
                            <div>
                                <label htmlFor="lr-ext-name" className={labelCls}>{lt('common.externalParty')}</label>
                                <input id="lr-ext-name" className={inputCls} value={form.data.external_party_name} onChange={(e) => form.setData('external_party_name', e.target.value)} required maxLength={255} />
                                {form.errors.external_party_name && <p className={errorCls}>{form.errors.external_party_name}</p>}
                            </div>
                            <div>
                                <label htmlFor="lr-ext-addr" className={labelCls}>{lt('common.externalAddress')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                                <input id="lr-ext-addr" className={inputCls} value={form.data.external_party_address} onChange={(e) => form.setData('external_party_address', e.target.value)} maxLength={500} />
                            </div>
                        </>
                    )}
                    <div>
                        <label htmlFor="lr-ext-ref" className={labelCls}>{lt('common.externalReference')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id="lr-ext-ref" className={inputCls} value={form.data.external_reference} onChange={(e) => form.setData('external_reference', e.target.value)} maxLength={120} />
                    </div>
                    <div>
                        <label htmlFor="lr-date" className={labelCls}>{lt('register.letterDate')}</label>
                        <LocalizedDatePicker id="lr-date" className={inputCls} value={form.data.letter_date} onChange={(iso) => form.setData('letter_date', iso)} required />
                        {form.errors.letter_date && <p className={errorCls}>{form.errors.letter_date}</p>}
                    </div>
                </section>

                <section className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2')}>
                    <h2 className={cx(sectionTitleCls, 'sm:col-span-2')}>{lt('register.content')}</h2>
                    <div className="sm:col-span-2">
                        <label htmlFor="lr-subject" className={labelCls}>{lt('common.subject')}</label>
                        <input id="lr-subject" className={inputCls} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} required maxLength={500} />
                        {form.errors.subject && <p className={errorCls}>{form.errors.subject}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="lr-summary" className={labelCls}>{lt('register.summary')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <textarea id="lr-summary" rows={3} className={textareaCls} value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} maxLength={5000} />
                    </div>
                    <div>
                        <label htmlFor="lr-priority" className={labelCls}>{lt('common.priority')}</label>
                        <select id="lr-priority" className={inputCls} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            {priorities.map((p) => <option key={p} value={p}>{lt(`priority.${p}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="lr-lang" className={labelCls}>{lt('common.language')}</label>
                        <select id="lr-lang" className={inputCls} value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            <option value="am">{lt('language.am')}</option>
                            <option value="en">{lt('language.en')}</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="lr-class" className={labelCls}>{lt('common.classification')}</label>
                        <select id="lr-class" className={inputCls} value={form.data.classification} onChange={(e) => form.setData('classification', e.target.value)}>
                            {['public', 'internal', 'confidential', 'secret'].map((c) => <option key={c} value={c}>{lt(`classification.${c}`)}</option>)}
                        </select>
                        <p className={cx(mutedCls, 'mt-1')}>{lt('register.classificationHelp')}</p>
                    </div>
                    {file_classes.length > 0 && (
                        <div>
                            <label htmlFor="lr-file-class" className={labelCls}>{lt('show.fileClass')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                            <select id="lr-file-class" className={inputCls} value={form.data.file_class_id} onChange={(e) => form.setData('file_class_id', e.target.value)}>
                                <option value="">—</option>
                                {file_classes.map((c) => <option key={c.id} value={c.id}>{c.code} · {(locale === 'am' ? c.title_am ?? c.title_en : c.title_en ?? c.title_am) ?? ''}</option>)}
                            </select>
                        </div>
                    )}
                    <div>
                        <label htmlFor="lr-file" className={labelCls}>{lt('common.fileCode')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id="lr-file" className={inputCls} value={form.data.file_code} onChange={(e) => form.setData('file_code', e.target.value)} maxLength={60} placeholder={lt('register.fileCodePlaceholder')} />
                    </div>
                    <div>
                        <label htmlFor="lr-loc" className={labelCls}>{lt('common.physicalLocation')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <input id="lr-loc" className={inputCls} value={form.data.physical_location} onChange={(e) => form.setData('physical_location', e.target.value)} maxLength={120} placeholder={lt('register.physicalLocationPlaceholder')} />
                    </div>
                    <div>
                        <label htmlFor="lr-received" className={labelCls}>{lt('register.receivedOn')}</label>
                        <LocalizedDatePicker id="lr-received" className={inputCls} value={form.data.received_on} onChange={(iso) => form.setData('received_on', iso)} />
                        {form.errors.received_on && <p className={errorCls}>{form.errors.received_on}</p>}
                    </div>
                    <div>
                        <label htmlFor="lr-due" className={labelCls}>{lt('common.dueOn')} <span className={mutedCls}>({lt('common.optional')})</span></label>
                        <LocalizedDatePicker id="lr-due" className={inputCls} value={form.data.due_on} onChange={(iso) => form.setData('due_on', iso)} />
                        {standard && !form.data.due_on && (
                            <p className={cx(mutedCls, 'mt-1')}>{lt('dashboard.dueDefault', { date: `${form.data.priority === 'very_urgent' ? standard.reply_days_very_urgent : form.data.priority === 'urgent' ? standard.reply_days_urgent : standard.reply_days_normal} ${lt('standards.workingDays')}` })}</p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="lr-files" className={labelCls}>{lt('register.scans')}</label>
                        <FilePicker id="lr-files" multiple accept="application/pdf,image/jpeg,image/png" onChange={(files) => form.setData('files', files.slice(0, 10))} />
                        <p className={cx(mutedCls, 'mt-1')}>{lt('register.scansHelp')}</p>
                        {(form.errors as Record<string, string>)['files.0'] && <p className={errorCls}>{(form.errors as Record<string, string>)['files.0']}</p>}
                        {form.errors.files && <p className={errorCls}>{form.errors.files}</p>}
                    </div>
                </section>

                <div className="flex flex-wrap gap-2">
                    <button type="submit" className={primaryBtn} disabled={form.processing || !form.data.organization_id}>{lt('register.submit')}</button>
                    <Link className={secondaryBtn} href={route('letters.index')}>{lt('common.cancel')}</Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
