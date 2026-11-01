import type { ReactNode } from 'react';
import { Alert, Button, Card, FieldError, Input, cx } from '@/Components/ui';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { CaseTypeOption } from '@/court/types/applicantCases';
import { PlusIcon, TrashIcon } from '../../../Cases/Show/icons';

/** A new evidence document row: its title and PDF. `key` only keeps React's rows apart. */
export interface EvidenceRow { key: number; title: string; file: File | null }
/** A new witness row. `key` only keeps React's rows apart. */
export interface WitnessRow { key: number; full_name: string; phone: string; email: string; address: string }

let nextKey = 1;
export const newEvidenceRow = (): EvidenceRow => ({ key: nextKey++, title: '', file: null });
export const newWitnessRow = (): WitnessRow => ({ key: nextKey++, full_name: '', phone: '', email: '', address: '' });

type Errors = Record<string, string | undefined>;

/** The first error whose key is `prefix` or starts with `prefix.` (the Blade `@error('x.*')`). */
export function firstError(errors: Errors, ...prefixes: string[]): string | undefined {
    for (const prefix of prefixes) {
        const key = Object.keys(errors).find((name) => (name === prefix || name.startsWith(`${prefix}.`)) && errors[name]);
        if (key) return errors[key];
    }
    return undefined;
}

/** The case number prefix the Blade forms previewed: letters and digits of the type's prefix (or name), four, upper-case. */
export function casePrefix(type: CaseTypeOption | undefined): string {
    const source = type?.prefix ?? type?.name ?? '';
    const cleaned = (source.match(/[A-Za-z0-9]+/g) ?? []).join('');
    return (cleaned.slice(0, 4) || 'CASE').toUpperCase();
}

/** "Please fix the errors" with every message, at the top of a form, as the Blade form showed it. */
export function ErrorSummary({ errors }: { errors: Errors }) {
    const { t } = useApplicantCaseText();
    const messages = [...new Set(Object.values(errors).filter((message): message is string => Boolean(message)))];
    if (messages.length === 0) return null;

    return (
        <Alert tone="danger" title={t('cm.cases.please_fix_errors')}>
            <ul className="list-inside list-disc space-y-0.5">
                {messages.map((message) => <li key={message}>{message}</li>)}
            </ul>
        </Alert>
    );
}

/** A labelled checkbox (certifications, terms acceptance). */
export function CheckField({ id, checked, onChange, children, error, disabled, required }: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
    error?: string;
    disabled?: boolean;
    required?: boolean;
}) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className={cx('flex items-start gap-2 text-sm text-(--app-foreground)', disabled && 'opacity-60')}>
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    disabled={disabled}
                    required={required}
                    className="mt-0.5 h-4 w-4 shrink-0 rounded border-(--app-border) text-primary focus:ring-primary"
                />
                <span>{children}</span>
            </label>
            <FieldError>{error}</FieldError>
        </div>
    );
}

