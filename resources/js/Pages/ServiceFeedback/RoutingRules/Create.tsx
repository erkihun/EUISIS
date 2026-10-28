import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import RuleForm, { type OrganizationOption } from './Form';

type Props = { organizations: OrganizationOption[]; canManageCityWide: boolean };

export default function RoutingRulesCreate({ organizations, canManageCityWide }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.addRule')} description={t('serviceFeedback.routingRulesIntro')} backHref={route('service-feedback.routing-rules.index')} />}>
            <Head title={t('serviceFeedback.addRule')} />
            <RuleForm record={null} organizations={organizations} canManageCityWide={canManageCityWide} />
        </AuthenticatedLayout>
    );
}
