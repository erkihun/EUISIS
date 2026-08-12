import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

const textareaCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500';

const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';

export default function CreatePermission({ groups }: { groups: string[] }) {
    const { t } = useLocale();

    const form = useForm<{
        name: string;
        label_en: string;
        label_am: string;
        description_en: string;
        description_am: string;
        group: string;
        sort_order: string;
    }>({
        name: '',
        label_en: '',
        label_am: '',
        description_en: '',
        description_am: '',
        group: '',
        sort_order: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(route('permissions.store'));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('permissions.createPermission')}
                    description={t('permissions.createDescription')}
                />
            }
        >
            <Head title={t('permissions.createPermission')} />

            <div className="mx-auto max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('permissions.basicInformation')}
                        </h2>
                        <div className="space-y-4">
                            <div>
                                <label className={labelCls}>{t('permissions.permissionKey')}</label>
                                <div className="mt-1">
                                    <input
                                        className={inputCls}
                                        placeholder="e.g. resources.action"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                    />
                                </div>
                                {form.errors.name && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.name}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className={labelCls}>{t('permissions.labelEn')}</label>
                                    <div className="mt-1">
                                        <input
                                            className={inputCls}
                                            value={form.data.label_en}
                                            onChange={(e) => form.setData('label_en', e.target.value)}
                                        />
                                    </div>
                                    {form.errors.label_en && (
                                        <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.label_en}</p>
                                    )}
                                </div>
                                <div>
                                    <label className={labelCls}>{t('permissions.labelAm')}</label>
                                    <div className="mt-1">
                                        <input
                                            className={inputCls}
                                            value={form.data.label_am}
                                            onChange={(e) => form.setData('label_am', e.target.value)}
                                            dir="auto"
                                        />
                                    </div>
                                    {form.errors.label_am && (
                                        <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.label_am}</p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <label className={labelCls}>{t('permissions.descriptionEn')}</label>
                                <div className="mt-1">
                                    <textarea
                                        className={textareaCls}
                                        rows={3}
                                        value={form.data.description_en}
                                        onChange={(e) => form.setData('description_en', e.target.value)}
                                    />
                                </div>
                                {form.errors.description_en && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.description_en}</p>
                                )}
                            </div>

                            <div>
                                <label className={labelCls}>{t('permissions.descriptionAm')}</label>
                                <div className="mt-1">
                                    <textarea
                                        className={textareaCls}
                                        rows={3}
                                        dir="auto"
                                        value={form.data.description_am}
                                        onChange={(e) => form.setData('description_am', e.target.value)}
                                    />
                                </div>
                                {form.errors.description_am && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.description_am}</p>
                                )}
                            </div>

                        </div>
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('permissions.classification')}
                        </h2>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('permissions.permissionGroup')}</label>
                                <div className="mt-1">
                                    <input
                                        list="group-suggestions"
                                        className={inputCls}
                                        value={form.data.group}
                                        onChange={(e) => form.setData('group', e.target.value)}
                                        placeholder="e.g. organizations"
                                    />
                                    <datalist id="group-suggestions">
                                        {groups.map((g) => <option key={g} value={g} />)}
                                    </datalist>
                                </div>
                                {form.errors.group && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.group}</p>
                                )}
                            </div>
                            <div>
                                <label className={labelCls}>{t('permissions.sortOrder')}</label>
                                <div className="mt-1">
                                    <input
                                        type="number"
                                        className={inputCls}
                                        value={form.data.sort_order}
                                        onChange={(e) => form.setData('sort_order', e.target.value)}
                                        placeholder="0"
                                    />
                                </div>
                                {form.errors.sort_order && (
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.sort_order}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-3">
                        <Link
                            href={route('permissions.index')}
                            className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            {t('common.cancel')}
                        </Link>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
                        >
                            {form.processing ? t('common.saving') : t('permissions.createPermission')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
