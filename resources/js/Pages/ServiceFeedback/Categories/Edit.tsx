import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import CategoryForm, { type CategoryRecord, type OrganizationOption } from './Form';

type Props = { category: CategoryRecord; organizations: OrganizationOption[]; canManageCityWide: boolean };

export default function CategoriesEdit({ category, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const name = locale === 'am' && category.name_am ? category.name_am : category.name_en;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.editCategory')} description={name} backHref={route('service-feedback.categories.index')} />}>
            <Head title={t('serviceFeedback.editCategory')} />
            <CategoryForm key={category.id} record={category} organizations={organizations} canManageCityWide={canManageCityWide} />
        </AuthenticatedLayout>
    );
}
