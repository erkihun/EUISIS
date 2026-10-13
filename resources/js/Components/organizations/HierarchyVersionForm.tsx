import { Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-xs font-medium text-[color:var(--app-muted-foreground)]';

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className={labelCls}>{label}</label>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

type FormShape = {
    version_name: string;
    effective_from: string;
    effective_to: string;
    source_document: string;
    notes: string;
};

export default function HierarchyVersionForm({
    mode,
    submitRoute,
    initialValues,
    readonly = false,
}: {
    mode: 'create' | 'edit';
    submitRoute: string;
    initialValues: FormShape;
    readonly?: boolean;
}) {
    const { t } = useLocale();
    const form = useForm<FormShape>(initialValues);

    function submit(event: React.FormEvent) {
        event.preventDefault();

        if (readonly) {
            return;
        }

        if (mode === 'create') {
            form.post(submitRoute);

            return;
        }

        form.patch(submitRoute);
    }

    return (
        <form
            onSubmit={submit}
            className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6"
        >
            <div className="grid gap-4 md:grid-cols-2">
                <Field label={t('hierarchyVersions.versionName')} error={form.errors.version_name}>
                    <input
                        className={inputCls}
                        disabled={readonly}
                        value={form.data.version_name}
                        onChange={(event) => form.setData('version_name', event.target.value)}
                    />
                </Field>

                <Field label={t('hierarchyVersions.sourceDocument')} error={form.errors.source_document}>
                    <input
                        className={inputCls}
                        disabled={readonly}
                        value={form.data.source_document}
                        onChange={(event) => form.setData('source_document', event.target.value)}
                    />
                </Field>

                <Field label={t('hierarchyVersions.effectiveFrom')} error={form.errors.effective_from}>
                    <LocalizedDatePicker
                        className={inputCls}
                        disabled={readonly}
                        value={form.data.effective_from}
                        onChange={(iso) => form.setData('effective_from', iso)}
                    />
                </Field>

                <Field label={t('hierarchyVersions.effectiveTo')} error={form.errors.effective_to}>
                    <LocalizedDatePicker
                        className={inputCls}
                        disabled={readonly}
                        value={form.data.effective_to}
                        onChange={(iso) => form.setData('effective_to', iso)}
                    />
                </Field>
            </div>

            <div className="mt-4">
                <Field label={t('hierarchyVersions.notes')} error={form.errors.notes}>
                    <textarea
                        rows={5}
                        className={inputCls}
                        disabled={readonly}
                        value={form.data.notes}
                        onChange={(event) => form.setData('notes', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-6 flex items-center justify-end gap-3 border-t border-[color:var(--app-border)] pt-5">
                <Link
                    href={route('hierarchy-versions.index')}
                    className="rounded-lg px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                >
                    {t('common.cancel')}
                </Link>
                {!readonly && (
                    <button
                        type="submit"
                        disabled={form.processing}
                        className={buttonClassName({ variant: 'primary' })}
                    >
                        {form.processing
                            ? t('common.saving')
                            : mode === 'create'
                                ? t('hierarchyVersions.createHierarchyVersion')
                                : t('common.save')}
                    </button>
                )}
            </div>
        </form>
    );
}
