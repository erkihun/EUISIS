import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
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
        <AuthenticatedLayout header={<PageHeader title={t('gradeLevels.createGradeLevel')} />}>
            <Head title={t('gradeLevels.createGradeLevel')} />
            <form
                className="space-y-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6"
                onSubmit={submit}
            >
                <div className="max-w-sm space-y-1.5">
                    <InputLabel htmlFor="name" value={t('gradeLevels.name')} />
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
                        className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)]"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
