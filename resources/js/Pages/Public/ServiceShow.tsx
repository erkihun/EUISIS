import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    PublicContainer,
    PublicPageHeader,
    RichText,
    publicButtonOnDark,
    publicButtonPrimary,
    publicButtonSecondary,
    publicHeadingClass,
    publicIconTileClass,
} from '@/Components/public/PublicPage';
import { ServiceIcon, type ServiceSummary } from '@/Components/public/PublicItems';
import { useBilingual } from '@/Components/public/bilingual';
import { useLocale } from '@/hooks/useLocale';

type Service = ServiceSummary & {
    description_html_en: string;
    description_html_am: string;
    eligibility_html_en: string;
    eligibility_html_am: string;
    requirements_html_en: string;
    requirements_html_am: string;
    steps_html_en: string;
    steps_html_am: string;
    contact_info: string | null;
    action_href: string | null;
    action_external: boolean;
};

/**
 * A service's detail page. Each block — description, eligibility,
 * requirements, steps, contact — appears only if the administrator actually
 * wrote it. Nothing is filled in with generic instructions.
 */
export default function ServiceShow({ service, preview = false }: { service: Service; preview?: boolean }) {
    const pick = useBilingual();
    const { t } = useLocale();
    const name = pick(service, 'name');

    const blocks = [
        { key: 'description', label: t('publicSite.description'), html: pick(service, 'description_html') },
        { key: 'eligibility', label: t('publicSite.eligibility'), html: pick(service, 'eligibility_html') },
        { key: 'requirements', label: t('publicSite.requirements'), html: pick(service, 'requirements_html') },
        { key: 'steps', label: t('publicSite.steps'), html: pick(service, 'steps_html') },
    ].filter((block) => block.html);

    const actionButton = (className: string) =>
        service.action_href === null ? null : service.action_external ? (
            <a href={service.action_href} className={className} rel="noopener noreferrer">
                {t('publicSite.accessService')}
            </a>
        ) : (
            <Link href={service.action_href} className={className}>
                {t('publicSite.accessService')}
            </Link>
        );

    return (
        <PublicLayout title={name} description={pick(service, 'short_description')} noindex={preview}>
            {preview && (
                <div role="status" className="border-b border-amber-300 bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    {t('publicSite.previewBanner')}
                </div>
            )}

            <PublicPageHeader
                title={name}
                description={pick(service, 'short_description')}
                breadcrumbs={[{ label: t('nav.services'), href: route('public.services') }, { label: name }]}
                actions={actionButton(publicButtonOnDark)}
            />

            <div className="bg-white py-16 sm:py-20 dark:bg-slate-950">
                <PublicContainer narrow className="space-y-10">
                    <span className={publicIconTileClass}>
                        <ServiceIcon name={service.icon} className="h-5 w-5" />
                    </span>

                    {blocks.map((block) => (
                        <section key={block.key} aria-labelledby={`${block.key}-heading`}>
                            <h2 id={`${block.key}-heading`} className={publicHeadingClass}>{block.label}</h2>
                            <RichText html={block.html} className="mt-2" />
                        </section>
                    ))}

                    {service.contact_info && (
                        <section aria-labelledby="contact-heading">
                            <h2 id="contact-heading" className={publicHeadingClass}>{t('publicSite.contact')}</h2>
                            <p className="mt-2 text-gray-700 [overflow-wrap:anywhere] dark:text-slate-300">{service.contact_info}</p>
                        </section>
                    )}

                    <div className="flex flex-wrap gap-3 border-t border-gray-200 pt-8 dark:border-slate-800">
                        {actionButton(publicButtonPrimary)}
                        <Link href={route('public.services')} className={publicButtonSecondary}>
                            {t('publicSite.backToServices')}
                        </Link>
                    </div>
                </PublicContainer>
            </div>
        </PublicLayout>
    );
}
