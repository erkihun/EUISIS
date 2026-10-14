import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, FormField, Input, SearchableSelect, Select, Textarea, buttonClassName } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { SrLabels } from '@/types/situationRoom';
import type { SituationActionDetail, SituationActionOptions } from './types';

type Props = {
    labels: SrLabels;
    action: SituationActionDetail | null;
    prefill: { organization_id?: string; category?: string; panel_key?: string };
    options: SituationActionOptions;
};

type FormData = {
    organization_id: string;
    category: string;
    panel_key: string;
    title: string;
    description: string;
    priority: string;
    responsible_office: string;
    due_date: string;
    status: string;
    outcome_note: string;
};

/**
 * Record or update an executive action — on its own page, never in a dialog.
 * The organization is chosen once (only from the viewer's scope) and cannot be
 * moved later; a Done or Cancelled action needs an outcome.
 */
export default function SituationActionForm({ action, prefill, options }: Props) {
    const { sr, label } = useSituationLabels();
    const editing = action !== null;
    const form = useForm<FormData>({
        organization_id: action?.organization_id ?? prefill.organization_id ?? '',
        category: action?.category ?? prefill.category ?? 'overview',
        panel_key: action?.panel_key ?? prefill.panel_key ?? '',
        title: action?.title ?? '',
        description: action?.description ?? '',
        priority: action?.priority ?? 'normal',
        responsible_office: action?.responsible_office ?? '',
        due_date: action?.due_date ?? '',
        status: action?.status ?? 'open',
        outcome_note: action?.outcome_note ?? '',
    });
    const { data, errors, processing } = form;
    // Every field is a string, so one setter serves them all; a fixed field clears its error.
    const set = (key: keyof FormData) => (value: string) => {
        form.setData((current) => ({ ...current, [key]: value }));
        form.clearErrors(key);
    };
    const needsOutcome = data.status === 'done' || data.status === 'cancelled';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ organization_id, status, ...rest }) => (editing ? { ...rest, status } : { ...rest, organization_id }));
        if (editing && action) form.put(route('situation-room.actions.update', action.id));
        else form.post(route('situation-room.actions.store'));
    };

    const title = sr(editing ? 'actions.edit' : 'actions.new');

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={editing && action ? action.reference_number : sr('actions.intro')} backHref={route('situation-room.actions.index')} />}>
            <Head title={`${title} · ${sr('actions.title')}`} />

            <Card className="p-4 sm:p-6">
                <form onSubmit={submit} className="space-y-5" noValidate>
                    {editing && action ? (
                        <FormField label={sr('actions.fields.organization_id')}>
                            <p className="text-sm text-[color:var(--app-foreground)]">{label(action.organization)}</p>
                        </FormField>
                    ) : (
                        <FormField id="sa-organization" label={sr('actions.fields.organization_id')} required description={sr('actions.hints.organization_id')} error={errors.organization_id}>
                            <SearchableSelect
                                id="sa-organization"
                                label={sr('actions.fields.organization_id')}
                                value={data.organization_id}
                                options={options.organizations.map((o) => ({ value: o.id, label: label(o.label) }))}
                                onChange={(value) => set('organization_id')(value ?? '')}
                            />
                        </FormField>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField id="sa-category" label={sr('actions.fields.category')} required error={errors.category}>
                            <Select id="sa-category" value={data.category} onChange={(e) => set('category')(e.target.value)}>
                                {options.categories.map((c) => <option key={c} value={c}>{sr(`categories.${c}.title`)}</option>)}
                            </Select>
                        </FormField>
                        <FormField id="sa-priority" label={sr('actions.fields.priority')} required error={errors.priority}>
                            <Select id="sa-priority" value={data.priority} onChange={(e) => set('priority')(e.target.value)}>
                                {options.priorities.map((p) => <option key={p} value={p}>{sr(`values.priority.${p}`)}</option>)}
                            </Select>
                        </FormField>
                    </div>

                    {data.panel_key && (
                        <FormField label={sr('actions.fields.panel_key')}>
                            <p className="text-sm text-[color:var(--app-muted-foreground)]">{sr(`panels.${data.panel_key}.title`)}</p>
                        </FormField>
                    )}

                    <FormField id="sa-title" label={sr('actions.fields.title')} required error={errors.title}>
                        <Input id="sa-title" value={data.title} maxLength={200} onChange={(e) => set('title')(e.target.value)} required />
                    </FormField>

                    <FormField id="sa-description" label={sr('actions.fields.description')} error={errors.description}>
                        <Textarea id="sa-description" value={data.description} maxLength={5000} rows={5} onChange={(e) => set('description')(e.target.value)} />
                    </FormField>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField id="sa-office" label={sr('actions.fields.responsible_office')} error={errors.responsible_office}>
                            <Input id="sa-office" value={data.responsible_office} maxLength={200} onChange={(e) => set('responsible_office')(e.target.value)} />
                        </FormField>
                        <FormField id="sa-due" label={sr('actions.fields.due_date')} error={errors.due_date}>
                            <LocalizedDatePicker id="sa-due" value={data.due_date} onChange={set('due_date')} />
                        </FormField>
                    </div>

                    {editing && (
                        <>
                            <FormField id="sa-status" label={sr('actions.fields.status')} required error={errors.status}>
                                <Select id="sa-status" value={data.status} onChange={(e) => set('status')(e.target.value)}>
                                    {options.statuses.map((s) => <option key={s} value={s}>{sr(`values.action_status.${s}`)}</option>)}
                                </Select>
                            </FormField>
                            <FormField id="sa-outcome" label={sr('actions.fields.outcome_note')} required={needsOutcome} description={sr('actions.hints.outcome_note')} error={errors.outcome_note}>
                                <Textarea id="sa-outcome" value={data.outcome_note} maxLength={5000} rows={4} onChange={(e) => set('outcome_note')(e.target.value)} />
                            </FormField>
                            {action && (
                                <dl className="grid grid-cols-1 gap-2 text-xs text-[color:var(--app-muted-foreground)] sm:grid-cols-3">
                                    <div><dt>{sr('actions.created_by')}</dt><dd className="text-[color:var(--app-foreground)]">{action.created_by ?? '—'}</dd></div>
                                    <div><dt>{sr('actions.updated_by')}</dt><dd className="text-[color:var(--app-foreground)]">{action.updated_by ?? '—'}</dd></div>
                                    <div><dt>{sr('actions.completed_at')}</dt><dd className="text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={action.completed_at} withTime /></dd></div>
                                </dl>
                            )}
                        </>
                    )}

                    <div className="flex flex-wrap gap-2 border-t border-[color:var(--app-border)] pt-4">
                        <Button type="submit" variant="primary" disabled={processing}>{sr('actions.save')}</Button>
                        <Link href={route('situation-room.actions.index')} className={buttonClassName({ variant: 'ghost' })}>{sr('actions.cancel')}</Link>
                    </div>
                </form>
            </Card>
        </AuthenticatedLayout>
    );
}
