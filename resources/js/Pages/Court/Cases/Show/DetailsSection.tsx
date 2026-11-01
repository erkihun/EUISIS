import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AppDialog } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, FormField, Input, Textarea, actionLinkClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay, formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import type { CaseDetailCase, CaseDocument, CaseWitness } from '@/court/types/caseDetail';
import DocumentSheet from './DocumentSheet';
import { fileKind } from './fileKind';
import { printCaseOverview } from './printCaseOverview';
import SectionCard, { EmptyBox } from './SectionCard';
import { useCaseText } from './useCaseText';
import { EyeIcon, FilesIcon, PencilIcon, PlusIcon, PrintIcon, TrashIcon, UserIcon, WitnessesIcon } from './icons';

interface Props {
    kase: CaseDetailCase;
    docs: CaseDocument[];
    witnesses: CaseWitness[];
    termsItems: string[];
    canEdit: boolean;
    caseLocked: boolean;
}

/**
 * The case details overview: the parties, the case details and relief as
 * filed (justified document sheets), the submitted documents, the witnesses
 * (with their register), and the printable A4 copy.
 */
export default function DetailsSection({ kase, docs, witnesses, termsItems, canEdit, caseLocked }: Props) {
    const { t, text, fill, locale } = useCaseText();
    const system = useCalendarSystem();
    const [printBlocked, setPrintBlocked] = useState(false);

    const print = () => {
        const ok = printCaseOverview({
            title: t('cm.cases.show.case_details_overview'),
            caseNumber: t('cm.cases.case_number'),
            filingDate: t('cm.cases.summary.filing_date'),
            applicant: t('cm.cases.summary.applicant'),
            submittedByLawyer: kase.submitted_by_lawyer ? fill(t('cm.dashboard.submitted_by_lawyer'), { name: kase.lawyer_name }) : null,
            name: t('cm.cases.name'),
            address: t('cm.cases.address'),
            email: t('cm.cases.applicant_email'),
            respondent: t('cm.cases.respondent_defendant'),
            details: t('cm.cases.details.case_details'),
            noDetails: t('cm.cases.details.no_details'),
            relief: t('cm.cases.details.relief_requested'),
            noRelief: t('cm.cases.details.no_relief_specified'),
            documents: t('cm.cases.documents.submitted_documents'),
            document: t('cm.cases.documents.document'),
            witnesses: t('cm.cases.witnesses_section.title'),
            phone: t('cm.cases.labels.phone'),
            printedAt: formatDateTimeDisplay(new Date().toISOString(), system, locale),
        }, {
            kase,
            filingDate: kase.filing_date ? formatDateDisplay(kase.filing_date, system, locale) : '—',
            docs: docs.map((doc) => ({ ...doc, created_display: doc.created_at ? formatDateTimeDisplay(doc.created_at, system, locale) : null })),
            witnesses,
            termsItems,
            locale,
        });
        setPrintBlocked(!ok);
    };

    return (
        <SectionCard
            id="case-details"
            title={t('cm.cases.show.case_details_overview')}
            icon={<FilesIcon />}
            actions={<button type="button" onClick={print} className={cx(buttonClassName({ variant: 'outline', size: 'sm' }), 'print:hidden')}><PrintIcon className="h-4 w-4" /> {t('cm.cases.print')}</button>}
        >
            {printBlocked && <p className="text-sm text-rose-700 dark:text-rose-300">{text('print_dialog_blocked')}</p>}

            {kase.submitted_by_lawyer && (
                <div className="flex items-center gap-2 rounded-control border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-800 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-100">
                    <UserIcon className="h-4 w-4 shrink-0" /> {fill(t('cm.dashboard.submitted_by_lawyer'), { name: kase.lawyer_name })}
                </div>
            )}

            <div className="grid gap-4 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 md:grid-cols-2">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.summary.applicant')}</p>
                    <dl className="space-y-1 text-sm text-(--app-foreground)">
                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.name')}</dt><dd className="font-semibold">{kase.applicant_display_name !== '' ? kase.applicant_display_name : '—'}</dd></div>
                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.address')}</dt><dd className="whitespace-pre-line">{kase.applicant_address !== '' ? kase.applicant_address : '—'}</dd></div>
                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.applicant_email')}</dt><dd>{kase.applicant_email ?? '—'}</dd></div>
                    </dl>
                </div>
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.respondent_defendant')}</p>
                    <dl className="space-y-1 text-sm text-(--app-foreground)">
                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.name')}</dt><dd className="font-semibold">{kase.respondent_name ?? '—'}</dd></div>
                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('cm.cases.address')}</dt><dd>{kase.respondent_address ?? '—'}</dd></div>
                    </dl>
                </div>
            </div>

            <div className="space-y-5 rounded-card bg-(--app-surface-muted) p-3 sm:p-5">
                <div className="space-y-2">
                    <h4 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)"><span aria-hidden="true" className="h-4 w-1 rounded-full bg-primary" />{t('cm.cases.details.case_details')}</h4>
                    <DocumentSheet html={kase.description_html} empty={t('cm.cases.details.no_details')} foldAt={36} />
                </div>
                <div className="space-y-2">
                    <h4 className="flex items-center gap-2 text-sm font-semibold text-(--app-foreground)"><span aria-hidden="true" className="h-4 w-1 rounded-full bg-(--court-brass,#c8a14a)" />{t('cm.cases.details.relief_requested')}</h4>
                    <DocumentSheet html={kase.relief_html ?? ''} empty={t('cm.cases.details.no_relief_specified')} foldAt={24} />
                </div>
            </div>

            <DocumentsTable caseId={kase.id} docs={docs} />
            <WitnessesBlock caseId={kase.id} witnesses={witnesses} canEdit={canEdit && !caseLocked} />
        </SectionCard>
    );
}

