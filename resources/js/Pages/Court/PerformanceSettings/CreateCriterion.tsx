import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, buttonClassName } from '@/Components/ui';
import { usePerformanceText } from '@/court/i18n/usePerformanceText';
import type { PerformanceCreateCriterionProps } from '@/court/types/performance';
import { CriterionFields, criterionFormData, type CriterionFormData } from './Partials/CriterionForm';

/** The Add Criterion page: one scoring criterion for future evaluations; saving returns to the settings page. */
export default function PerformanceCreateCriterion({ categories }: PerformanceCreateCriterionProps) {
    const { p } = usePerformanceText();
    const form = useForm<CriterionFormData>(criterionFormData(null, categories));
    const title = p('settings.create_criterion_title');
    const back = route('court.settings.performance-evaluation.index');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.settings.performance-evaluation.criteria.store'));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={p('settings.create_criterion_subtitle')} backHref={back} />}>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl">
                <Card className="space-y-5">
                    <CriterionFields form={form} categories={categories} />
                    <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                        <Link href={back} className={buttonClassName({ variant: 'outline' })}>{p('actions.cancel')}</Link>
                        <Button type="submit" variant="primary" loading={form.processing}>{p('settings.actions.add_criterion')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
