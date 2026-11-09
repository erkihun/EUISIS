import PublicLayout from '@/court/public/PublicLayout';
import CourtManualContent from '@/court/public/CourtManualContent';
import { CourtPublicContainer, CourtPublicPageHeader, courtPublicCardClass } from '@/court/public/CourtPublicPage';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { PublicUserManualProps } from '@/court/types/public';

/**
 * The user manual written in the landing-page manager (UserManualController),
 * in the request's language, linked from the home page navigation.
 */
export default function PublicUserManual({ title, content, publicSite }: PublicUserManualProps) {
    const { t } = usePublicText();

    return (
        <PublicLayout title={title} publicSite={publicSite}>
            <CourtPublicPageHeader
                title={title}
                eyebrow={t('cm.home.nav.user_manual')}
                breadcrumbs={[{ label: t('cm.home.nav.user_manual') }]}
            />

            <CourtPublicContainer className="pb-12 sm:pb-20">
                <article className={`${courtPublicCardClass} print:mt-0 print:border-0 print:shadow-none`}>
                    <CourtManualContent html={content} />
                </article>
            </CourtPublicContainer>
        </PublicLayout>
    );
}