function DocumentsTable({ caseId, docs }: { caseId: number; docs: CaseDocument[] }) {
    const { t } = useCaseText();

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between border-b border-(--app-border) pb-3">
                <h4 className="flex items-center gap-2 text-base font-semibold text-(--app-foreground)"><FilesIcon className="h-5 w-5 text-primary" /> {t('cm.cases.documents.submitted_documents')}</h4>
                <span className="rounded-full bg-(--app-surface-muted) px-2.5 py-1 text-xs font-medium text-(--app-muted-foreground)">{docs.length} {t('cm.cases.documents.items')}</span>
            </div>
            {docs.length === 0 ? <EmptyBox>{t('cm.cases.documents.no_documents')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.cases.documents.document')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.cases.summary.type')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.cases.show.uploaded')}</th>
                                <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.show.action')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {docs.map((doc) => (
                                <tr key={doc.id} className={tableRowClassName}>
                                    <td className={cx(tableCellClassName, 'align-top')}>
                                        <div className="font-medium text-(--app-foreground)">{doc.title ?? t('cm.cases.documents.document')}</div>
                                        {doc.description_html && <div className="rich-text mt-1 text-xs text-(--app-muted-foreground)" dangerouslySetInnerHTML={{ __html: doc.description_html }} />}
                                    </td>
                                    <td className={tableCellClassName}>{fileKind(doc.mime) ?? t('cm.cases.documents.document')}</td>
                                    <td className={tableCellClassName}>
                                        <LocalizedDateDisplay value={doc.created_at} withTime />
                                        {doc.size !== null && <span className="ml-1 text-xs text-(--app-muted-foreground)">({(Math.max(0, doc.size) / 1024).toFixed(1)} KB)</span>}
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-right')}>
                                        {doc.has_file
                                            ? <a href={route('court.cases.documents.view', [caseId, doc.id])} target="_blank" rel="noopener noreferrer" className={actionLinkClassName({})}><EyeIcon className="h-3 w-3" /> {t('cm.cases.documents.view')}</a>
                                            : <span className={cx(actionLinkClassName({ tone: 'neutral' }), 'cursor-not-allowed opacity-60')} aria-disabled="true">{t('cm.cases.documents.view')}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

type WitnessForm = { full_name: string; phone: string; email: string; address: string; notes: string };
const EMPTY_WITNESS: WitnessForm = { full_name: '', phone: '', email: '', address: '', notes: '' };

function WitnessesBlock({ caseId, witnesses, canEdit }: { caseId: number; witnesses: CaseWitness[]; canEdit: boolean }) {
    const { t, text } = useCaseText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<CaseWitness | null>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const addForm = useForm<WitnessForm>(EMPTY_WITNESS);

    const add = (event: FormEvent) => {
        event.preventDefault();
        addForm.post(route('court.cases.witnesses.store', caseId), { preserveScroll: true, onSuccess: () => addForm.reset() });
    };

    const remove = async (witness: CaseWitness) => {
        const result = await confirm({ title: t('cm.cases.witnesses_section.delete_confirm'), description: witness.full_name, variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.general.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.cases.witnesses.delete', [caseId, witness.id]), { preserveScroll: true, onStart: () => setRemoving(witness.id), onFinish: () => setRemoving(null) });
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between border-b border-(--app-border) pb-3">
                <h4 className="flex items-center gap-2 text-base font-semibold text-(--app-foreground)"><WitnessesIcon className="h-5 w-5 text-primary" /> {t('cm.cases.witnesses_section.title')}</h4>
                <span className="rounded-full bg-(--app-surface-muted) px-2.5 py-1 text-xs font-medium text-(--app-muted-foreground)">{witnesses.length} {t('cm.cases.witnesses_section.total')}</span>
            </div>

            {canEdit && (
                <form onSubmit={add} className="grid gap-3 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 md:grid-cols-2 xl:grid-cols-5">
                    <FormField id="witness-name" label={t('cm.cases.labels.name')} required error={addForm.errors.full_name}>
                        {({ id, describedBy, invalid }) => <Input id={id} required value={addForm.data.full_name} onChange={(e) => addForm.setData('full_name', e.target.value)} placeholder={t('cm.cases.witnesses_section.full_name_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <FormField id="witness-phone" label={t('cm.cases.labels.phone')} error={addForm.errors.phone}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={addForm.data.phone} onChange={(e) => addForm.setData('phone', e.target.value)} placeholder={t('cm.cases.witnesses_section.phone_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <FormField id="witness-email" label={t('cm.cases.labels.email')} error={addForm.errors.email}>
                        {({ id, describedBy, invalid }) => <Input id={id} type="email" value={addForm.data.email} onChange={(e) => addForm.setData('email', e.target.value)} placeholder={t('cm.cases.witnesses_section.email_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <FormField id="witness-address" label={t('cm.cases.labels.address')} error={addForm.errors.address}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={addForm.data.address} onChange={(e) => addForm.setData('address', e.target.value)} placeholder={t('cm.cases.witnesses_section.address_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <div className="flex items-end">
                        <Button type="submit" variant="primary" loading={addForm.processing} icon={<PlusIcon className="h-4 w-4" />} className="w-full">{t('cm.cases.witnesses_section.add_witness')}</Button>
                    </div>
                </form>
            )}

            {witnesses.length === 0 ? <EmptyBox>{t('cm.cases.witnesses_section.no_witnesses')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.cases.labels.name'), t('cm.cases.labels.phone'), t('cm.cases.labels.email'), t('cm.cases.labels.address')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>
                                    ))}
                                    {canEdit && <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.table.actions')}</th>}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {witnesses.map((witness) => (
                                    <tr key={witness.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{witness.full_name}</td>
                                        <td className={tableCellClassName}>{witness.phone ?? '—'}</td>
                                        <td className={tableCellClassName}>{witness.email ? <a href={`mailto:${witness.email}`} className="text-primary hover:underline">{witness.email}</a> : <span className="text-(--app-muted-foreground)">—</span>}</td>
                                        <td className={tableCellClassName}>{witness.address ?? '—'}</td>
                                        {canEdit && (
                                            <td className={cx(tableCellClassName, 'space-x-2 text-right whitespace-nowrap')}>
                                                <button type="button" onClick={() => setEditing(witness)} className={actionLinkClassName({ tone: 'neutral' })}><PencilIcon className="h-3 w-3" /> {t('cm.cases.general.edit')}</button>
                                                <button type="button" onClick={() => remove(witness)} disabled={removing === witness.id} className={actionLinkClassName({ tone: 'danger' })}><TrashIcon className="h-3 w-3" /> {t('cm.cases.general.delete')}</button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {editing && <WitnessEditDialog key={editing.id} caseId={caseId} witness={editing} onClose={() => setEditing(null)} title={text('edit_witness')} />}
        </div>
    );
}

function WitnessEditDialog({ caseId, witness, onClose, title }: { caseId: number; witness: CaseWitness; onClose: () => void; title: string }) {
    const { t } = useCaseText();
    const form = useForm<WitnessForm>({ full_name: witness.full_name, phone: witness.phone ?? '', email: witness.email ?? '', address: witness.address ?? '', notes: witness.notes ?? '' });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('court.cases.witnesses.update', [caseId, witness.id]), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <AppDialog open onClose={onClose} title={title} maxWidth="lg" footer={(
            <>
                <Button variant="outline" onClick={onClose} disabled={form.processing}>{t('cm.cases.general.cancel')}</Button>
                <Button variant="primary" type="submit" form={`witness-edit-${witness.id}`} loading={form.processing}>{t('cm.cases.general.save')}</Button>
            </>
        )}>
            <form id={`witness-edit-${witness.id}`} onSubmit={save} className="space-y-3">
                <FormField label={t('cm.cases.labels.name')} required error={form.errors.full_name}>
                    {({ id, describedBy, invalid }) => <Input id={id} required value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField label={t('cm.cases.labels.phone')} error={form.errors.phone}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                    <FormField label={t('cm.cases.labels.email')} error={form.errors.email}>
                        {({ id, describedBy, invalid }) => <Input id={id} type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>
                </div>
                <FormField label={t('cm.cases.labels.address')} error={form.errors.address}>
                    {({ id, describedBy, invalid }) => <Input id={id} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <FormField label={t('cm.cases.witnesses_section.notes_placeholder')} error={form.errors.notes}>
                    {({ id, describedBy, invalid }) => <Textarea id={id} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
            </form>
        </AppDialog>
    );
}
