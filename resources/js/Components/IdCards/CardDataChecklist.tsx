import { useLocale } from '@/hooks/useLocale';

type EmployeeData = {
    employee_number?: string | null;
    full_name?: string | null;
    photo_path?: string | null;
    status?: string | null;
    current_assignment?: {
        organization?: { name_en: string } | null;
        position?: { title_en: string } | null;
    } | null;
};

type CheckItem = {
    key: string;
    label: string;
    pass: boolean;
};

function CheckRow({ label, pass }: { label: string; pass: boolean }) {
    return (
        <li className="flex items-center gap-2 text-sm">
            {pass ? (
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                    ✓
                </span>
            ) : (
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                    ✗
                </span>
            )}
            <span className={pass ? 'text-gray-700 dark:text-slate-300' : 'text-red-600 dark:text-red-400'}>
                {label}
            </span>
        </li>
    );
}

export default function CardDataChecklist({ employee }: { employee: EmployeeData }) {
    const { t } = useLocale();

    const checks: CheckItem[] = [
        { key: 'checkEmployeeNumber', label: t('idCards.checkEmployeeNumber'), pass: !!employee.employee_number },
        { key: 'checkNameEn', label: t('idCards.checkNameEn'), pass: !!employee.full_name },
        { key: 'checkOrganization', label: t('idCards.checkOrganization'), pass: !!employee.current_assignment?.organization },
        { key: 'checkActiveStatus', label: t('idCards.checkActiveStatus'), pass: employee.status === 'active' },
        { key: 'checkNoActiveDuplicate', label: t('idCards.checkNoActiveDuplicate'), pass: true },
    ];

    const allPass = checks.every((c) => c.pass);

    return (
        <div className="rounded-card border border-gray-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            <h4 className="mb-3 text-sm font-semibold text-gray-700 dark:text-slate-300">
                {t('idCards.requiredDataChecklist')}
            </h4>
            <ul className="space-y-2">
                {checks.map((c) => (
                    <CheckRow key={c.key} label={c.label} pass={c.pass} />
                ))}
            </ul>
            <div className={`mt-4 rounded-lg px-3 py-2 text-sm font-medium ${allPass ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'}`}>
                {allPass ? t('idCards.allChecksPass') : t('idCards.checksFailWarning')}
            </div>
        </div>
    );
}
