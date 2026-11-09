import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName, FormLabel } from '@/Components/ui';
export default function GradeLevelsCreate() {
    const { t } = useLocale();
    const form = useForm({
        name: '',
    });

    const inputCls = cx(inputClassName, 'w-full');
    const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('grade-levels.store'));
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('gradeLevels.createGradeLevel')} />}>
            <Head title={t('gradeLevels.createGradeLevel')} />
            <form
                className="space-y-6 rounded-panel border border-(--app-border) bg-(--app-surface) p-6"
                onSubmit={submit}
            >
                <div className="max-w-sm space-y-1.5">
                    <FormLabel htmlFor="name">{t('gradeLevels.name')}</FormLabel>
                    <input
                        id="name"
                        className={inputCls}
                        value={form.data.name}
                        placeholder={t('gradeLevels.name')}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                    {form.errors.name && <p className={errorCls}>{form.errors.name}</p>}
                </div>

                <div className="flex gap-3">
                    <button
                        className={buttonClassName({ variant: 'primary' })}
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={route('grade-levels.index')}
                        className="rounded-lg border border-(--app-border-strong) px-4 py-2 text-sm font-medium text-(--app-foreground)"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
