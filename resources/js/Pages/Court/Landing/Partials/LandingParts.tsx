import { router } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Button, FormField, Input, Textarea, cx } from '@/Components/ui';
import { EyeIcon, EyeOffIcon, PencilIcon, Plus, TrashIcon } from '@/Components/Icons';
import { useConfirm } from '@/hooks/useConfirm';
import { usePublicText } from '@/court/i18n/usePublicText';

/** A tab's heading row: title, description and its main action. */
export function PanelHeading({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <h2 className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{description}</p>}
            </div>
            {action}
        </div>
    );
}

/** The "Add …" button that opens a tab's editor. */
export function AddButton({ label, onClick, expanded }: { label: string; onClick: () => void; expanded: boolean }) {
    return (
        <Button type="button" onClick={onClick} aria-expanded={expanded} className="shrink-0">
            <Plus className="h-4 w-4" aria-hidden="true" />
            {label}
        </Button>
    );
}

/**
 * Open/close and "which row is being edited" for a tab's inline editor, as the
 * Blade page's `showForm` / `editing` pair. Opening an edit scrolls the editor
 * into view.
 */
export function useInlineEditor() {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const ref = useRef<HTMLDivElement>(null);

    const reveal = () => window.requestAnimationFrame(() => ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));

    return {
        open,
        editing,
        ref,
        /** The Add button toggles a blank editor. */
        toggleCreate: () => {
            setEditing(null);
            setOpen((value) => !(value && editing === null));
        },
        startEdit: (id: number) => {
            setEditing(id);
            setOpen(true);
            reveal();
        },
        close: () => {
            setOpen(false);
            setEditing(null);
        },
    };
}

/** The editor box (the Blade page's blue panel): its title, the fields, Save and Cancel. */
export function EditorCard({ editorRef, title, onSubmit, saveLabel, cancelLabel, processing, onCancel, children }: {
    editorRef: RefObject<HTMLDivElement | null>;
    title: string;
    onSubmit: (event: FormEvent) => void;
    saveLabel: string;
    cancelLabel: string;
    processing: boolean;
    onCancel: () => void;
    children: ReactNode;
}) {
    return (
        <div ref={editorRef} className="scroll-mt-24 rounded-card border border-primary/30 bg-primary/5 p-5">
            <h3 className="mb-4 text-sm font-semibold text-(--app-foreground)">{title}</h3>
            <form onSubmit={onSubmit} className="space-y-4">
                {children}
                <div className="flex flex-wrap gap-2 pt-1">
                    <Button type="submit" disabled={processing}>{saveLabel}</Button>
                    <Button type="button" variant="outline" onClick={onCancel}>{cancelLabel}</Button>
                </div>
            </form>
        </div>
    );
}

/** The dashed box a tab shows when it has no rows. */
export function EmptyBox({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-card border border-dashed border-(--app-border-strong) bg-(--app-surface-muted) p-10 text-center text-sm text-(--app-muted-foreground)">
            {children}
        </div>
    );
}

/** A small rounded label on a row (badge, type, state). */
export function Chip({ tone = 'neutral', children, className }: { tone?: 'neutral' | 'primary' | 'amber'; children: ReactNode; className?: string }) {
    const tones = {
        neutral: 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
        primary: 'bg-primary/10 text-primary',
        amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    } as const;

    return <span className={cx('rounded-full px-2 py-0.5 text-[10px] font-semibold', tones[tone], className)}>{children}</span>;
}

/** The "Hidden" chip of a row that is switched off. */
export function HiddenChip() {
    const { text } = usePublicText();
    return <Chip>{text('hidden')}</Chip>;
}

const iconButton = 'flex h-8 w-8 items-center justify-center rounded-lg border border-(--app-border) text-(--app-muted-foreground) transition hover:bg-(--app-surface-muted) disabled:opacity-50';

