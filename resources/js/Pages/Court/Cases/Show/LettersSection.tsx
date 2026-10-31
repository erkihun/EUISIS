import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { StatusBadge as UiStatusBadge } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Alert, Button, FormField, Input, Select, actionLinkClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import type { CaseLetter, LetterTemplateOption } from '@/court/types/caseDetail';
import SectionCard, { EmptyBox } from './SectionCard';
import { LETTER_TONES, useCaseText } from './useCaseText';
import { BackIcon, ComposeIcon, MailIcon } from './icons';

const humanize = (status: string): string => {
    const text = status.replace(/_/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
};

/** The letters logged against this case number. */
export function LettersSection({ letters, canView }: { letters: CaseLetter[]; canView: boolean }) {
    const { t, tx } = useCaseText();

    return (
        <SectionCard id="letters" title={t('cm.cases.navigation.letters')} icon={<MailIcon />} count={`${letters.length} ${t('cm.cases.show.entries')}`}>
            {letters.length === 0 ? <EmptyBox>{t('cm.cases.show.no_letters_logged')}</EmptyBox> : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {['reference', 'subject', 'template', 'status', 'date', 'author'].map((column) => (
                                        <th key={column} className={cx(tableHeaderCellClassName, 'text-left')}>{t(`cm.cases.show.letters_table.${column}`)}</th>
                                    ))}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.show.letters_table.action')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {letters.map((letter) => (
                                    <tr key={letter.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}>{letter.reference_number ?? '—'}</td>
                                        <td className={tableCellClassName}>{letter.subject ?? '—'}</td>
                                        <td className={tableCellClassName}>{letter.template_title ?? '—'}</td>
                                        <td className={tableCellClassName}><UiStatusBadge tone={LETTER_TONES[letter.approval_status] ?? 'neutral'}>{tx(`cm.cases.letters_section.status.${letter.approval_status}`, humanize(letter.approval_status))}</UiStatusBadge></td>
                                        <td className={tableCellClassName}><LocalizedDateDisplay value={letter.created_at} withTime /></td>
                                        <td className={tableCellClassName}>{letter.author_name ?? '—'}</td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            {canView
                                                ? <a href={route('court.letters.show', letter.id)} target="_blank" rel="noopener noreferrer" className={actionLinkClassName({ tone: 'neutral' })}>{t('cm.cases.documents.view_letter')}</a>
                                                : <span className="text-xs text-(--app-muted-foreground)">—</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

type LetterForm = {
    template_id: string;
    recipient_name: string;
    recipient_title: string;
    recipient_company: string;
    case_number: string;
    cc: string;
    send_to_applicant: '0' | '1';
    send_to_respondent: '0' | '1';
    subject: string;
    body: string;
};

interface ComposeProps {
    caseNumber: string;
    templates: LetterTemplateOption[];
    nextReference: string | null;
    onBack: () => void;
}

const stripHtml = (html: string): string => html.replace(/<[^>]*>/g, '');

/**
 * The inline letter composer: pick a template to fill the subject and body,
 * address it, choose who receives it, and save it (letters.store).
 */
export function LetterComposeSection({ caseNumber, templates, nextReference, onBack }: ComposeProps) {
    const { t, text } = useCaseText();
    const [selectedId, setSelectedId] = useState('');
    const [loaded, setLoaded] = useState<LetterTemplateOption | null>(null);
    const [loadNotice, setLoadNotice] = useState<string | null>(null);
    const form = useForm<LetterForm>({
        template_id: '', recipient_name: '', recipient_title: '', recipient_company: '', case_number: caseNumber, cc: '',
        send_to_applicant: '1', send_to_respondent: '1', subject: '', body: '',
    });

    const load = () => {
        const template = templates.find((item) => String(item.id) === selectedId);
        if (!template) {
            setLoadNotice(t('cm.letters.form.select_placeholder'));
            return;
        }
        setLoaded(template);
        setLoadNotice(text('template_loaded'));
        form.setData((current) => ({ ...current, template_id: String(template.id), subject: template.title, body: template.body }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.letters.store'), { preserveScroll: true });
    };

    const excerpt = loaded ? stripHtml(loaded.body) : '';

    return (
        <SectionCard
            id="letters-compose"
            title={t('cm.cases.show.write_letter')}
            icon={<ComposeIcon />}
            actions={<button type="button" onClick={onBack} className={buttonClassName({ variant: 'outline', size: 'sm' })}><BackIcon className="h-4 w-4" /> {t('cm.cases.show.back_to_list')}</button>}
        >
            <div className="mx-auto max-w-5xl space-y-6">
                <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-5">
                    <h4 className="text-base font-semibold text-(--app-foreground)">{t('cm.letters.form.select_template')}</h4>
                    <p className="mb-4 text-sm text-(--app-muted-foreground)">{t('cm.letters.description.compose')}</p>
                    <div className="flex flex-col gap-3 md:flex-row md:items-end">
                        <FormField id="inline-template-select" label={t('cm.letters.form.template_label')} className="flex-1">
                            {({ id }) => (
                                <Select id={id} value={selectedId} onChange={(event) => setSelectedId(event.target.value)} className="w-full">
                                    <option value="">{t('cm.letters.form.select_placeholder')}</option>
                                    {templates.map((template) => <option key={template.id} value={template.id}>{template.title}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <Button variant="outline" onClick={load}>{t('cm.letters.actions.load')}</Button>
                    </div>
                    {loadNotice && <p className="mt-2 text-xs text-(--app-muted-foreground)" role="status">{loadNotice}</p>}
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-control border border-(--app-border) p-5">
                    {!loaded && <Alert tone="warning">{t('cm.letters.form.template_notice')}</Alert>}

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={t('cm.letters.form.recipient_name')} error={form.errors.recipient_name}>
                            {({ id, describedBy, invalid }) => <Input id={id} value={form.data.recipient_name} onChange={(e) => form.setData('recipient_name', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                        </FormField>
                        <FormField label={t('cm.letters.form.recipient_title')} error={form.errors.recipient_title}>
                            {({ id, describedBy, invalid }) => <Input id={id} value={form.data.recipient_title} onChange={(e) => form.setData('recipient_title', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                        </FormField>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={t('cm.letters.form.recipient_company')} description={t('cm.letters.form.recipient_company_hint')} error={form.errors.recipient_company}>
                            {({ id, describedBy, invalid }) => <Input id={id} value={form.data.recipient_company} onChange={(e) => form.setData('recipient_company', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                        </FormField>
                        <FormField label={t('cm.letters.form.case_number')} description={t('cm.letters.form.case_number_help')} error={form.errors.case_number}>
                            {({ id, describedBy }) => <Input id={id} value={form.data.case_number} readOnly placeholder={t('cm.letters.form.case_number_placeholder')} aria-describedby={describedBy} className="w-full bg-(--app-surface-muted)" />}
                        </FormField>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={t('cm.letters.form.cc')} description={t('cm.letters.form.cc_hint')} error={form.errors.cc}>
                            {({ id, describedBy, invalid }) => <Input id={id} value={form.data.cc} onChange={(e) => form.setData('cc', e.target.value)} placeholder={t('cm.letters.form.cc_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                        </FormField>
                        <FormField label={t('cm.letters.form.delivery_label')} description={t('cm.letters.form.delivery_hint')} error={form.errors.send_to_applicant}>
                            <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-(--app-foreground)">
                                <label className="inline-flex items-center gap-2">
                                    <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={form.data.send_to_applicant === '1'} onChange={(e) => form.setData('send_to_applicant', e.target.checked ? '1' : '0')} />
                                    <span>{t('cm.letters.form.deliver_applicant')}</span>
                                </label>
                                <label className="inline-flex items-center gap-2">
                                    <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={form.data.send_to_respondent === '1'} onChange={(e) => form.setData('send_to_respondent', e.target.checked ? '1' : '0')} />
                                    <span>{t('cm.letters.form.deliver_respondent')}</span>
                                </label>
                            </div>
                        </FormField>
                    </div>

                    <FormField label={t('cm.letters.form.subject')} error={form.errors.subject}>
                        {({ id, describedBy, invalid }) => <Input id={id} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                    </FormField>

                    {nextReference && (
                        <FormField label={t('cm.cases.show.reference_number_auto')} description={t('cm.cases.show.reference_number_hint')}>
                            {({ id }) => <Input id={id} value={nextReference} readOnly className="w-full bg-(--app-surface-muted)" />}
                        </FormField>
                    )}

                    {loaded && loaded.placeholders.length > 0 && (
                        <div className="rounded-control border border-dashed border-primary/40 bg-primary/5 px-4 py-3 text-xs text-(--app-foreground)">
                            <p className="mb-1 font-semibold">{t('cm.letters.form.placeholders_title')}</p>
                            <p>{t('cm.letters.form.placeholders_help')}</p>
                            <p className="mt-1">{loaded.placeholders.join(', ')}</p>
                        </div>
                    )}
                    {loaded && (
                        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) px-4 py-3 text-sm text-(--app-muted-foreground)">
                            <p className="text-xs uppercase tracking-wide">{t('cm.letters.form.selected_template')}</p>
                            <p className="font-semibold text-(--app-foreground)">{loaded.title}</p>
                            <p className="text-xs">{loaded.category ?? t('cm.letters.form.category_fallback')}</p>
                            <p className="mt-1 text-xs">{excerpt.length > 120 ? `${excerpt.slice(0, 120)}...` : excerpt}</p>
                        </div>
                    )}

                    <RichTextEditor
                        label={t('cm.letters.form.body')}
                        required
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        error={form.errors.body}
                        helperText={t('cm.letters.form.body_hint')}
                        minHeight={480}
                        name="body"
                    />

                    <div className="flex items-center justify-end">
                        <Button type="submit" variant="primary" loading={form.processing}>{t('cm.letters.actions.save_letter')}</Button>
                    </div>
                </form>
            </div>
        </SectionCard>
    );
}
