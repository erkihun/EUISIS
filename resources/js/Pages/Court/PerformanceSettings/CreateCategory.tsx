import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, buttonClassName } from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import { CategoryFields, categoryFormData, type CategoryFormData } from './Partials/CategoryForm';

/** The Add Category page: a reusable category for grouping criteria; saving returns to the settings page. */
export default function PerformanceCreateCategory() {
    const { p } = usePerformanceText();
    const form = useForm<CategoryFormData>(categoryFormData());
    const title = p('settings.create_category_title');
    const back = route('court.settings.performance-evaluation.index');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.settings.performance-evaluation.categories.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={p('settings.create_category_subtitle')} backHref={back} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-2xl">
                <Card className="space-y-5">
                    <CategoryFields form={form} />
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={back} className={buttonClassName({ variant: 'outline' })}>{p('actions.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{p('settings.actions.add_category')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
