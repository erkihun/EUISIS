import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { Button, Card, FormField, Select, Textarea } from '@euisis/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import type { CaseType } from './MemberForm';

type Props = { organizations: { id: string; name: string }[]; caseTypes: CaseType[]; caseType: CaseType };

/** Designate one of an institution's existing work units to receive Tip-offs or complaints (ADR 0034). */
export default function HandlingUnitsCreate({ organizations, caseTypes, caseType }: Props) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm({ organization_id: organizations.length === 1 ? organizations[0].id : '', case_type: caseType, organization_unit_id: '', description_en: '', description_am: '' });
    const [units, setUnits] = useState<{ id: string; name: string }[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!form.data.organization_id) { setUnits([]); return; }
        const controller = new AbortController();
        setLoading(true);
        fetch(route('service-feedback.handling-units.unit-options', { organization_id: form.data.organization_id, case_type: form.data.case_type }), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body: { data: { id: string; name: string }[] }) => setUnits(body.data))
            .catch(() => undefined)
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [form.data.organization_id, form.data.case_type]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-feedback.handling-units.store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={h('designate')} description={h('designateHint')} backHref={route('service-feedback.handling-units.index', { case_type: form.data.case_type })} />}>
            <Head title={h('designate')} />
            <form onSubmit={submit}>
                <Card className="overflow-hidden">
                    <div className="grid gap-4 p-6">
                        <FormField id="hu-designate-type" label={h('caseType')} description={h('caseTypeHint')} error={form.errors.case_type} required>
                            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.case_type} onChange={(e) => form.setData((d) => ({ ...d, case_type: e.target.value as CaseType, organization_unit_id: '' }))}>
                                {caseTypes.map((type) => <option key={type} value={type}>{h(`caseTypes.${type}`)}</option>)}
                            </Select>}
                        </FormField>
                        <FormField id="hu-designate-org" label={t('serviceFeedback.filterOrganization')} error={form.errors.organization_id} required>
                            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData((d) => ({ ...d, organization_id: e.target.value, organization_unit_id: '' }))}>
                                <option value="">{h('chooseOrganization')}</option>
                                {organizations.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
                            </Select>}
                        </FormField>
                        <FormField id="hu-designate-unit" label={h('workUnit')} description={h('workUnitHint')} error={form.errors.organization_unit_id} required>
                            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_unit_id} disabled={loading || !form.data.organization_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)}>
                                <option value="">{loading ? t('common.loading') : h('chooseUnit')}</option>
                                {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                            </Select>}
                        </FormField>
                        <FormField id="hu-designate-desc-am" label={h('descriptionAm')} error={form.errors.description_am}>
                            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
                        </FormField>
                        <FormField id="hu-designate-desc-en" label={h('descriptionEn')} error={form.errors.description_en}>
                            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
                        </FormField>
                    </div>
                    <div className="flex justify-end gap-2 border-t border-(--app-border) px-6 py-4">
                        <Button as={Link} href={route('service-feedback.handling-units.index', { case_type: form.data.case_type })} variant="outline">{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
