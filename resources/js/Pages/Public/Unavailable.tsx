import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { PublicContainer, publicButtonPrimary, publicHeadingClass } from '@/Components/public/PublicPage';
import { useBilingual } from '@/Components/public/bilingual';
import { useLocale } from '@/hooks/useLocale';

/**
 * Shown on content pages while the public site is switched off in Public Site
 * Management. ID verification is never switched off with it, so the page says
 * so and links there.
 */
export default function Unavailable(props: { notice_en: string; notice_am: string }) {
    const pick = useBilingual();
    const { t } = useLocale();
    const notice = pick(props, 'notice') || t('publicSite.unavailableDefault');

    return (
        <PublicLayout title={t('publicSite.unavailableTitle')} noindex>
            <PublicContainer narrow className="py-16 text-center sm:py-24">
                <h1 className={publicHeadingClass}>{t('publicSite.unavailableTitle')}</h1>
                <p className="mx-auto mt-3 max-w-xl whitespace-pre-line text-base text-gray-600 dark:text-slate-400">{notice}</p>
                <p className="mt-6 text-sm text-gray-500 dark:text-slate-400">{t('publicSite.unavailableVerifyNote')}</p>
                <Link href={route('public.verify')} className={`${publicButtonPrimary} mt-4`}>
                    {t('nav.verifyIdCard')}
                </Link>
            </PublicContainer>
        </PublicLayout>
    );
}
