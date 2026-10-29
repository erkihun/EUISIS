import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import RuleForm, { type OrganizationOption, type RuleRecord } from './Form';

type Props = {
    rule: RuleRecord & { handler_name: string | null; handler_name_am: string | null };
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
};

export default function RoutingRulesEdit({ rule, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const handler = locale === 'am' && rule.handler_name_am ? rule.handler_name_am : rule.handler_name;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.editRule')} description={handler ?? undefined} backHref={route('service-feedback.routing-rules.index')} />}>
            <Head title={t('serviceFeedback.editRule')} />
            <RuleForm key={rule.id} record={rule} organizations={organizations} canManageCityWide={canManageCityWide} />
        </AuthenticatedLayout>
    );
}