/** A row's actions: show/hide (eye), edit, delete with a confirmation, as on the Blade page. */
export function RowActions({ active, toggleUrl, destroyUrl, onEdit, showToggle = true }: {
    active: boolean;
    toggleUrl: string;
    destroyUrl: string;
    onEdit: () => void;
    showToggle?: boolean;
}) {
    const { t, text } = usePublicText();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState(false);
    const visit = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    const remove = async () => {
        const { confirmed } = await confirm({
            title: t('cm.admin_landing.common.delete'),
            description: t('cm.admin_landing.common.confirm_delete'),
            confirmLabel: t('cm.admin_landing.common.delete'),
            cancelLabel: t('cm.admin_landing.common.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.delete(destroyUrl, visit);
    };

    return (
        <div className="flex shrink-0 items-center gap-1">
            {showToggle && (
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => router.patch(toggleUrl, {}, visit)}
                    className={iconButton}
                    title={active ? text('hide') : text('show')}
                    aria-label={active ? text('hide') : text('show')}
                >
                    {active ? <EyeIcon className="h-4 w-4" aria-hidden="true" /> : <EyeOffIcon className="h-4 w-4" aria-hidden="true" />}
                </button>
            )}
            <button type="button" onClick={onEdit} className={cx(iconButton, 'hover:border-primary/40 hover:text-primary')} title={t('cm.admin_landing.common.edit')} aria-label={t('cm.admin_landing.common.edit')}>
                <PencilIcon className="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" disabled={busy} onClick={() => void remove()} className={cx(iconButton, 'hover:border-red-300 hover:text-red-600')} title={t('cm.admin_landing.common.delete')} aria-label={t('cm.admin_landing.common.delete')}>
                <TrashIcon className="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    );
}

/** An on/off switch (the services list and the section settings used one). */
export function SwitchButton({ checked, onChange, label, disabled }: { checked: boolean; onChange: (checked: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cx(
                'relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50',
                checked ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600',
            )}
        >
            <span className={cx('pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow transition duration-200', checked ? 'translate-x-4' : 'translate-x-0')} />
        </button>
    );
}

/** "(optional)" / "(optional, 3 MB)" after a label, as the Blade labels read. */
export function OptionalHint({ extra }: { extra?: string }) {
    const { t } = usePublicText();
    return <span className="font-normal text-(--app-muted-foreground)">({t('cm.admin_landing.common.optional')}{extra ? `, ${extra}` : ''})</span>;
}

/** The options of the colour selects, in the Blade order. */
export const COLOR_OPTIONS = ['blue', 'orange', 'emerald', 'violet', 'amber'] as const;

export type AmharicFieldSpec = { key: string; label: string; multiline?: boolean; rows?: number; maxLength?: number; mono?: boolean };

/** A blank Amharic text for the fields of a form. */
export function blankAmharic(keys: readonly string[]): Record<string, string> {
    return Object.fromEntries(keys.map((key) => [key, '']));
}

/**
 * A row's Amharic text beside its English fields. Visitors reading in Amharic
 * see it; a field left empty shows the English text. Open while it has text
 * or an error.
 */
export function AmharicFields({ fields, values, errors, onChange }: {
    fields: AmharicFieldSpec[];
    values: Record<string, string>;
    errors: Record<string, string | undefined>;
    onChange: (key: string, value: string) => void;
}) {
    const { t } = usePublicText();
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);
    const open = fields.some((field) => (values[field.key] ?? '').trim() !== '' || Boolean(errors[`am.${field.key}`]));

    return (
        <details open={open} className="group rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-3">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-(--app-foreground) marker:content-none">
                <span>{c('amharic_version')} <span lang="am" className="font-normal text-(--app-muted-foreground)">· አማርኛ</span></span>
                <span aria-hidden="true" className="text-(--app-muted-foreground) transition group-open:rotate-180">⌄</span>
            </summary>
            <p className="mt-1 text-xs text-(--app-muted-foreground)">{c('amharic_help')}</p>
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {fields.map((field) => (
                    <FormField key={field.key} label={field.label} error={errors[`am.${field.key}`]} className={field.multiline ? 'sm:col-span-2' : undefined}>
                        {({ id }) => field.multiline ? (
                            <Textarea id={id} lang="am" rows={field.rows ?? 3} maxLength={field.maxLength} className={field.mono ? 'font-mono' : undefined} value={values[field.key] ?? ''} onChange={(event) => onChange(field.key, event.target.value)} />
                        ) : (
                            <Input id={id} lang="am" maxLength={field.maxLength} value={values[field.key] ?? ''} onChange={(event) => onChange(field.key, event.target.value)} />
                        )}
                    </FormField>
                ))}
            </div>
        </details>
    );
}
