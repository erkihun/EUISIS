import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    gradeLevel: { id: string; name: string };
}

export default function GradeLevelsEdit({ gradeLevel }: Props) {
    const { t } = useLocale();
    const form = useForm({
        name: gradeLevel.name ?? '',
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
    const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch(route('grade-levels.update', gradeLevel.id));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('gradeLevels.editGradeLevel')} />}>
            <Head title={t('gradeLevels.editGradeLevel')} />
            <form
                className="space-y-6 rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
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
                        className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={route('grade-levels.show', gradeLevel.id)}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
