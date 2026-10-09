import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import ServiceTypeForm from './Form';

export default function ServiceTypesCreate() {
    const { t } = useLocale();
    const form = useForm({
        code: '',
        name_en: '',
        name_am: '',
        description: '',
        is_active: true,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('service-types.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceTypes.createTitle')} />}>
            <Head title={t('serviceTypes.createTitle')} />
            <ServiceTypeForm
                form={form}
                submitLabel={t('common.save')}
                cancelHref={route('service-types.index')}
                onSubmit={submit}
            />
        </AuthenticatedLayout>
    );
}
