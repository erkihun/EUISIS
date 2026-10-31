import { useForm, type InertiaFormProps } from '@inertiajs/react';
import { useEffect, useId, type FormEvent } from 'react';
import { AppDialog } from '@euisis/ui';
import { Button, FormField, Input } from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';

/** What the category store action reads (StorePerformanceCategoryRequest). */
export interface CategoryFormData {
    name: string;
    name_am: string;
    /** Blank: generated from the name. */
    slug: string;
    sort_order: string;
    active: boolean;
    /** Where the store action comes back to (the page the dialog was opened on); empty for the settings page. */
    redirect_to: string;
}

export function categoryFormData(redirectTo = ''): CategoryFormData {
    return { name: '', name_am: '', slug: '', sort_order: '0', active: true, redirect_to: redirectTo };
}

/** The category fields: name, Amharic name, slug (with its hint), order, active. */
export function CategoryFields({ form }: { form: InertiaFormProps<CategoryFormData> }) {
    const { p, text } = usePerformanceText();

    return (
        <div className="space-y-4">
            <FormField label={p('settings.fields.name')} required error={form.errors.name}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required maxLength={255}
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <FormField label={p('settings.fields.name_am')} error={form.errors.name_am}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} value={form.data.name_am} onChange={(event) => form.setData('name_am', event.target.value)} maxLength={255} lang="am"
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <FormField label={p('settings.fields.slug')} description={p('settings.slug_hint')} error={form.errors.slug}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} value={form.data.slug} onChange={(event) => form.setData('slug', event.target.value)} maxLength={50}
                        placeholder={text('slug_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <FormField label={p('settings.fields.order')} error={form.errors.sort_order}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} type="number" min={0} max={65535} value={form.data.sort_order} onChange={(event) => form.setData('sort_order', event.target.value)}
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <label className="inline-flex items-center gap-2 text-sm text-(--app-foreground)">
                <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary"
                    checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} />
                {p('settings.active')}
            </label>
        </div>
    );
}

/** The Add Category dialog: posts to settings.performance-evaluation.categories.store and comes back to `returnUrl`. */
export function CategoryDialog({ open, returnUrl, onClose }: { open: boolean; returnUrl: string; onClose: () => void }) {
    const { p } = usePerformanceText();
    const formId = useId();
    const form = useForm<CategoryFormData>(categoryFormData(returnUrl));

    useEffect(() => {
        if (open) {
            form.setData(categoryFormData(returnUrl));
            form.clearErrors();
        }
        // The form object is recreated on each render; the dialog only re-seeds when it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.settings.performance-evaluation.categories.store'), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <AppDialog
            open={open}
            onClose={() => { if (!form.processing) onClose(); }}
            maxWidth="xl"
            title={p('settings.create_category_title')}
            description={p('settings.create_category_subtitle')}
            footer={(
                <>
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>{p('actions.cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" loading={form.processing}>{p('settings.actions.add_category')}</Button>
                </>
            )}
        >
            <form id={formId} onSubmit={submit}>
                <CategoryFields form={form} />
            </form>
        </AppDialog>
    );
}
