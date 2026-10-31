import type { ReactNode } from 'react';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { FormField, Input } from '@/Components/ui';
import type { LetterFormValues } from '@/court/types/letters';
import { useLetterText } from './useLetterText';

interface Props {
    values: LetterFormValues;
    onChange: <K extends keyof LetterFormValues>(field: K, value: LetterFormValues[K]) => void;
    errors: Partial<Record<keyof LetterFormValues, string>>;
    /** The edit page keeps the case number as it was. */
    caseNumberReadOnly?: boolean;
    /** The hint under the body: compose and edit worded it differently. */
    bodyHint: string;
    /** What sits between the subject and the body (the compose page's reference and template notes). */
    children?: ReactNode;
}

/**
 * The letter itself, as both the compose and the edit page ask for it: who it
 * goes to, the case, the copies, who is served, the subject and the body.
 */
export default function LetterFields({ values, onChange, errors, caseNumberReadOnly = false, bodyHint, children }: Props) {
    const { t } = useLetterText();
    const field = (name: keyof LetterFormValues, options: { placeholder?: string; readOnly?: boolean } = {}) => ({ id, describedBy, invalid }: { id: string; describedBy?: string; invalid: boolean }) => (
        <Input
            id={id}
            value={String(values[name] ?? '')}
            onChange={(event) => onChange(name, event.target.value as never)}
            readOnly={options.readOnly}
            placeholder={options.placeholder}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            className={options.readOnly ? 'w-full bg-(--app-surface-muted)' : 'w-full'}
        />
    );

    return (
        <>
            <div className="grid gap-4 md:grid-cols-2">
                <FormField label={t('cm.letters.form.recipient_name')} error={errors.recipient_name}>{field('recipient_name')}</FormField>
                <FormField label={t('cm.letters.form.recipient_title')} error={errors.recipient_title}>{field('recipient_title')}</FormField>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <FormField label={t('cm.letters.form.recipient_company')} description={t('cm.letters.form.recipient_company_hint')} error={errors.recipient_company}>
                    {field('recipient_company')}
                </FormField>
                <FormField label={t('cm.letters.form.case_number')} description={t('cm.letters.form.case_number_help')} error={errors.case_number}>
                    {field('case_number', { placeholder: t('cm.letters.form.case_number_placeholder'), readOnly: caseNumberReadOnly })}
                </FormField>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <FormField label={t('cm.letters.form.cc')} description={t('cm.letters.form.cc_hint')} error={errors.cc}>
                    {field('cc', { placeholder: t('cm.letters.form.cc_placeholder') })}
                </FormField>
                <FormField label={t('cm.letters.form.delivery_label')} description={t('cm.letters.form.delivery_hint')} error={errors.send_to_applicant ?? errors.send_to_respondent}>
                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-(--app-foreground)">
                        <label className="inline-flex items-center gap-2">
                            <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={values.send_to_applicant} onChange={(event) => onChange('send_to_applicant', event.target.checked)} />
                            <span>{t('cm.letters.form.deliver_applicant')}</span>
                        </label>
                        <label className="inline-flex items-center gap-2">
                            <input type="checkbox" className="rounded border-(--app-border) text-primary focus:ring-primary" checked={values.send_to_respondent} onChange={(event) => onChange('send_to_respondent', event.target.checked)} />
                            <span>{t('cm.letters.form.deliver_respondent')}</span>
                        </label>
                    </div>
                </FormField>
            </div>

            <FormField label={t('cm.letters.form.subject')} error={errors.subject}>{field('subject')}</FormField>

            {children}

            <RichTextEditor
                label={t('cm.letters.form.body')}
                required
                value={values.body}
                onChange={(html) => onChange('body', html)}
                error={errors.body}
                helperText={bodyHint}
                preset="document"
                minHeight={520}
            />
        </>
    );
}
