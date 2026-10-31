import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import PublicLayout from '@/court/public/PublicLayout';
import { CourtPublicContainer, CourtPublicPageHeader, courtPublicCardClass } from '@/court/public/CourtPublicPage';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicTermsProps } from '@/court/types/public';

/** The published terms and conditions (TermsDisplayController): title, publication date, text. */
export default function PublicTerms({ term, publicSite }: PublicTermsProps) {
    const { t } = usePublicText();

    return (
        <PublicLayout title={term.title} publicSite={publicSite}>
            <CourtPublicPageHeader
                title={term.title}
                breadcrumbs={[{ label: t('cm.home.footer.terms_of_service') }]}
                meta={term.published_at ? <LocalizedDateDisplay value={term.published_at} withTime /> : null}
            />

            <CourtPublicContainer narrow className="pb-12 sm:pb-20">
                <article className={courtPublicCardClass}>
                    <RichTextViewer html={term.body} className="text-slate-800 dark:text-slate-200" />
                </article>
            </CourtPublicContainer>
        </PublicLayout>
    );
}
