import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { fieldErrors, firstError } from '../api';
import { useLocale } from '../i18n';
import { Alert, AppDialog, Button, FormField, Input, Select, Textarea } from '../ui';

export type FieldValue = string | number | boolean | null;

export type FieldDef = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'select' | 'date' | 'number' | 'checkbox';
    required?: boolean;
    options?: { value: string; label: string }[];
    /** First option of a select; null = no empty option. */
    emptyOption?: string | null;
    placeholder?: string;
    description?: string;
    /** Both columns of the form grid. */
    wide?: boolean;
    disabled?: boolean;
};

/**
 * One form in the EUISIS dialog (UI kit AppDialog + FormField): the fields
 * come from a list, the API's field messages land under their fields, and
 * the dialog closes only when the save succeeds.
 */
export default function FormDialog({ open, title, description, fields, initial, submitLabel, destructive = false, onSubmit, onClose, onValuesChange, children }: {
    open: boolean;
    title: string;
    description?: string;
    fields: FieldDef[];
    initial: Record<string, FieldValue>;
    submitLabel: string;
    destructive?: boolean;
    /** Throws the API error on failure. */
    onSubmit: (values: Record<string, FieldValue>) => Promise<void>;
    onClose: () => void;
    /** Lets the page load options that depend on a choice (the units of the chosen organization). */
    onValuesChange?: (values: Record<string, FieldValue>) => void;
    children?: ReactNode;
}) {
    const { t } = useLocale();
    const [values, setValues] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setValues(initial);
            setErrors({});
            onValuesChange?.(initial);
        }
        // Reset each time the dialog opens, not on every parent render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    async function submit(event?: FormEvent) {
        event?.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await onSubmit(values);
        } catch (caught) {
            const fields = fieldErrors(caught);
            const known = Object.keys(fields).filter((name) => fields[name] && fieldsByName[name]);
            setErrors(known.length > 0 ? fields : { form: Object.values(fields)[0] ?? firstError(caught) ?? t('platform.error') });
        } finally {
            setBusy(false);
        }
    }

    const fieldsByName = Object.fromEntries(fields.map((field) => [field.name, field]));
    const set = (name: string, value: FieldValue) => {
        const next = { ...values, [name]: value };
        setValues(next);
        onValuesChange?.(next);
    };

    return (
        <AppDialog
            open={open}
            onClose={() => { if (!busy) onClose(); }}
            title={title}
            description={description}
            maxWidth="xl"
            footer={(
                <>
                    <Button variant="outline" onClick={onClose} disabled={busy}>{t('common.cancel')}</Button>
                    <Button variant={destructive ? 'destructive' : 'primary'} onClick={() => void submit()} loading={busy}>{submitLabel}</Button>
                </>
            )}
        >
            <form onSubmit={submit} className="space-y-4">
                {errors.form && <Alert tone="danger">{errors.form}</Alert>}
                {children}
                <div className="grid gap-4 sm:grid-cols-2">
                    {fields.map((field) => (
                        <div key={field.name} className={field.wide || field.type === 'textarea' ? 'sm:col-span-2' : undefined}>
                            {field.type === 'checkbox' ? (
                                <label className="flex items-center gap-3 rounded-card border border-(--app-border) p-3 text-sm">
                                    <input type="checkbox" checked={values[field.name] === true} disabled={field.disabled} onChange={(e) => set(field.name, e.target.checked)} className="h-4 w-4 rounded border-(--app-border-strong) text-primary focus:ring-primary" />
                                    {field.label}
                                    {errors[field.name] && <span className="text-helper text-red-600">{errors[field.name]}</span>}
                                </label>
                            ) : (
                                <FormField label={field.label} required={field.required} description={field.description} error={errors[field.name]}>
                                    {({ id, describedBy, invalid }) => {
                                        const common = { id, 'aria-describedby': describedBy, 'aria-invalid': invalid || undefined, disabled: field.disabled };
                                        const value = values[field.name];
                                        if (field.type === 'select') {
                                            return (
                                                <Select {...common} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => set(field.name, e.target.value === '' ? null : e.target.value)}>
                                                    {field.emptyOption !== null && <option value="">{field.emptyOption ?? '—'}</option>}
                                                    {(field.options ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                                </Select>
                                            );
                                        }
                                        if (field.type === 'textarea') {
                                            return <Textarea {...common} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => set(field.name, e.target.value === '' ? null : e.target.value)} />;
                                        }
                                        return (
                                            <Input
                                                {...common}
                                                type={field.type === 'date' ? 'date' : field.type === 'number' ? 'number' : 'text'}
                                                placeholder={field.placeholder}
                                                value={value === null || value === undefined ? '' : String(value)}
                                                onChange={(e) => set(field.name, e.target.value === '' ? null : field.type === 'number' ? Number(e.target.value) : e.target.value)}
                                            />
                                        );
                                    }}
                                </FormField>
                            )}
                        </div>
                    ))}
                </div>
                <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
            </form>
        </AppDialog>
    );
}