/** A form section card with a title, an optional hint and the "add" button on the right. */
export function RowsCard({ title, hint, required, addLabel, onAdd, disabled, children }: {
    title: ReactNode;
    hint?: ReactNode;
    required?: boolean;
    addLabel: string;
    onAdd: () => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <Card className="space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-(--app-foreground)">
                        {title}{required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
                    </h2>
                    {hint}
                </div>
                <Button type="button" size="sm" variant="outline" onClick={onAdd} disabled={disabled} icon={<PlusIcon className="h-3.5 w-3.5" />}>{addLabel}</Button>
            </div>
            {children}
        </Card>
    );
}

/** Evidence document rows: a title and a PDF each. */
export function EvidenceRows({ rows, onChange, required, disabled, titlePlaceholder, idPrefix }: {
    rows: EvidenceRow[];
    onChange: (rows: EvidenceRow[]) => void;
    required?: boolean;
    disabled?: boolean;
    titlePlaceholder: string;
    idPrefix: string;
}) {
    const { t, text } = useApplicantCaseText();
    const update = (key: number, patch: Partial<EvidenceRow>) => onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    const minimum = required ? 1 : 0;

    return (
        <div className="space-y-2">
            {rows.map((row, index) => (
                <div key={row.key} className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_1fr_auto] md:items-center">
                    <Input
                        id={`${idPrefix}-title-${index}`}
                        aria-label={titlePlaceholder}
                        value={row.title}
                        onChange={(event) => update(row.key, { title: event.target.value })}
                        placeholder={titlePlaceholder}
                        required={required}
                        disabled={disabled}
                    />
                    <input
                        id={`${idPrefix}-file-${index}`}
                        type="file"
                        accept="application/pdf"
                        aria-label={`${t('cm.cases.file')} ${index + 1}`}
                        onChange={(event) => update(row.key, { file: event.target.files?.[0] ?? null })}
                        required={required}
                        disabled={disabled}
                        className="block w-full text-sm text-(--app-muted-foreground) file:mr-3 file:rounded-control file:border-0 file:bg-(--app-surface-muted) file:px-4 file:py-2 file:text-sm file:font-medium file:text-(--app-foreground)"
                    />
                    {rows.length > minimum ? (
                        <button type="button" onClick={() => onChange(rows.filter((item) => item.key !== row.key))} disabled={disabled}
                            className="inline-flex items-center gap-1 text-xs font-medium text-red-700 hover:underline disabled:opacity-50 dark:text-red-400" aria-label={text('remove_row')}>
                            <TrashIcon className="h-3.5 w-3.5" />
                        </button>
                    ) : <span />}
                </div>
            ))}
        </div>
    );
}

/** Witness rows: name, phone, (email), address. */
export function WitnessRows({ rows, onChange, required, disabled, withEmail, placeholders, idPrefix }: {
    rows: WitnessRow[];
    onChange: (rows: WitnessRow[]) => void;
    required?: boolean;
    disabled?: boolean;
    withEmail?: boolean;
    placeholders: { full_name: string; phone: string; email?: string; address: string };
    idPrefix: string;
}) {
    const { text } = useApplicantCaseText();
    const update = (key: number, patch: Partial<WitnessRow>) => onChange(rows.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    const minimum = required ? 1 : 0;

    return (
        <div className="space-y-2">
            {rows.map((row, index) => (
                <div key={row.key} className={cx('grid grid-cols-1 gap-3 md:items-center', withEmail ? 'md:grid-cols-[2fr_1fr_1fr_2fr_auto]' : 'md:grid-cols-[2fr_1fr_2fr_auto]')}>
                    <Input id={`${idPrefix}-name-${index}`} aria-label={placeholders.full_name} placeholder={placeholders.full_name} value={row.full_name}
                        onChange={(event) => update(row.key, { full_name: event.target.value })} required={required} disabled={disabled} />
                    <Input id={`${idPrefix}-phone-${index}`} aria-label={placeholders.phone} placeholder={placeholders.phone} value={row.phone}
                        onChange={(event) => update(row.key, { phone: event.target.value })} required={required} disabled={disabled} />
                    {withEmail && (
                        <Input id={`${idPrefix}-email-${index}`} type="email" aria-label={placeholders.email} placeholder={placeholders.email} value={row.email}
                            onChange={(event) => update(row.key, { email: event.target.value })} disabled={disabled} />
                    )}
                    <Input id={`${idPrefix}-address-${index}`} aria-label={placeholders.address} placeholder={placeholders.address} value={row.address}
                        onChange={(event) => update(row.key, { address: event.target.value })} required={required} disabled={disabled} />
                    {rows.length > minimum ? (
                        <button type="button" onClick={() => onChange(rows.filter((item) => item.key !== row.key))} disabled={disabled}
                            className="inline-flex items-center gap-1 text-xs font-medium text-red-700 hover:underline disabled:opacity-50 dark:text-red-400" aria-label={text('remove_row')}>
                            <TrashIcon className="h-3.5 w-3.5" />
                        </button>
                    ) : <span />}
                </div>
            ))}
        </div>
    );
}
