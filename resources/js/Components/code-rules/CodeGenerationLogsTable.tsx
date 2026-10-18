import { useLocale } from '@/hooks/useLocale';

import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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
        <div className="overflow-x-auto rounded-panel border border-(--app-border) bg-(--app-surface)">
            <table className={cx(tableClassName, 'min-w-full text-left')}>
                <thead className={tableHeadClassName}>
                    <tr>
                        {[t('codeRules.previewCode'), t('codeRules.sequenceNumber'), t('codeRules.generatedBy'), t('codeRules.generatedAt')].map((heading) => (
                            <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>
                                {heading}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className={tableBodyClassName}>
                    {logs.map((log) => (
                        <tr key={log.id} className={tableRowClassName}>
                            <td className={cx(tableCellClassName, 'font-mono text-xs')}>{log.generated_code}</td>
                            <td className={cx(tableCellClassName, 'tabular-nums')}>{log.sequence_number}</td>
                            <td className={tableCellClassName}>{log.generated_by?.name ?? '—'}</td>
                            <td className={tableCellClassName}>{log.generated_at ? new Date(log.generated_at).toLocaleString() : '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
