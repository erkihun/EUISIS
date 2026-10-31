import { useForm, type InertiaFormProps } from '@inertiajs/react';
import { useEffect, useId, type FormEvent } from 'react';
import { AppDialog } from '@euisis/ui';
import { Button, FormField, Input, Select, Textarea } from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import type { PerformanceCategoryOption, PerformanceCriterionRow } from '@/court/types/performance';

/** What the criterion store/update actions read (StorePerformanceCriterionRequest / UpdatePerformanceCriterionRequest). */
export interface CriterionFormData {
    name: string;
    name_am: string;
    category: string;
    weight: string;
    sort_order: string;
    active: boolean;
    description: string;
    /** Where the store action comes back to (the page the dialog was opened on); empty for the settings page. */
    redirect_to: string;
}

/** A criterion's form values: its saved ones, or the Blade defaults (first category, weight 10, order 0, active). */
export function criterionFormData(criterion: PerformanceCriterionRow | null, categories: PerformanceCategoryOption[], redirectTo = ''): CriterionFormData {
    if (criterion) {
        return {
            name: criterion.name,
            name_am: criterion.name_am ?? '',
            category: criterion.category,
            weight: String(criterion.weight),
            sort_order: String(criterion.sort_order),
            active: criterion.active,
            description: criterion.description ?? '',
            redirect_to: redirectTo,
        };
    }

    return { name: '', name_am: '', category: categories[0]?.slug ?? '', weight: '10', sort_order: '0', active: true, description: '', redirect_to: redirectTo };
}

/**
 * The criterion fields: name, Amharic name, category, weight (0-100), order,
 * active, description. A criterion whose category is no longer active keeps
 * it as an option, so editing it does not move it silently.
 */
export function CriterionFields({ form, categories, currentCategoryLabel }: {
    form: InertiaFormProps<CriterionFormData>;
    categories: PerformanceCategoryOption[];
    /** The saved category's label, when it is not among the active categories. */
    currentCategoryLabel?: string;
}) {
    const { p } = usePerformanceText();
    const listed = categories.some((category) => category.slug === form.data.category);

    return (
        <div className="grid gap-4 sm:grid-cols-2">
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
            <FormField label={p('settings.fields.category')} required error={form.errors.category}>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} value={form.data.category} onChange={(event) => form.setData('category', event.target.value)} required
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                        {!listed && form.data.category !== '' && <option value={form.data.category}>{currentCategoryLabel ?? form.data.category}</option>}
                        {categories.map((category) => <option key={category.slug} value={category.slug}>{category.name}</option>)}
                    </Select>
                )}
            </FormField>
            <FormField label={p('settings.fields.weight')} required error={form.errors.weight}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} type="number" min={0} max={100} value={form.data.weight} onChange={(event) => form.setData('weight', event.target.value)} required
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <FormField label={p('settings.fields.order')} error={form.errors.sort_order}>
                {({ id, describedBy, invalid }) => (
                    <Input id={id} type="number" min={0} max={65535} value={form.data.sort_order} onChange={(event) => form.setData('sort_order', event.target.value)}
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
            <label className="inline-flex items-center gap-2 self-end pb-2 text-sm text-(--app-foreground)">
                <input type="checkbox" className="h-4 w-4 rounded border-(--app-border) text-primary focus:ring-primary"
                    checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} />
                {p('settings.active')}
            </label>
            <FormField label={p('settings.fields.description')} error={form.errors.description} className="sm:col-span-2">
                {({ id, describedBy, invalid }) => (
                    <Textarea id={id} rows={3} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} maxLength={1000}
                        aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
                )}
            </FormField>
        </div>
    );
}

/**
 * The Add Criterion / Edit Criterion dialog. Adding posts to
 * settings.performance-evaluation.criteria.store and comes back to
 * `returnUrl`; editing patches the criterion and comes back to the settings page.
 */
export function CriterionDialog({ open, criterion, categories, returnUrl, onClose }: {
    open: boolean;
    /** The criterion being edited; null to add one. */
    criterion: PerformanceCriterionRow | null;
    categories: PerformanceCategoryOption[];
    returnUrl: string;
    onClose: () => void;
}) {
    const { p } = usePerformanceText();
    const formId = useId();
    const form = useForm<CriterionFormData>(criterionFormData(criterion, categories, returnUrl));

    useEffect(() => {
        if (open) {
            form.setData(criterionFormData(criterion, categories, returnUrl));
            form.clearErrors();
        }
        // The form object is recreated on each render; the dialog only re-seeds when it opens or changes criterion.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, criterion]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (criterion) form.patch(route('court.settings.performance-evaluation.criteria.update', criterion.id), options);
        else form.post(route('court.settings.performance-evaluation.criteria.store'), options);
    };

    return (
        <AppDialog
            open={open}
            onClose={() => { if (!form.processing) onClose(); }}
            maxWidth="2xl"
            title={criterion ? p('settings.edit_criterion_title') : p('settings.create_criterion_title')}
            description={criterion ? undefined : p('settings.create_criterion_subtitle')}
            footer={(
                <>
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>{p('actions.cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" loading={form.processing}>
                        {criterion ? p('settings.actions.update') : p('settings.actions.add_criterion')}
                    </Button>
                </>
            )}
        >
            <form id={formId} onSubmit={submit}>
                <CriterionFields form={form} categories={categories} currentCategoryLabel={criterion?.category_label} />
            </form>
        </AppDialog>
    );
}
