import { Card, CardHeader, CardTitle, badgeClassName } from '@/Components/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useDesignText } from './ui';

export type Finding = { severity: 'error' | 'warning' | 'info'; code: string; message: string; subject: { type: string; id: string | null; code: string | null; name: string | null }; params?: Record<string, unknown> };
export type Report = { ok: boolean; summary: { errors: number; warnings: number; infos: number }; findings: Finding[]; generated_at: string };

const TONES = { error: 'danger', warning: 'warning', info: 'info' } as const;

/** The validation gate's report, grouped by severity. Read-only: the server decides. */
export default function ValidationReport({ report, compact = false }: { report: Report; compact?: boolean }) {
    const { tt } = useDesignText();
    const groups = (['error', 'warning', 'info'] as const).map((severity) => ({ severity, items: report.findings.filter((f) => f.severity === severity) })).filter((g) => g.items.length > 0);

    return (
        <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <CardTitle>{tt('validation.title')}</CardTitle>
                    {!compact && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('validation.help')}</p>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span className={badgeClassName(report.ok ? 'success' : 'danger')}>{report.ok ? tt('validation.ok') : tt('validation.blocked')}</span>
                    <span className={badgeClassName('danger')}>{tt('validation.errors')} {report.summary.errors}</span>
                    <span className={badgeClassName('warning')}>{tt('validation.warnings')} {report.summary.warnings}</span>
                    <span className={badgeClassName('info')}>{tt('validation.infos')} {report.summary.infos}</span>
                </div>
            </CardHeader>
            {groups.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('validation.none')}</p> : (
                <div className="divide-y divide-(--app-border)">
                    {groups.map((g) => (
                        <ul key={g.severity} className="space-y-1 px-5 py-3 text-sm">
                            {g.items.map((f, i) => (
                                <li key={`${f.code}-${f.subject.id ?? i}`} className="flex items-start gap-2">
                                    <span className={badgeClassName(TONES[f.severity])}>{tt(`validation.severity.${f.severity}`)}</span>
                                    <span className="min-w-0">{f.message}</span>
                                </li>
                            ))}
                        </ul>
                    ))}
                </div>
            )}
            <p className="border-t border-(--app-border) px-5 py-2 text-xs text-(--app-muted-foreground)">{tt('validation.generatedAt')}: <LocalizedDateDisplay value={report.generated_at} withTime /></p>
        </Card>
    );
}
