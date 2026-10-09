import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import ServiceTypeForm from './Form';

export default function ServiceTypesEdit({
    serviceType,
}: {
    serviceType: {
        id: string;
        code: string;
        name_en: string;
        name_am: string | null;
        description: string | null;
        is_active: boolean;
    };
}) {
    const { t } = useLocale();
    const form = useForm({
        code: serviceType.code,
        name_en: serviceType.name_en,
        name_am: serviceType.name_am ?? '',
        description: serviceType.description ?? '',
        is_active: serviceType.is_active,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('service-types.update', serviceType.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceTypes.editTitle')} />}>
            <Head title={t('serviceTypes.editTitle')} />
            <ServiceTypeForm
                form={form}
                submitLabel={t('common.update')}
                cancelHref={route('service-types.show', serviceType.id)}
                onSubmit={submit}
                existingCode={serviceType.code}
            />
        </AuthenticatedLayout>
    );
}
