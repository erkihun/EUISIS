import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AIStatusBadge from '@/Components/intelligence/AIStatusBadge';
import AreaIcon from '@/Components/intelligence/AreaIcon';
import { Card } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { IntelligenceFrame, IntelligenceSection } from '@/types/intelligence';

/**
 * The Intelligence launcher: one card per permitted area, grouped by section.
 * Cards carry no figures until an area has authoritative data behind it
 * (docs/intelligence-architecture.md); a card is a door, not a dashboard.
 */
export default function Index({ areas, sections, ai }: PageProps<IntelligenceFrame>) {
    const { t } = useLocale();

    const visibleSections = sections.filter((section) => areas.some((area) => area.section === section));

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('intelligence.title')}
                    description={t('intelligence.description')}
                    actions={<AIStatusBadge enabled={ai.enabled} provider={ai.provider} />}
                />
            }
        >
            <Head title={t('intelligence.title')} />

            <div className="space-y-8">
                {visibleSections.map((section: IntelligenceSection) => (
                    <section key={section} aria-labelledby={`intelligence-section-${section}`}>
                        <h2 id={`intelligence-section-${section}`} className="text-section-title text-(--app-foreground)">
                            {t(`intelligence.sections.${section}.title`)}
                        </h2>
                        <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{t(`intelligence.sections.${section}.description`)}</p>
                        <ul className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {areas.filter((area) => area.section === section).map((area) => (
                                <li key={area.key}>
                                    <Link href={area.href} className="group block h-full focus-visible:outline-hidden">
                                        <Card className="flex h-full items-start gap-3 p-4 transition-colors group-hover:border-primary group-focus-visible:border-primary">
                                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-(--app-surface-muted) text-primary">
                                                <AreaIcon name={area.icon} className="h-5 w-5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block font-semibold text-(--app-foreground)">{t(`intelligence.areas.${area.key}.title`)}</span>
                                                <span className="mt-0.5 block text-sm text-(--app-muted-foreground)">{t(`intelligence.areas.${area.key}.description`)}</span>
                                            </span>
                                        </Card>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
