import { Head } from '@inertiajs/react';
import { useState, type KeyboardEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName, cx } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';
import type { LandingIndexProps, LandingTab } from '@/court/types/public';
import { FaqsPanel, ResourcesPanel, ServicesPanel, SlidesPanel, TimelinePanel } from './Partials/ContentPanels';
import { FooterPanel, ManualPanel, MetricsPanel, SectionsPanel } from './Partials/SettingsPanels';
import { AboutPanel, MembersPanel } from './Partials/CourtPanels';

const TABS: LandingTab[] = ['slides', 'about', 'members', 'services', 'metrics', 'timeline', 'resources', 'manual', 'faqs', 'footer', 'sections'];

/**
 * The landing-page manager (LandingPageController): every section of the
 * public home page in one workspace, in English and Amharic — hero slides, the
 * court and its members, service cards, statistics labels, the process
 * timeline, resources, the user manual, FAQs, the footer and contact details,
 * and section visibility. Each tab saves through the controller's own routes;
 * the tab stays open after a save.
 */
export default function LandingIndex(props: LandingIndexProps) {
    const { t } = usePublicText();
    const [tab, setTab] = useState<LandingTab>(TABS.includes(props.initialTab) ? props.initialTab : 'slides');
    const title = t('cm.admin_landing.page_title');

    const onTabKey = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : null;
        if (next === null) return;
        event.preventDefault();
        const target = TABS[(next + TABS.length) % TABS.length];
        setTab(target);
        document.getElementById(`landing-tab-${target}`)?.focus();
    };

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={title}
                    description={t('cm.admin_landing.intro')}
                    actions={(
                        <a href={props.homeUrl} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline' })}>
                            {t('cm.admin_landing.preview')}
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5h5m0 0v5m0-5L10 14M5 7v12h12v-5" />
                            </svg>
                        </a>
                    )}
                />
            )}
        >
            <Head title={title} />

            <div className="space-y-6">
                <div className="overflow-hidden rounded-card bg-linear-to-r from-slate-950 via-blue-950 to-slate-900 p-5 text-white">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-blue-300">{t('cm.admin_landing.eyebrow')}</p>
                    <p className="mt-1 text-lg font-semibold">{t('cm.admin_landing.heading')}</p>
                </div>

                <div role="tablist" aria-label={title} className="flex gap-1 overflow-x-auto border-b border-(--app-border)">
                    {TABS.map((key, index) => {
                        const active = key === tab;
                        return (
                            <button
                                key={key}
                                id={`landing-tab-${key}`}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                aria-controls={`landing-panel-${key}`}
                                tabIndex={active ? 0 : -1}
                                onClick={() => setTab(key)}
                                onKeyDown={(event) => onTabKey(event, index)}
                                className={cx(
                                    '-mb-px inline-flex items-center whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                    active ? 'border-primary font-medium text-primary' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)',
                                )}
                            >
                                {t(`cm.admin_landing.tabs.${key}`)}
                            </button>
                        );
                    })}
                </div>

                {/* Every panel stays mounted (as the Blade tabs did), so unsaved edits survive a tab switch. */}
                {TABS.map((key) => (
                    <div key={key} id={`landing-panel-${key}`} role="tabpanel" aria-labelledby={`landing-tab-${key}`} hidden={key !== tab} tabIndex={0} className="focus-visible:outline-hidden">
                        {key === 'slides' && <SlidesPanel slides={props.slides} />}
                        {key === 'about' && <AboutPanel about={props.about} />}
                        {key === 'members' && <MembersPanel members={props.members} />}
                        {key === 'metrics' && <MetricsPanel stats={props.stats} metrics={props.metrics} metricDefaults={props.metricDefaults} />}
                        {key === 'timeline' && <TimelinePanel steps={props.steps} />}
                        {key === 'services' && <ServicesPanel services={props.services} />}
                        {key === 'resources' && <ResourcesPanel resources={props.resources} />}
                        {key === 'manual' && <ManualPanel userManual={props.userManual} />}
                        {key === 'faqs' && <FaqsPanel faqs={props.faqs} />}
                        {key === 'footer' && <FooterPanel footer={props.footer} />}
                        {key === 'sections' && <SectionsPanel sections={props.sections} />}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
