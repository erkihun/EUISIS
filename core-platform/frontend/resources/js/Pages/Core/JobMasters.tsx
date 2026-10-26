import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { AppTabNav, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { AppDialog, Button, Card, EmptyState, FormField, Input, Textarea } from '@euisis/ui';

type MasterValue = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    needs_review: boolean;
    positions_count: number;
    update_url: string;
};

/**
 * Core › Job masters (ADR 0010): one tab per master. Every value shows how
 * many positions use it; a holder of the job master permission adds and
 * edits values and deactivates one instead of deleting it.
 */
export default function JobMasters({ kind, kinds, values, can_manage, store_url, url }: {
    kind: string;
    kinds: string[];
    values: MasterValue[];
    can_manage: boolean;
    store_url: string;
    url: string;
}) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.jobMasters.${key}`);
    const [editing, setEditing] = useState<MasterValue | 'new' | null>(null);
    const form = useForm({ code: '', name_en: '', name_am: '', description: '', sort_order: 0, is_active: true });

    const open = (value: MasterValue | 'new') => {
        form.clearErrors();
        form.setData(value === 'new'
            ? { code: '', name_en: '', name_am: '', description: '', sort_order: 0, is_active: true }
            : { code: value.code, name_en: value.name_en, name_am: value.name_am ?? '', description: value.description ?? '', sort_order: value.sort_order, is_active: value.is_active });
        setEditing(value);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post(store_url, options);
        else if (editing) form.patch(editing.update_url, options);
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={can_manage ? <Button variant="primary" onClick={() => open('new')}>{label('add')}</Button> : undefined} />}>
            <Head title={label('title')} />

            <div className="space-y-5">
                <AppTabNav label={label('tabsLabel')} current={kind} items={kinds.map((tab) => ({ key: tab, label: label(`kinds.${tab}`), href: url, data: { kind: tab } }))} />

                {!can_manage && <p className="text-sm text-(--app-muted-foreground)">{label('readOnly')}</p>}

                <Card className="overflow-hidden p-0">
                    {values.length === 0 ? <EmptyState title={label('empty')} /> : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{label('code')}</Th>
                                    <Th>{label(locale === 'am' ? 'nameAm' : 'nameEn')}</Th>
                                    <Th align="right">{label('sortOrder')}</Th>
                                    <Th align="right">{label('positions')}</Th>
                                    <Th>{label('status')}</Th>
                                    {can_manage && <Th><span className="sr-only">{label('edit')}</span></Th>}
                                </Tr>
                            </THead>
                            <TBody>
                                {values.map((value) => (
                                    <Tr key={value.id}>
                                        <Td className="font-mono">{value.code}</Td>
                                        <Td>
                                            {locale === 'am' && value.name_am ? value.name_am : value.name_en}
                                            {value.needs_review && <span className="ml-2" title={label('needsReviewHint')}><StatusBadge status="warning" label={label('needsReview')} /></span>}
                                        </Td>
                                        <Td numeric>{value.sort_order}</Td>
                                        <Td numeric>{value.positions_count}</Td>
                                        <Td><StatusBadge status={value.is_active ? 'active' : 'inactive'} label={label(value.is_active ? 'active' : 'inactive')} /></Td>
                                        {can_manage && <Td align="right"><Button size="sm" variant="outline" onClick={() => open(value)}>{label('edit')}</Button></Td>}
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            <AppDialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={editing === 'new' ? label('add') : label('edit')}
                description={label(`kinds.${kind}`)}
                footer={(
                    <>
                        <Button variant="outline" onClick={() => setEditing(null)} disabled={form.processing}>{label('cancel')}</Button>
                        <Button variant="primary" type="submit" form="job-master-form" loading={form.processing}>{label('save')}</Button>
                    </>
                )}
            >
                <form id="job-master-form" onSubmit={submit} className="space-y-4">
                    <FormField label={label('code')} required error={form.errors.code}>
                        {({ id }) => <Input id={id} maxLength={64} value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} />}
                    </FormField>
                    <FormField label={label('nameEn')} required error={form.errors.name_en}>
                        {({ id }) => <Input id={id} maxLength={255} value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} />}
                    </FormField>
                    <FormField label={label('nameAm')} error={form.errors.name_am}>
                        {({ id }) => <Input id={id} maxLength={255} value={form.data.name_am} onChange={(event) => form.setData('name_am', event.target.value)} />}
                    </FormField>
                    <FormField label={label('descriptionField')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>
                    <FormField label={label('sortOrder')} error={form.errors.sort_order}>
                        {({ id }) => <Input id={id} type="number" min={0} max={100000} value={form.data.sort_order} onChange={(event) => form.setData('sort_order', Number(event.target.value))} />}
                    </FormField>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                        {label('active')}
                    </label>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
