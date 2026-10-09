import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    title: string;
    description?: string;
    actions?: ReactNode;
    backHref?: string;
}

export default function PageHeader({ title, description, actions, backHref }: Props) {
    const { t } = useLocale();

    return (
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    {backHref && (
                        <Link
                            href={backHref}
                            className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clipRule="evenodd" />
                            </svg>
                            {t('common.back')}
                        </Link>
                    )}
                    <h1 className="break-words text-page-title text-gray-900 dark:text-slate-100">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-1 break-words text-sm text-gray-500 dark:text-slate-400">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex w-full flex-wrap items-center gap-2 sm:ml-4 sm:w-auto sm:flex-nowrap">{actions}</div>
                )}
        </div>
    );
}
