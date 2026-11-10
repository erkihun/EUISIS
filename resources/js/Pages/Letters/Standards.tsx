import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useLetters } from '@/Components/letters/helpers';
import { TBody, Table, Td, Th, THead, Tr, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FileClassOption, FileClassRow, OrganizationOption, ReplyStandard } from '@/types/letters';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface Props {
    organizations: OrganizationOption[];
    platform: boolean;
    selected_organization_id: string;
    own: ReplyStandard | null;
    effective: ReplyStandard & { source: 'institution' | 'platform' | 'default' };
    defaults: ReplyStandard;
    file_classes: FileClassRow[];
    inherited_classes: FileClassOption[];
}

const STANDARD_FIELDS: (keyof ReplyStandard)[] = ['reply_days_normal', 'reply_days_urgent', 'reply_days_very_urgent', 'interim_reply_days', 'escalation_after_days'];

/** Letter standards of one institution (or the platform defaults): the reply-time standard and the file classification scheme. */
export default function Standards({ organizations, platform, selected_organization_id, own, effective, file_classes, inherited_classes }: Props): JSX.Element {
    const { lt, pick, locale } = useLetters();
    const standard = useForm({ organization_id: selected_organization_id, ...(Object.fromEntries(STANDARD_FIELDS.map((f) => [f, own?.[f] ?? effective[f]])) as unknown as ReplyStandard) });
    const [editing, setEditing] = useState<FileClassRow | null>(null);
    const classForm = useForm({ organization_id: selected_organization_id, parent_id: '', code: '', title_am: '', title_en: '', active: true, position: 0 });

    function changeOrganization(id: string) {
        router.get(route('letters.standards.edit'), id ? { organization_id: id } : {}, { preserveState: false });
    }

    function saveStandard(event: FormEvent) {
        event.preventDefault();
        standard.transform((d) => ({ ...d, organization_id: d.organization_id || null }));
        standard.put(route('letters.standards.update'), { preserveScroll: true });
    }

    function startEdit(row: FileClassRow | null) {
        setEditing(row);
        classForm.setData({ organization_id: selected_organization_id, parent_id: row?.parent_id ?? '', code: row?.code ?? '', title_am: row?.title_am ?? '', title_en: row?.title_en ?? '', active: row?.active ?? true, position: row?.position ?? 0 });
    }

    function saveClass(event: FormEvent) {
        event.preventDefault();
        classForm.transform((d) => ({ ...d, organization_id: d.organization_id || null, parent_id: d.parent_id || null, title_am: d.title_am || null, title_en: d.title_en || null }));
        const options = { preserveScroll: true, onSuccess: () => { startEdit(null); } };
        if (editing) {
            classForm.put(route('letters.file-classes.update', editing.id), options);
        } else {
            classForm.post(route('letters.file-classes.store'), options);
        }
    }

    const title = (c: FileClassOption) => (locale === 'am' ? c.title_am ?? c.title_en : c.title_en ?? c.title_am) ?? '';
    const errors = { ...(standard.errors as Record<string, string>), ...(classForm.errors as Record<string, string>) };

    return (
        <AuthenticatedLayout header={<PageHeader title={lt('standards.title')} description={lt('standards.description')} backHref={route('letters.dashboard')} />}>
            <Head title={lt('standards.title')} />
            <ActionErrors />
            <div className="space-y-5">
                <div className={cx(panelCls, 'p-4')}>
                    <label htmlFor="ls-org" className={labelCls}>{lt('letterheads.institution')}</label>
                    <select id="ls-org" className={inputCls} value={selected_organization_id} onChange={(e) => changeOrganization(e.target.value)}>
                        {platform && <option value="">{lt('standards.platformDefault')}</option>}
                        {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                    </select>
                </div>

                <form onSubmit={saveStandard} className={cx(panelCls, 'grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5')} aria-label={lt('standards.standard')}>
                    <div className="sm:col-span-2 lg:col-span-5">
                        <h2 className={sectionTitleCls}>{lt('standards.standard')}</h2>
                        <p className={mutedCls}>{lt('standards.standardHelp')} · {lt(`standards.source.${effective.source}`)}</p>
                    </div>
                    {STANDARD_FIELDS.map((f) => (
                        <div key={f}>
                            <label htmlFor={`ls-${f}`} className={labelCls}>{lt(`standards.fields.${f}`)}</label>
                            <input id={`ls-${f}`} type="number" min={0} max={120} className={inputCls} value={standard.data[f]} onChange={(e) => standard.setData(f, Number(e.target.value))} required />
                            <p className={cx(mutedCls, 'mt-1')}>{lt(`standards.help.${f}`)}</p>
                            {errors[f] && <p className={errorCls}>{errors[f]}</p>}
                        </div>
                    ))}
                    <div className="sm:col-span-2 lg:col-span-5">
                        <button type="submit" className={primaryBtn} disabled={standard.processing}>{lt('standards.saveStandard')}</button>
                    </div>
                </form>

                <section className={cx(panelCls, 'p-4')} aria-labelledby="ls-classes">
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 id="ls-classes" className={sectionTitleCls}>{lt('standards.scheme')}</h2>
                            <p className={mutedCls}>{lt('standards.schemeHelp')}</p>
                        </div>
                        <button type="button" className={secondaryBtn} onClick={() => startEdit(null)}>{lt('standards.addClass')}</button>
                    </div>

                    <form onSubmit={saveClass} className="mb-4 grid gap-3 rounded-control border border-(--app-border) p-3 sm:grid-cols-5" aria-label={editing ? lt('standards.editClass') : lt('standards.addClass')}>
                        <div>
                            <label htmlFor="fc-code" className={labelCls}>{lt('standards.classCode')}</label>
                            <input id="fc-code" className={cx(inputCls, 'font-mono uppercase')} value={classForm.data.code} onChange={(e) => classForm.setData('code', e.target.value.toUpperCase())} required maxLength={40} placeholder="HR/12" />
                            {errors.code && <p className={errorCls}>{errors.code}</p>}
                        </div>
                        <div>
                            <label htmlFor="fc-am" className={labelCls}>{lt('standards.classTitleAm')}</label>
                            <input id="fc-am" className={inputCls} value={classForm.data.title_am} onChange={(e) => classForm.setData('title_am', e.target.value)} maxLength={200} lang="am" />
                        </div>
                        <div>
                            <label htmlFor="fc-en" className={labelCls}>{lt('standards.classTitleEn')}</label>
                            <input id="fc-en" className={inputCls} value={classForm.data.title_en} onChange={(e) => classForm.setData('title_en', e.target.value)} maxLength={200} />
                        </div>
                        <div>
                            <label htmlFor="fc-parent" className={labelCls}>{lt('standards.classParent')}</label>
                            <select id="fc-parent" className={inputCls} value={classForm.data.parent_id} onChange={(e) => classForm.setData('parent_id', e.target.value)}>
                                <option value="">—</option>
                                {file_classes.filter((c) => c.id !== editing?.id).map((c) => <option key={c.id} value={c.id}>{c.code} · {title(c)}</option>)}
                            </select>
                            {errors.parent_id && <p className={errorCls}>{errors.parent_id}</p>}
                        </div>
                        <div className="flex items-end gap-2">
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={classForm.data.active} onChange={(e) => classForm.setData('active', e.target.checked)} /> {lt('standards.classActive')}</label>
                            <button type="submit" className={primaryBtn} disabled={classForm.processing}>{editing ? lt('common.save') : lt('common.add')}</button>
                            {editing && <button type="button" className={secondaryBtn} onClick={() => startEdit(null)}>{lt('common.cancel')}</button>}
                        </div>
                    </form>

                    {file_classes.length === 0 ? (
                        <div>
                            <p className={mutedCls}>{inherited_classes.length > 0 ? lt('standards.inherited') : lt('standards.noClasses')}</p>
                            {inherited_classes.length > 0 && <ul className="mt-2 flex flex-wrap gap-2 text-xs">{inherited_classes.map((c) => <li key={c.id} className="rounded-control border border-(--app-border) px-2 py-0.5"><span className="font-mono">{c.code}</span> · {title(c)}</li>)}</ul>}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <THead><Tr><Th>{lt('standards.classCode')}</Th><Th>{lt('standards.classTitleAm')}</Th><Th>{lt('standards.classTitleEn')}</Th><Th>{lt('standards.classParent')}</Th><Th>{lt('common.status')}</Th><Th>{lt('common.actions')}</Th></Tr></THead>
                                <TBody>
                                    {file_classes.map((c) => (
                                        <Tr key={c.id}>
                                            <Td className="font-mono">{c.code}</Td>
                                            <Td>{c.title_am ?? '—'}</Td>
                                            <Td>{c.title_en ?? '—'}</Td>
                                            <Td>{file_classes.find((p) => p.id === c.parent_id)?.code ?? '—'}</Td>
                                            <Td>{c.active ? lt('standards.classActive') : lt('standards.classInactive')}</Td>
                                            <Td><button type="button" className={secondaryBtn} onClick={() => startEdit(c)}>{lt('common.edit')}</button></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
