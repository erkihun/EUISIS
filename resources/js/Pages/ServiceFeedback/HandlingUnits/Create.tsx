import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { Button, Card, FormField, Select, Textarea } from '@euisis/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { unitRoute, unitsIndexUrl, type CaseType } from './MemberForm';

type Props = { organizations: { id: string; name: string }[]; caseType: CaseType };

/** Designate one of an institution's existing work units to receive Tip-offs or complaints (ADR 0034). */
export default function HandlingUnitsCreate({ organizations, caseType }: Props) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm({ organization_id: organizations.length === 1 ? organizations[0].id : '', case_type: caseType, organization_unit_id: '', description_en: '', description_am: '' });
    const title = h(`byType.${caseType}.designate`);
    const [units, setUnits] = useState<{ id: string; name: string }[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!form.data.organization_id) { setUnits([]); return; }
        const controller = new AbortController();
        setLoading(true);
        fetch(unitRoute(caseType, 'unit-options', { organization_id: form.data.organization_id }), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body: { data: { id: string; name: string }[] }) => setUnits(body.data))
            .catch(() => undefined)
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [form.data.organization_id, form.data.case_type]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(unitRoute(caseType, 'store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={h('designateHint')} backHref={unitsIndexUrl(caseType)} />}>
            <Head title={title} />
            <form onSubmit={submit}>
                <Card className="overflow-hidden">
                    <div className="grid gap-4 p-6">
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
                        <Button as={Link} href={unitsIndexUrl(caseType)} variant="outline">{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
