import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { Card } from '@euisis/ui';

/** Core › the card as it will print (ADR 0010), drawn by the renderer the print sheet uses. */
export default function CardPreview({ card, svg_url, back_url }: { card: { id: string; card_number: string; status: string }; svg_url: string; back_url: string }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={<PageHeader title={t('idCards.previewBeforePrint')} description={card.card_number} backHref={back_url} />}>
            <Head title={t('idCards.previewBeforePrint')} />
            <Card className="p-5">
                <div className="mb-4"><CardStatusBadge status={card.status} /></div>
                <img src={svg_url} alt={card.card_number} className="w-full max-w-2xl rounded-lg border border-(--app-border)" />
                <p className="mt-3 text-sm text-(--app-muted-foreground)">{t('core.cardProduction.previewNote')}</p>
            </Card>
        </AuthenticatedLayout>
    );
}
