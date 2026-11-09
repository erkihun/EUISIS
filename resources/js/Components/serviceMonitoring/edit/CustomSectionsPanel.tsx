import { Button } from '@/Components/ui';
import FormRenderer from '@/Components/serviceMonitoring/builder/FormRenderer';
import type { FormSchema, ReportData } from '@/Components/serviceMonitoring/builder/types';
import { useSm } from '@/Components/serviceMonitoring/helpers';
import { router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

/**
 * The form template's additional sections on the entry screen. Answers are
 * saved as a whole; totals are recomputed by the server, and required
 * answers are checked when the report is submitted.
 */
export default function CustomSectionsPanel({ submissionId, schema, data, editable, errors }: {
    submissionId: string;
    schema: FormSchema;
    data: ReportData;
    editable: boolean;
    errors: Record<string, string | undefined>;
}): JSX.Element {
    const { sm } = useSm();
    const [answers, setAnswers] = useState<ReportData>(data);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);

    function save() {
        setSaving(true);
        router.put(route('service-monitoring.submissions.custom', submissionId), { custom: answers }, {
            preserveScroll: true,
            onSuccess: () => setDirty(false),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <div className="space-y-4">
            <FormRenderer schema={schema} data={answers} errors={errors} errorPrefix="custom"
                onChange={editable ? (next) => { setAnswers(next); setDirty(true); } : undefined} />
            {editable && (
                <div className="flex items-center justify-end gap-3">
                    {dirty && <span className="text-xs text-(--app-muted-foreground)">{sm('templates.unsaved')}</span>}
                    <Button type="button" variant="primary" loading={saving} onClick={save}>{sm('templates.actions.saveAnswers')}</Button>
                </div>
            )}
        </div>
    );
}
