import { router } from '@inertiajs/react';
import { AppDialog, Button, Card, FormField, Input, Select, StatusBadge, Textarea } from '@euisis/ui';
import { useState, type ReactNode } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useTransportLabels } from './labels';

/** Shared bits for the verified-usage pages (admin and portal). */

export function Kpi({ label, value, hint, tone }: { label: string; value: string | number | null | undefined; hint?: string; tone?: 'danger' | 'warning' | 'success' }) {
    const color = tone === 'danger' ? 'text-red-600 dark:text-red-400' : tone === 'warning' ? 'text-amber-600 dark:text-amber-400' : tone === 'success' ? 'text-emerald-600 dark:text-emerald-400' : '';
    return (
        <Card className="p-4">
            <p className="text-xs font-medium text-(--app-muted-foreground)">{label}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${color}`}>{value ?? '—'}</p>
            {hint && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{hint}</p>}
        </Card>
    );
}

/** An amount as the server sent it (a decimal string); never recomputed in the browser. */
export function Money({ value, currency = 'ETB' }: { value: string | null | undefined; currency?: string }) {
    if (value === null || value === undefined) return <span>—</span>;
    const [whole, cents = '00'] = value.split('.');
    const sign = whole.startsWith('-') ? '−' : '';
    return <span className="tabular-nums">{sign}{Number(whole.replace('-', '')).toLocaleString()}.{cents.padEnd(2, '0')} <span className="text-xs text-(--app-muted-foreground)">{currency}</span></span>;
}

export function DateText({ value, withTime = false }: { value: string | null | undefined; withTime?: boolean }) {
    return <LocalizedDateDisplay value={value ?? null} withTime={withTime} />;
}

export function Pill({ group = 'statuses', value }: { group?: 'statuses' | 'directions' | 'methods' | 'severities' | 'syncStates' | 'occupancy'; value: string | null | undefined }) {
    const { label, tone } = useTransportLabels();
    return <StatusBadge tone={tone(value)}>{label(group, value)}</StatusBadge>;
}

export function Percent({ value }: { value: number | null | undefined }) {
    if (value === null || value === undefined) return <span>—</span>;
    const tone = value >= 100 ? 'text-red-600' : value >= 75 ? 'text-amber-600' : '';
    return <span className={`tabular-nums ${tone}`}>{value}%</span>;
}

type Field = { name: string; label: string; type?: 'text' | 'textarea' | 'number' | 'date' | 'select'; required?: boolean; options?: { value: string; label: string }[]; placeholder?: string; description?: string };

/**
 * A workflow action: opens a dialog with the fields the action needs
 * (a reason, an amount, a vehicle), posts them, and shows the server's
 * validation errors in the dialog. The same component drives dispatch,
 * settlement transitions, voids and reviews.
 */
export function ActionDialogButton({ label, url, method = 'post', data = {}, fields = [], title, description, variant = 'outline', size = 'sm', destructive = false, confirmLabel, onDone }: {
    label: string;
    url: string;
    method?: 'post' | 'patch' | 'delete';
    data?: Record<string, string | number | boolean | null>;
    fields?: Field[];
    title?: string;
    description?: string;
    variant?: 'primary' | 'outline' | 'ghost' | 'destructive' | 'destructiveOutline' | 'secondary';
    size?: 'xs' | 'sm' | 'default';
    destructive?: boolean;
    confirmLabel?: string;
    onDone?: () => void;
}) {
    const { t } = useTransportLabels();
    const [open, setOpen] = useState(false);
    const [values, setValues] = useState<Record<string, string>>(Object.fromEntries(fields.map((f) => [f.name, ''])));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    function submit() {
        setBusy(true);
        setErrors({});
        router.visit(url, {
            method,
            data: { ...data, ...values } as Record<string, string | number | boolean | null>,
            preserveScroll: true,
            onError: (e) => setErrors(e as Record<string, string>),
            onSuccess: () => { setOpen(false); setValues(Object.fromEntries(fields.map((f) => [f.name, '']))); onDone?.(); },
            onFinish: () => setBusy(false),
        });
    }

    return (
        <>
            <Button type="button" variant={destructive ? 'destructiveOutline' : variant} size={size} onClick={() => (fields.length === 0 && !description ? submit() : setOpen(true))} disabled={busy}>{label}</Button>
            <AppDialog open={open} onClose={() => !busy && setOpen(false)} title={title ?? label} description={description}
                footer={<><Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={busy}>{t('transport.cancel')}</Button><Button type="button" variant={destructive ? 'destructive' : 'primary'} onClick={submit} disabled={busy}>{confirmLabel ?? label}</Button></>}>
                <div className="space-y-3">
                    {fields.map((field) => (
                        <FormField key={field.name} label={field.label} required={field.required} error={errors[field.name]} description={field.description}>
                            {({ id, describedBy, invalid }) => field.type === 'textarea'
                                ? <Textarea id={id} rows={3} value={values[field.name]} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} placeholder={field.placeholder} aria-describedby={describedBy} aria-invalid={invalid} />
                                : field.type === 'select'
                                    ? <Select id={id} value={values[field.name]} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} aria-describedby={describedBy} aria-invalid={invalid}>{(field.options ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
                                    : <Input id={id} type={field.type ?? 'text'} inputMode={field.type === 'number' ? 'decimal' : undefined} value={values[field.name]} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} placeholder={field.placeholder} aria-describedby={describedBy} aria-invalid={invalid} />}
                        </FormField>
                    ))}
                    {errors.action && <p className="text-sm text-red-600" role="alert">{errors.action}</p>}
                </div>
            </AppDialog>
        </>
    );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 text-sm">{children ?? '—'}</dd>
        </div>
    );
}

/** Filter query from the current URL plus the given overrides, for list pages. */
export function queryWith(overrides: Record<string, string | number | null | undefined>): Record<string, string> {
    const current = Object.fromEntries(new URLSearchParams(window.location.search));
    const next: Record<string, string> = { ...current };
    for (const [key, value] of Object.entries(overrides)) {
        if (value === null || value === undefined || value === '') delete next[key];
        else next[key] = String(value);
    }
    return next;
}
