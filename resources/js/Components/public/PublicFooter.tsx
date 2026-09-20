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
    const cls = 'text-sm text-gray-600 hover:text-gray-900 hover:underline dark:text-slate-400 dark:hover:text-white';

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

    const heading = 'mb-3 text-xs font-semibold text-gray-900 dark:text-slate-100';

    return (
        <footer className="border-t border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
                <div className="sm:col-span-2 lg:col-span-1">
                    <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{organization}</p>
                    {description && (
                        <p className="mt-2 max-w-xs text-sm leading-relaxed text-gray-600 dark:text-slate-400">{description}</p>
                    )}
                </div>

                {navigation.length > 0 && (
                    <nav aria-label={t('publicSite.footerNavigation')}>
                        <p className={heading}>{t('publicSite.footerNavigation')}</p>
                        <ul className="space-y-2">
                            {navigation.map((link) => (
                                <li key={link.id}><FooterLink link={link} label={pick(link, 'label')} /></li>
                            ))}
                        </ul>
                    </nav>
                )}

                {usefulLinks.length > 0 && (
                    <nav aria-label={t('publicSite.footerLinks')}>
                        <p className={heading}>{t('publicSite.footerLinks')}</p>
                        <ul className="space-y-2">
                            {usefulLinks.map((link) => (
                                <li key={link.id}><FooterLink link={link} label={pick(link, 'label')} /></li>
                            ))}
                        </ul>
                    </nav>
                )}

                {(email || phone) && (
                    <div>
                        <p className={heading}>{t('publicSite.footerContact')}</p>
                        <ul className="space-y-2 text-sm text-gray-600 dark:text-slate-400">
                            {email && <li><a href={`mailto:${email}`} className="hover:text-gray-900 hover:underline dark:hover:text-white">{email}</a></li>}
                            {phone && <li><a href={`tel:${phone.replace(/\s+/g, '')}`} className="hover:text-gray-900 hover:underline dark:hover:text-white">{phone}</a></li>}
                        </ul>
                    </div>
                )}
            </div>

            <div className="border-t border-gray-100 dark:border-slate-800">
                <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-4 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8 dark:text-slate-400">
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
