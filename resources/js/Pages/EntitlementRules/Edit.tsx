import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import EntitlementRuleForm from './Form';

type ServiceTypeOption = { id: string; name_en: string; name_am?: string | null };

export default function EntitlementRulesEdit({
    rule,
    serviceTypes,
}: {
    rule: {
        id: string;
        service_type_id: string;
        name: string;
        rule_definition: { quota_limit?: number; period_days?: number; notes?: string } | null;
        is_active: boolean;
    };
    serviceTypes: ServiceTypeOption[];
}) {
    const { t } = useLocale();
    const form = useForm({
        service_type_id: rule.service_type_id,
        name: rule.name,
        rule_definition: {
            quota_limit: rule.rule_definition?.quota_limit?.toString() ?? '',
            period_days: rule.rule_definition?.period_days?.toString() ?? '',
            notes: rule.rule_definition?.notes ?? '',
        },
        is_active: rule.is_active,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('entitlement-rules.update', rule.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('entitlementRules.editTitle')} />}>
            <Head title={t('entitlementRules.editTitle')} />
            <EntitlementRuleForm
                form={form}
                serviceTypes={serviceTypes}
                submitLabel={t('common.update')}
                cancelHref={route('entitlement-rules.show', rule.id)}
                onSubmit={submit}
            />
        </AuthenticatedLayout>
    );
}
