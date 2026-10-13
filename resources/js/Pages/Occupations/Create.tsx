import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName as uiInputClassName, FormLabel } from '@/Components/ui';
type OccupationFormData = {
    isco_code: string;
    name_en: string;
    name_am: string;
    skill_specialization: string;
    description: string;
};

export default function OccupationsCreate() {
    const { t } = useLocale();
    const form = useForm<OccupationFormData>({
        isco_code: '',
        name_en: '',
        name_am: '',
        skill_specialization: '',
        description: '',
    });

    const inputClassName = cx(uiInputClassName, 'w-full');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('occupations.store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('occupations.createOccupation')} />}>
            <Head title={t('occupations.createOccupation')} />

            <form
                className="overflow-hidden rounded-lg border border-[color:var(--app-border)] bg-[color:var(--app-surface)]"
                onSubmit={submit}
            >
                <div className="border-b border-[color:var(--app-border)] px-6 py-4">
                    <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">
                        {t('occupations.occupationDetails')}
                    </h2>
                </div>

                <div className="grid gap-5 p-6 md:grid-cols-2">
                    <FormField error={form.errors.isco_code} label={t('occupations.iscoCode')}>
                        <input
                            id="isco_code"
                            className={inputClassName}
                            maxLength={4}
                            inputMode="numeric"
                            value={form.data.isco_code}
                            placeholder={t('occupations.iscoCodePlaceholder')}
                            onChange={(event) => form.setData('isco_code', event.target.value.replace(/\D/g, ''))}
                        />
                    </FormField>

                    <FormField error={form.errors.skill_specialization} label={t('occupations.skillSpecialization')}>
                        <input
                            id="skill_specialization"
                            className={inputClassName}
                            value={form.data.skill_specialization}
                            onChange={(event) => form.setData('skill_specialization', event.target.value)}
                        />
                    </FormField>

                    <FormField error={form.errors.name_en} label={t('occupations.nameEn')}>
                        <input
                            id="name_en"
                            className={inputClassName}
                            value={form.data.name_en}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                        />
                    </FormField>

                    <FormField error={form.errors.name_am} label={t('occupations.nameAm')}>
                        <input
                            id="name_am"
                            className={inputClassName}
                            value={form.data.name_am}
                            onChange={(event) => form.setData('name_am', event.target.value)}
                        />
                    </FormField>

                    <div className="md:col-span-2">
                        <FormField error={form.errors.description} label={t('occupations.description')}>
                            <textarea
                                id="description"
                                className={`${inputClassName} min-h-32 resize-y`}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                        </FormField>
                    </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-[color:var(--app-border)] px-6 py-4">
                    <Link
                        href={route('occupations.index')}
                        className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                    >
                        {t('common.cancel')}
                    </Link>
                    <button
                        className={buttonClassName({ variant: 'primary' })}
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function FormField({ children, error, label }: { children: ReactNode; error?: string; label: string }) {
    return (
        <div className="space-y-1.5">
            <FormLabel>{label}</FormLabel>
            {children}
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
