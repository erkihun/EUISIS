import { useLocale } from '@/hooks/useLocale';

type CodeGenerationLog = {
    id: string;
    generated_code: string;
    sequence_number: number;
    generated_at: string | null;
    generated_by: { id: string; name: string } | null;
};

export default function CodeGenerationLogsTable({ logs }: { logs: CodeGenerationLog[] }) {
    const { t } = useLocale();

    return (
        <div className="overflow-x-auto rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-gray-50 dark:bg-slate-950">
                    <tr>
                        {[t('codeRules.previewCode'), t('codeRules.sequenceNumber'), t('codeRules.generatedBy'), t('codeRules.generatedAt')].map((heading) => (
                            <th key={heading} className="px-4 py-3 text-xs font-semibold text-gray-500 dark:text-slate-400">
                                {heading}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {logs.map((log) => (
                        <tr key={log.id} className="border-t border-gray-100 dark:border-slate-800">
                            <td className="px-4 py-3 font-mono text-xs">{log.generated_code}</td>
                            <td className="px-4 py-3 tabular-nums">{log.sequence_number}</td>
                            <td className="px-4 py-3">{log.generated_by?.name ?? '—'}</td>
                            <td className="px-4 py-3">{log.generated_at ? new Date(log.generated_at).toLocaleString() : '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
