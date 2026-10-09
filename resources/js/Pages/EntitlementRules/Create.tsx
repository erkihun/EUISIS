import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import EntitlementRuleForm from './Form';

type ServiceTypeOption = { id: string; name_en: string; name_am?: string | null };

export default function EntitlementRulesCreate({
    serviceTypes,
}: {
    serviceTypes: ServiceTypeOption[];
}) {
    const { t } = useLocale();
    const form = useForm({
        service_type_id: '',
        name: '',
        rule_definition: {
            quota_limit: '',
            period_days: '',
            notes: '',
        },
        is_active: true,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('entitlement-rules.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('entitlementRules.createTitle')} />}>
            <Head title={t('entitlementRules.createTitle')} />
            <EntitlementRuleForm
                form={form}
                serviceTypes={serviceTypes}
                submitLabel={t('common.save')}
                cancelHref={route('entitlement-rules.index')}
                onSubmit={submit}
            />
        </AuthenticatedLayout>
    );
}
