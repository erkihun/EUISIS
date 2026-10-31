import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cx, type Tone } from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';

/** The tone of each request status, as the workflow reads it. */
export const REQUEST_STATUS_TONES: Record<string, Tone> = {
    pending: 'warning', in_progress: 'info', completed: 'success', cancelled: 'neutral',
};

/** The tone of each finding severity. */
export const SEVERITY_TONES: Record<string, Tone> = {
    low: 'neutral', medium: 'info', high: 'warning', critical: 'danger',
};

/** "CASE-123 - Title", as the Blade pages labelled a case in a select. */
export function caseLabel(caseNumber: string | null | undefined, title: string | null | undefined): string {
    return [caseNumber, title].filter((part) => part !== null && part !== undefined && part !== '').join(' - ');
}

/** The two inspection lists side by side: requests and findings. The viewer sees only the ones they may open. */
export function InspectionTabs({ active, canRequests, canFindings }: { active: 'requests' | 'findings'; canRequests: boolean; canFindings: boolean }) {
    const { t } = useInspectionPagesText();
    const tabs = [
        { id: 'requests' as const, show: canRequests, href: route('court.case-inspection-requests.index'), label: t('cm.case_inspections.requests.nav') },
        { id: 'findings' as const, show: canFindings, href: route('court.case-inspection-findings.index'), label: t('cm.case_inspections.findings.nav') },
    ].filter((tab) => tab.show);

    if (tabs.length < 2) return null;

    return (
        <nav aria-label={t('cm.case_inspections.title')} className="flex gap-1 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-1 w-fit">
            {tabs.map((tab) => (
                <Link key={tab.id} href={tab.href} aria-current={active === tab.id ? 'page' : undefined}
                    className={cx('rounded-control px-3 py-1.5 text-sm font-medium transition-colors',
                        active === tab.id ? 'bg-(--app-surface) text-(--app-foreground) shadow-sm' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}

/** A "label: value" line of a detail grid, as the Blade show pages laid them out. */
export function DetailLine({ label, children }: { label: ReactNode; children: ReactNode }) {
    return (
        <div className="text-sm">
            <span className="text-(--app-muted-foreground)">{label}:</span>{' '}
            <span className="text-(--app-foreground)">{children}</span>
        </div>
    );
}

/** A block of plain text with its line breaks, as the Blade pages showed a note (`nl2br(e(...))`). */
export function PlainTextBlock({ text, empty }: { text: string | null | undefined; empty: string }) {
    return (
        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm whitespace-pre-wrap text-(--app-foreground)">
            {text && text.trim() !== '' ? text : <span className="text-(--app-muted-foreground)">{empty}</span>}
        </div>
    );
}
