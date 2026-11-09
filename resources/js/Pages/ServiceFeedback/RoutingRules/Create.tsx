import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import RuleForm, { type OrganizationOption } from './Form';

type Props = { organizations: OrganizationOption[]; canManageCityWide: boolean; submissionType: 'suggestion' | 'complaint'; routeBase: string };

export default function RoutingRulesCreate({ organizations, canManageCityWide, submissionType, routeBase }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.addRule')} description={t('serviceFeedback.routingRulesIntro')} backHref={route(`${routeBase}.index`)} />}>
            <Head title={t('serviceFeedback.addRule')} />
            <RuleForm record={null} organizations={organizations} canManageCityWide={canManageCityWide} submissionType={submissionType} routeBase={routeBase} />
        </AuthenticatedLayout>
    );
}
