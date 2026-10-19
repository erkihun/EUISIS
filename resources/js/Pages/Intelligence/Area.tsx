import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AIStatusBadge from '@/Components/intelligence/AIStatusBadge';
import { Alert } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import type { IntelligenceAreaInfo, IntelligenceFrame } from '@/types/intelligence';

/**
 * The generic area frame. An area whose analysis is not built yet says so
 * instead of showing a placeholder figure: an honest empty state beats a fake
 * green (docs/intelligence-architecture.md, D-5).
 */
export default function Area({ area, ai }: PageProps<IntelligenceFrame & { area: IntelligenceAreaInfo }>) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t(`intelligence.areas.${area.key}.title`)}
                    description={t(`intelligence.areas.${area.key}.description`)}
                    backHref={route('intelligence.index')}
                    actions={<AIStatusBadge enabled={ai.enabled} provider={ai.provider} />}
                />
            }
        >
            <Head title={t(`intelligence.areas.${area.key}.title`)} />

            <Alert tone="info" title={t('intelligence.notReady.title')}>
                {t('intelligence.notReady.body')}
            </Alert>
        </AuthenticatedLayout>
    );
}
