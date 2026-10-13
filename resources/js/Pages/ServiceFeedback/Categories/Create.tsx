import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import CategoryForm, { type OrganizationOption } from './Form';

type Props = { organizations: OrganizationOption[]; canManageCityWide: boolean };

export default function CategoriesCreate({ organizations, canManageCityWide }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.addCategory')} description={t('serviceFeedback.categoriesIntro')} backHref={route('service-feedback.categories.index')} />}>
            <Head title={t('serviceFeedback.addCategory')} />
            <CategoryForm record={null} organizations={organizations} canManageCityWide={canManageCityWide} />
        </AuthenticatedLayout>
    );
}
