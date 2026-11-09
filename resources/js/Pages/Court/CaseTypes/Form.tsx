import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, FormField, Input, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Null on the create page. */
    type: { id: number; name: string; prefix: string | null } | null;
    /** The case number prefix column exists on this installation. */
    hasPrefix: boolean;
}

/** One form for a new and an existing case type: name and, where the column exists, the case number prefix. */
export default function CaseTypeForm({ type, hasPrefix }: Props) {
    const { t } = useLocale();
    const editing = type !== null;
    const form = useForm({ name: type?.name ?? '', prefix: type?.prefix ?? '' });
    const title = editing ? `${t('cm.json.Edit')} · ${type.name}` : t('cm.json.Add Case Type');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.patch(route('court.case-types.update', type.id));
        else form.post(route('court.case-types.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} backHref={route('court.case-types.index')} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-xl">
                <Card className="space-y-5">
                    <FormField label={t('cm.json.Name')} required error={form.errors.name}>
                        {({ id }) => <Input id={id} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required maxLength={100} />}
                    </FormField>
                    {hasPrefix && (
                        <FormField label={t('cm.json.Prefix')} error={form.errors.prefix}>
                            {({ id }) => <Input id={id} value={form.data.prefix} onChange={(event) => form.setData('prefix', event.target.value)} maxLength={16} className="font-mono" />}
                        </FormField>
                    )}
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={route('court.case-types.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.json.Cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{t('cm.json.Save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
