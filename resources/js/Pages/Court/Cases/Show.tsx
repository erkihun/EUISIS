import { Head, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import type { CaseDetailProps, CaseSection } from '@/court/types/caseDetail';
import { AuditSection, TimelineSection } from './Show/AuditSection';
import DetailsSection from './Show/DetailsSection';
import FilesSection from './Show/FilesSection';
import HeaderCard, { type ReviewDecision } from './Show/HeaderCard';
import HearingsSection from './Show/HearingsSection';
import { InspectionFindingsSection, InspectionRequestsSection } from './Show/InspectionSections';
import { LetterComposeSection, LettersSection } from './Show/LettersSection';
import MessagesSection from './Show/MessagesSection';
import PartiesSection, { MergedBanner } from './Show/PartiesSection';
import { RespondentResponsesSection, ResponseRepliesSection } from './Show/ResponsesSection';
import ReviewDialog from './Show/ReviewDialog';
import SectionNav, { QuickAccessBar, useSectionItems } from './Show/SectionNav';
import StatusPanel from './Show/StatusPanel';
import SummarySection from './Show/SummarySection';
import { useCaseText } from './Show/useCaseText';

const SECTIONS: CaseSection[] = [
    'case-summary', 'case-details', 'timeline', 'letters', 'letters-compose', 'audits', 'inspection-requests', 'inspection-findings',
    'uploaded-files', 'hearings', 'messages', 'respondent-responses', 'response-of-response', 'parties',
];
const isSection = (value: string): value is CaseSection => (SECTIONS as string[]).includes(value);
const sectionFromHash = (): CaseSection | null => {
    const hash = typeof window === 'undefined' ? '' : window.location.hash.replace(/^#/, '');
    return isSection(hash) ? hash : null;
};

/**
 * The case detail page: header, status panel, section navigation and one open
 * section at a time (the URL hash names it, as on the Blade page) — summary,
 * details, letters, timeline, audits, inspections, files, hearings, messages,
 * respondent responses and the applicant's replies.
 */
export default function CaseShow(props: CaseDetailProps) {
    const { case: kase, can, caseLocked, statuses, euisis, euisisRecord } = props;
    const { t } = useCaseText();
    const title = t('cm.cases.case_details');
    const { main, quick } = useSectionItems(can);
    const allowed = useMemo(() => new Set<CaseSection>([...main.map((item) => item.id), ...quick.map((item) => item.id), ...(can.writeLetter ? ['letters-compose' as CaseSection] : [])]), [main, quick, can.writeLetter]);

    const letterErrors = Boolean(props.errors && (props.errors.template_id || props.errors.recipient_name || props.errors.body));
    const [active, setActive] = useState<CaseSection>(() => {
        const fromHash = sectionFromHash();
        if (fromHash && allowed.has(fromHash)) return fromHash;
        return letterErrors && can.writeLetter ? 'letters-compose' : 'case-summary';
    });
    const [reviewDecision, setReviewDecision] = useState<Exclude<ReviewDecision, 'accept'> | null>(null);
    const [selectedFindingId, setSelectedFindingId] = useState<number | null>(null);

    const openSection = useCallback((section: CaseSection) => {
        if (!allowed.has(section)) return;
        setActive(section);
        if (typeof window !== 'undefined' && window.location.hash !== `#${section}`) {
            window.history.pushState(null, '', `#${section}`);
        }
        requestAnimationFrame(() => document.getElementById(section)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }, [allowed]);

    useEffect(() => {
        const sync = () => {
            const section = sectionFromHash();
            if (section && allowed.has(section)) setActive(section);
        };
        window.addEventListener('hashchange', sync);
        window.addEventListener('popstate', sync);
        return () => {
            window.removeEventListener('hashchange', sync);
            window.removeEventListener('popstate', sync);
        };
    }, [allowed]);

    const review = (decision: ReviewDecision) => {
        if (decision === 'accept') {
            router.patch(route('court.cases.review.update', kase.id), { decision: 'accept', note: '' }, { preserveScroll: true });
            return;
        }
        setReviewDecision(decision);
    };

    const toggleFinding = (id: number) => {
        setActive('inspection-findings');
        setSelectedFindingId((current) => (current === id ? null : id));
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} />}>
            <Head title={`${title} · ${kase.case_number}`} />

            <div className="space-y-5">
                <HeaderCard kase={kase} can={can} caseLocked={caseLocked} onOpenSection={openSection} onReview={review} />
                {props.parties.mergedInto && <MergedBanner mergedInto={props.parties.mergedInto} />}
                <QuickAccessBar can={can} onOpen={openSection} />

                {can.review && <ReviewDialog caseId={kase.id} decision={reviewDecision} initialNote={kase.review_note ?? ''} onClose={() => setReviewDecision(null)} />}

                {can.editStatus && <StatusPanel kase={kase} statuses={statuses} />}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    <div className="lg:col-span-3">
                        <SectionNav active={active} can={can} onOpen={openSection} />
                    </div>
                    <div className="space-y-6 lg:col-span-9">
                        {active === 'case-summary' && <SummarySection kase={kase} hearings={props.hearings} euisis={euisis} euisisRecord={euisisRecord} />}
                        {active === 'case-details' && <DetailsSection kase={kase} docs={props.docs} witnesses={props.witnesses} termsItems={props.termsItems} canEdit={can.editStatus} caseLocked={caseLocked} />}
                        {active === 'parties' && <PartiesSection caseId={kase.id} caseNumber={kase.case_number} parties={props.parties} />}
                        {active === 'timeline' && <TimelineSection timeline={props.timeline} />}
                        {active === 'audits' && <AuditSection audits={props.audits} />}
                        {active === 'inspection-requests' && can.manageInspectionRequests && <InspectionRequestsSection requests={props.inspectionRequests} />}
                        {active === 'inspection-findings' && can.manageInspectionFindings && (
                            <InspectionFindingsSection findings={props.inspectionFindings} can={can} selectedId={selectedFindingId} onToggle={toggleFinding} />
                        )}
                        {active === 'letters' && <LettersSection letters={props.letters} canView={can.viewLetters} />}
                        {active === 'letters-compose' && can.writeLetter && (
                            <LetterComposeSection caseNumber={kase.case_number} templates={props.letterTemplates} nextReference={props.nextLetterReference} onBack={() => openSection('letters')} />
                        )}
                        {active === 'hearings' && <HearingsSection caseId={kase.id} hearings={props.hearings} can={can} caseLocked={caseLocked} />}
                        {active === 'respondent-responses' && <RespondentResponsesSection caseNumber={kase.case_number} responses={props.respondentResponses} canReview={can.review} />}
                        {active === 'response-of-response' && can.manageResponseReplies && <ResponseRepliesSection caseNumber={kase.case_number} replies={props.applicantResponseReplies} />}
                        {active === 'messages' && (
                            <MessagesSection caseId={kase.id} caseNumber={kase.case_number} messages={props.messages} canCreate={can.createMessage} caseLocked={caseLocked} currentUserInitial={props.currentUserInitial} />
                        )}
                        {active === 'uploaded-files' && <FilesSection caseId={kase.id} files={props.files} can={can} caseLocked={caseLocked} />}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
