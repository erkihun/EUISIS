import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import FunctionTree, { type FunctionNode, type PowerOption } from '@/Components/organizationDesign/FunctionTree';
import MandateForm, { type InstrumentType, type MandateFormValues } from '@/Components/organizationDesign/MandateForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, FieldError, FormField, Input, Textarea, actionLinkClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { MandateSummary } from './Index';

type Power = { id: string; code: string; title_en: string; title_am: string | null; description_en: string | null; description_am: string | null; legal_reference: string | null; sequence: number; status: string };

type Props = {
    mandate: MandateSummary & {
        description_en: string | null; description_am: string | null; legal_reference: string | null; article_reference: string | null; issuing_authority: string | null;
        approved_by: string | null; approved_at: string | null; created_by: string | null; created_by_id: number | null;
    };
    versions: { id: string; version_no: number; status: string; effective_from: string | null; effective_to: string | null }[];
    powers: Power[];
    functions: FunctionNode[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    instrumentTypes: InstrumentType[];
    can: { update: boolean; publish: boolean; version: boolean; manageFunctions: boolean };
};

export default function MandateShow({ mandate, versions, powers, functions, history, instrumentTypes, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState(false);
    const [versioning, setVersioning] = useState(false);
    const [powerForm, setPowerForm] = useState<Power | 'new' | null>(null);

    const values: MandateFormValues = {
        organization_id: mandate.organization_id, code: mandate.code, title_en: mandate.title_en, title_am: mandate.title_am ?? '',
        description_en: mandate.description_en ?? '', description_am: mandate.description_am ?? '',
        legal_instrument_type_id: mandate.legal_instrument_type_id, legal_instrument_number: mandate.legal_instrument_number,
        legal_reference: mandate.legal_reference ?? '', article_reference: mandate.article_reference ?? '', issuing_authority: mandate.issuing_authority ?? '',
        effective_from: mandate.effective_from ?? '', effective_to: mandate.effective_to ?? '',
    };

    async function publish() {
        const { confirmed } = await confirm({ title: tt('mandates.publish'), description: tt('mandates.publishConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.mandates.publish', mandate.id), {}, { preserveScroll: true });
    }

    async function cancel() {
        const { confirmed } = await confirm({ title: tt('mandates.cancel'), description: tt('mandates.cancelConfirm'), variant: 'danger' });
        if (confirmed) router.post(route('organization-design.mandates.cancel', mandate.id));
    }

    async function removePower(power: Power) {
        const { confirmed } = await confirm({ title: tt('powers.remove'), description: tt('powers.removeConfirm'), variant: 'danger' });
        if (confirmed) router.delete(route('organization-design.powers.destroy', power.id), { preserveScroll: true });
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-[color:var(--app-foreground)]">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.mandates.index')}
                title={`${mandate.code} · ${titleOf(mandate, locale)}`}
                description={`${nameOf(mandate.organization, locale)} · ${tt('mandates.version')} ${mandate.version_no}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.update && <Button variant="outline" onClick={() => setEditing(true)}>{tt('mandates.edit')}</Button>}
                        {can.update && <Button variant="destructiveOutline" onClick={cancel}>{tt('mandates.cancel')}</Button>}
                        {can.version && <Button variant="outline" onClick={() => setVersioning(true)}>{tt('mandates.newVersion')}</Button>}
                        {can.publish && <Button variant="primary" onClick={publish}>{tt('mandates.publish')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${mandate.code} · ${titleOf(mandate, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('mandates.details')}</CardTitle>
                            <StatusPill status={mandate.status} />
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.instrumentType'), nameOf(mandate.instrument_type, locale))}
                            {detail(tt('fields.instrumentNumber'), mandate.legal_instrument_number)}
                            {detail(tt('fields.legalReference'), mandate.legal_reference)}
                            {detail(tt('fields.articleReference'), mandate.article_reference)}
                            {detail(tt('fields.issuingAuthority'), mandate.issuing_authority)}
                            {detail(tt('fields.effectiveFrom'), <LocalizedDateDisplay value={mandate.effective_from} />)}
                            {detail(tt('fields.effectiveTo'), mandate.effective_to ? <LocalizedDateDisplay value={mandate.effective_to} /> : null)}
                            {detail(tt('mandates.draftedBy'), mandate.created_by)}
                            {detail(tt('mandates.publishedBy'), mandate.approved_by ? `${mandate.approved_by}` : null)}
                            {detail(tt('fields.descriptionEn'), mandate.description_en)}
                            {detail(tt('fields.descriptionAm'), mandate.description_am)}
                        </dl>
                        {mandate.status === 'draft' && !can.publish && can.update && (
                            <p className="border-t border-[color:var(--app-border)] px-5 py-3 text-xs text-[color:var(--app-muted-foreground)]">{tt('mandates.publishByOther')}</p>
                        )}
                    </Card>

                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <div><CardTitle>{tt('powers.title')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('powers.description')}</p></div>
                            {can.manageFunctions && <Button size="sm" variant="primary" onClick={() => setPowerForm('new')}>{tt('powers.add')}</Button>}
                        </CardHeader>
                        {powers.length === 0 ? <p className="px-5 py-6 text-center text-sm text-[color:var(--app-muted-foreground)]">{tt('powers.none')}</p> : (
                            <ul className="divide-y divide-[color:var(--app-border)]">
                                {powers.map((p) => (
                                    <li key={p.id} className="flex flex-wrap items-start gap-3 px-5 py-3">
                                        <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{p.code}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-[color:var(--app-foreground)]">{titleOf(p, locale)}</p>
                                            {(locale === 'am' ? p.description_am : p.description_en) && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{locale === 'am' ? p.description_am : p.description_en}</p>}
                                            {p.legal_reference && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{p.legal_reference}</p>}
                                        </div>
                                        <StatusPill status={p.status} />
                                        {can.manageFunctions && (
                                            <span className="flex gap-3">
                                                <button type="button" className={actionLinkClassName()} onClick={() => setPowerForm(p)}>{t('common.edit')}</button>
                                                <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removePower(p)}>{t('common.remove')}</button>
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>{tt('functions.tree')}</CardTitle><p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('functions.description')}</p></CardHeader>
                        <div className="p-3">
                            <FunctionTree tree={functions} mandateId={mandate.id} powers={powers as PowerOption[]} canManage={can.manageFunctions} emptyText={tt('functions.noneForMandate')} />
                        </div>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('mandates.versions')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <div>
                                        {v.id === mandate.id ? <span className="font-semibold">v{v.version_no}</span> : <Link href={route('organization-design.mandates.show', v.id)} className={actionLinkClassName({ size: 'sm' })}>v{v.version_no}</Link>}
                                        <p className="text-xs text-[color:var(--app-muted-foreground)]"><LocalizedDateDisplay value={v.effective_from} />{v.effective_to && <> – <LocalizedDateDisplay value={v.effective_to} /></>}</p>
                                    </div>
                                    <StatusPill status={v.status} />
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('mandates.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-[color:var(--app-foreground)]">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-[color:var(--app-muted-foreground)]">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.update && <MandateForm show={editing} onClose={() => setEditing(false)} title={tt('mandates.edit')} initial={values} url={route('organization-design.mandates.update', mandate.id)} method="put" instrumentTypes={instrumentTypes} />}
            {can.version && <MandateForm show={versioning} onClose={() => setVersioning(false)} title={tt('mandates.newVersion')} help={tt('mandates.newVersionHelp')} initial={{ ...values, effective_from: '', effective_to: '' }} url={route('organization-design.mandates.versions.store', mandate.id)} method="post" instrumentTypes={instrumentTypes} lockCode />}
            {powerForm && <PowerForm mandateId={mandate.id} power={powerForm === 'new' ? null : powerForm} onClose={() => setPowerForm(null)} />}
        </AuthenticatedLayout>
    );
}

function PowerForm({ mandateId, power, onClose }: { mandateId: string; power: Power | null; onClose: () => void }) {
    const { tt } = useDesignText();
    const form = useForm({
        code: power?.code ?? '', title_en: power?.title_en ?? '', title_am: power?.title_am ?? '', description_en: power?.description_en ?? '',
        description_am: power?.description_am ?? '', legal_reference: power?.legal_reference ?? '', sequence: String(power?.sequence ?? 0), status: power?.status ?? 'active',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (power) form.put(route('organization-design.powers.update', power.id), options);
        else form.post(route('organization-design.mandates.powers.store', mandateId), options);
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{power ? tt('powers.edit') : tt('powers.add')}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField id="pw-code" label={tt('fields.code')} required error={form.errors.code}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}</FormField>
                    <FormField id="pw-seq" label={tt('fields.sequence')} error={form.errors.sequence}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} />}</FormField>
                    <FormField id="pw-title-en" label={tt('fields.titleEn')} required error={form.errors.title_en}>{({ id }) => <Input id={id} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />}</FormField>
                    <FormField id="pw-title-am" label={tt('fields.titleAm')} error={form.errors.title_am}>{({ id }) => <Input id={id} value={form.data.title_am} onChange={(e) => form.setData('title_am', e.target.value)} />}</FormField>
                    <FormField id="pw-legal" label={tt('fields.legalReference')} className="sm:col-span-2" error={form.errors.legal_reference}>{({ id }) => <Input id={id} value={form.data.legal_reference} onChange={(e) => form.setData('legal_reference', e.target.value)} />}</FormField>
                    <FormField id="pw-desc-en" label={tt('fields.descriptionEn')} className="sm:col-span-2" error={form.errors.description_en}>{({ id }) => <Textarea id={id} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}</FormField>
                    <FormField id="pw-desc-am" label={tt('fields.descriptionAm')} className="sm:col-span-2" error={form.errors.description_am}>{({ id }) => <Textarea id={id} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}</FormField>
                </div>
                <FieldError>{form.errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
