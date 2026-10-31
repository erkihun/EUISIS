import type { ReactNode } from 'react';
import { cx } from '@/Components/ui';
import type { CaseDetailPermissions, CaseSection } from '@/court/types/caseDetail';
import { useCaseText } from './useCaseText';
import { AuditIcon, CalendarIcon, ChatIcon, ClockIcon, DetailsIcon, FilesIcon, MailIcon, RepliesIcon, ResponsesIcon, SummaryIcon, UsersIcon } from './icons';

interface Props {
    active: CaseSection;
    can: CaseDetailPermissions;
    onOpen: (section: CaseSection) => void;
}

interface NavItem {
    id: CaseSection;
    label: string;
    icon: ReactNode;
    show: boolean;
}

/** The sections a viewer may open, in the Blade sidebar's order: main sections, then the quick sections. */
export function useSectionItems(can: CaseDetailPermissions): { main: NavItem[]; quick: NavItem[] } {
    const { t, text } = useCaseText();
    const anyFiles = can.viewFiles || can.createFiles || can.updateFiles || can.deleteFiles;
    const anyHearings = can.viewHearings || can.createHearings || can.updateHearings || can.deleteHearings;

    const main: NavItem[] = [
        { id: 'case-summary', label: t('cm.cases.navigation.case_summary'), icon: <SummaryIcon />, show: true },
        { id: 'case-details', label: t('cm.cases.navigation.case_details'), icon: <DetailsIcon />, show: true },
        { id: 'parties', label: t('cm.case_parties.panel.nav'), icon: <UsersIcon />, show: true },
        { id: 'letters', label: t('cm.cases.navigation.letters'), icon: <MailIcon />, show: true },
        { id: 'timeline', label: text('timeline_title'), icon: <ClockIcon />, show: true },
        { id: 'audits', label: t('cm.cases.navigation.case_audits'), icon: <AuditIcon />, show: true },
    ];
    const quick: NavItem[] = [
        { id: 'inspection-requests', label: t('cm.case_inspections.requests.index_title'), icon: <DetailsIcon />, show: can.manageInspectionRequests },
        { id: 'inspection-findings', label: t('cm.case_inspections.findings.index_title'), icon: <DetailsIcon />, show: can.manageInspectionFindings },
        { id: 'uploaded-files', label: t('cm.cases.navigation.uploaded_files'), icon: <FilesIcon />, show: anyFiles },
        { id: 'hearings', label: t('cm.cases.navigation.hearings'), icon: <CalendarIcon />, show: anyHearings },
        { id: 'messages', label: t('cm.cases.navigation.messages'), icon: <ChatIcon />, show: true },
        { id: 'respondent-responses', label: t('cm.cases.navigation.respondent_responses'), icon: <ResponsesIcon />, show: true },
        { id: 'response-of-response', label: t('cm.cases.navigation.response_of_response'), icon: <RepliesIcon />, show: can.manageResponseReplies },
    ];

    return { main: main.filter((item) => item.show), quick: quick.filter((item) => item.show) };
}

/** The sticky section navigation at the left of the page. */
export default function SectionNav({ active, can, onOpen }: Props) {
    const { t } = useCaseText();
    const { main, quick } = useSectionItems(can);

    const button = (item: NavItem) => (
        <li key={item.id}>
            <button
                type="button"
                onClick={() => onOpen(item.id)}
                aria-current={active === item.id ? 'true' : undefined}
                className={cx(
                    'flex w-full items-center gap-2.5 rounded-control px-3 py-2.5 text-left text-sm leading-5 transition-colors',
                    active === item.id
                        ? 'bg-primary/15 font-semibold text-(--app-foreground)'
                        : 'text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)',
                )}
            >
                <span className="shrink-0 text-primary" aria-hidden="true">{item.icon}</span>
                <span>{item.label}</span>
            </button>
        </li>
    );

    return (
        <nav aria-label={t('cm.cases.navigation.title')} className="sticky top-6 overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) print:hidden">
            <div className="border-b border-(--app-border) bg-(--app-surface-muted) px-4 py-3">
                <span className="text-[10.5px] font-semibold uppercase tracking-[0.14em] text-(--app-muted-foreground)">{t('cm.cases.navigation.title')}</span>
            </div>
            <div className="p-2">
                <ul className="space-y-0.5">{main.map(button)}</ul>
                {quick.length > 0 && (
                    <div className="mt-2 border-t border-(--app-border) pt-3">
                        <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.1em] text-(--app-muted-foreground)">{t('cm.cases.show.quick_sections')}</p>
                        <ul className="space-y-0.5">{quick.map(button)}</ul>
                    </div>
                )}
            </div>
        </nav>
    );
}

/** The quick-access chip row under the header card. */
export function QuickAccessBar({ can, onOpen }: Omit<Props, 'active'>) {
    const { t } = useCaseText();
    const anyFiles = can.viewFiles || can.createFiles || can.updateFiles || can.deleteFiles;
    const anyHearings = can.viewHearings || can.createHearings || can.updateHearings || can.deleteHearings;
    const chips: NavItem[] = [
        { id: 'case-summary', label: t('cm.cases.navigation.case_summary'), icon: <SummaryIcon className="h-3.5 w-3.5" />, show: true },
        { id: 'case-details', label: t('cm.cases.navigation.case_details'), icon: <DetailsIcon className="h-3.5 w-3.5" />, show: true },
        { id: 'uploaded-files', label: t('cm.cases.navigation.uploaded_files'), icon: <FilesIcon className="h-3.5 w-3.5" />, show: anyFiles },
        { id: 'hearings', label: t('cm.cases.navigation.hearings'), icon: <CalendarIcon className="h-3.5 w-3.5" />, show: anyHearings },
        { id: 'messages', label: t('cm.cases.navigation.messages'), icon: <ChatIcon className="h-3.5 w-3.5" />, show: true },
        { id: 'letters', label: t('cm.cases.navigation.letters'), icon: <MailIcon className="h-3.5 w-3.5" />, show: true },
    ];

    return (
        <div className="flex flex-wrap items-center gap-2 print:hidden">
            <span className="mr-1 text-[11px] font-semibold uppercase tracking-[.1em] text-(--app-muted-foreground)">{t('cm.cases.show.quick_access')}</span>
            {chips.filter((chip) => chip.show).map((chip) => (
                <button key={chip.id} type="button" onClick={() => onOpen(chip.id)}
                    className="inline-flex items-center gap-2 rounded-control border border-(--app-border) bg-(--app-surface) px-3.5 py-2 text-[13px] font-medium text-(--app-muted-foreground) transition-colors hover:border-primary/40 hover:text-primary">
                    {chip.icon} {chip.label}
                </button>
            ))}
        </div>
    );
}
