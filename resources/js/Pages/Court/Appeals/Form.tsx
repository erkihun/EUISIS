import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, FormField, Input, Select, Textarea, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    /** Null on the create page. */
    appeal: { id: number; appeal_number: string; title: string; grounds: string | null } | null;
    /** Closed cases with a published decision, which a party may appeal (create only). */
    cases: { id: number; case_number: string; title: string | null; respondent_name?: string | null }[];
    /** The case to start from (?case=). */
    caseId?: number | null;
    sides?: string[];
}

/**
 * The registry records a party's request to take a closed case to the appellate
 * court (EUISIS ADR 0013 #14): the case, who appeals and on what grounds. Editing
 * keeps the title and grounds of an older draft.
 */
export default function AppealForm({ appeal, cases, caseId = null, sides = ['applicant', 'respondent'] }: Props) {
    const { t } = useLocale();
    const a = (key: string) => t(`cm.appeal_records.panel.${key}`);
    const editing = appeal !== null;
    const form = useForm({
        court_case_id: caseId ? String(caseId) : '',
        appellant_type: '',
        appellant_name: '',
        title: appeal?.title ?? '',
        grounds: appeal?.grounds ?? '',
    });
    const title = editing ? `${t('court.appeals.editTitle')} · ${appeal.appeal_number}` : a('record_request');
    const back = editing ? route('court.appeals.show', appeal.id) : route('court.appeals.index');
    const chosen = cases.find((item) => String(item.id) === form.data.court_case_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.patch(route('court.appeals.update', appeal.id));
        else form.post(route('court.appeals.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={editing ? undefined : a('help')} backHref={back} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    {!editing && (
                        <>
                            <FormField label={t('court.appeals.case')} required error={form.errors.court_case_id ?? (form.errors as Record<string, string | undefined>).case}>
                                {({ id }) => (
                                    <Select id={id} value={form.data.court_case_id} onChange={(event) => form.setData('court_case_id', event.target.value)} required>
                                        <option value="">{t('court.appeals.chooseCase')}</option>
                                        {cases.map((item) => <option key={item.id} value={item.id}>{item.case_number}{item.title ? ` — ${item.title}` : ''}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            {cases.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{a('not_appealable')}</p>}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField label={a('side')} required error={form.errors.appellant_type}>
                                    {({ id }) => (
                                        <Select id={id} value={form.data.appellant_type} required
                                            onChange={(event) => {
                                                const side = event.target.value;
                                                form.setData((data) => ({ ...data, appellant_type: side, appellant_name: side === 'respondent' && !data.appellant_name ? (chosen?.respondent_name ?? '') : side === 'applicant' && !data.appellant_name ? (chosen?.title ?? '') : data.appellant_name }));
                                            }}>
                                            <option value="">—</option>
                                            {sides.map((side) => <option key={side} value={side}>{t(`cm.appeal_records.sides.${side}`)}</option>)}
                                        </Select>
                                    )}
                                </FormField>
                                <FormField label={a('name')} required error={form.errors.appellant_name}>
                                    {({ id }) => <Input id={id} value={form.data.appellant_name} onChange={(event) => form.setData('appellant_name', event.target.value)} required maxLength={255} />}
                                </FormField>
                            </div>
                        </>
                    )}
                    {editing && (
                        <FormField label={t('court.appeals.title')} required error={form.errors.title}>
                            {({ id }) => <Input id={id} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required maxLength={255} />}
                        </FormField>
                    )}
                    <FormField label={editing ? t('court.appeals.grounds') : a('grounds')} required={!editing} error={form.errors.grounds}>
                        {({ id }) => <Textarea id={id} rows={6} value={form.data.grounds} onChange={(event) => form.setData('grounds', event.target.value)} required={!editing} />}
                    </FormField>
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={back} className={buttonClassName({ variant: 'outline' })}>{t('court.appeals.cancel')}</Link>
                        <Button type="submit" disabled={form.processing}>{editing ? t('court.appeals.save') : a('submit_request')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
