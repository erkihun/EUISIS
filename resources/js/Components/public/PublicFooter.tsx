import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { useBilingual } from './bilingual';
import type { PublicLink } from './PublicHeader';
import type { PageProps } from '@/types';

type SharedProps = PageProps & {
    publicSite?: { navigation: PublicLink[]; footerLinks: Record<string, PublicLink[]> };
};

function FooterLink({ link, label }: { link: PublicLink; label: string }) {
    const cls = 'inline-flex min-h-11 max-w-full items-center py-2 text-sm text-white/75 [overflow-wrap:anywhere] hover:text-white hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';

    return link.external ? (
        <a href={link.href} className={cls} rel="noopener noreferrer">{label}</a>
    ) : (
        <Link href={link.href} className={cls}>{label}</Link>
    );
}

/**
 * Public footer. Every column renders only when it has real content: an
 * institution with no configured legal links shows no empty "Legal" heading,
 * and nothing here — social accounts, addresses — is ever filled with
 * placeholders.
 */
export default function PublicFooter() {
    const { publicSite } = usePage<SharedProps>().props;
    const { t } = useLocale();
    const { getString } = useSystemSettings();
    const pick = useBilingual();

    const settings = {
        description_en: getString('public_site.footer_description_en'),
        description_am: getString('public_site.footer_description_am'),
        copyright_en: getString('public_site.copyright_text_en'),
        copyright_am: getString('public_site.copyright_text_am'),
    };

    const organization = getString('general.organization_name', getString('app.name'));
    const description = pick(settings, 'description');
    const copyright = pick(settings, 'copyright');
    const email = getString('general.support_email');
    const phone = getString('general.support_phone');

    const navigation = publicSite?.navigation ?? [];
    const usefulLinks = publicSite?.footerLinks?.useful ?? [];
    const legalLinks = publicSite?.footerLinks?.legal ?? [];

    const heading = 'mb-3 text-sm font-semibold text-white';

    return (
        <footer className="bg-[color:var(--color-primary-900)] pb-[env(safe-area-inset-bottom)] text-white [overflow-wrap:anywhere] dark:bg-[color:var(--color-primary-950)]">
            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-4 py-8 sm:grid-cols-2 sm:gap-8 sm:px-6 sm:py-12 lg:grid-cols-4 lg:px-8 [&>*]:min-w-0">
                <div className="sm:col-span-2 lg:col-span-1">
                    <p className="text-base font-bold text-white">{organization}</p>
                    {description && (
                        <p className="mt-2 max-w-xs text-sm leading-relaxed text-white/75">{description}</p>
                    )}
                </div>

                {navigation.length > 0 && (
                    <nav aria-label={t('publicSite.footerNavigation')}>
                        <p className={heading}>{t('publicSite.footerNavigation')}</p>
                        <ul>
                            {navigation.map((link) => (
                                <li key={link.id}><FooterLink link={link} label={pick(link, 'label')} /></li>
                            ))}
                        </ul>
                    </nav>
                )}

                {usefulLinks.length > 0 && (
                    <nav aria-label={t('publicSite.footerLinks')}>
                        <p className={heading}>{t('publicSite.footerLinks')}</p>
                        <ul>
                            {usefulLinks.map((link) => (
                                <li key={link.id}><FooterLink link={link} label={pick(link, 'label')} /></li>
                            ))}
                        </ul>
                    </nav>
                )}

                {(email || phone) && (
                    <div>
                        <p className={heading}>{t('publicSite.footerContact')}</p>
                        <ul className="space-y-2 text-sm text-white/75">
                            {email && <li><a href={`mailto:${email}`} className="inline-flex min-h-11 max-w-full items-center py-2 hover:text-white hover:underline">{email}</a></li>}
                            {phone && <li><a href={`tel:${phone.replace(/\s+/g, '')}`} className="inline-flex min-h-11 max-w-full items-center py-2 hover:text-white hover:underline">{phone}</a></li>}
                        </ul>
                    </div>
                )}
            </div>

            <div className="border-t border-white/15">
                <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-4 text-xs text-white/70 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>{copyright || `© ${new Date().getFullYear()} ${organization}`}</p>
                    {legalLinks.length > 0 && (
                        <nav aria-label={t('publicSite.footerLegal')}>
                            <ul className="flex flex-wrap gap-x-4 gap-y-1">
                                {legalLinks.map((link) => (
                                    <li key={link.id}><FooterLink link={link} label={pick(link, 'label')} /></li>
                                ))}
                            </ul>
                        </nav>
                    )}
                </div>
            </div>
        </footer>
    );
}
