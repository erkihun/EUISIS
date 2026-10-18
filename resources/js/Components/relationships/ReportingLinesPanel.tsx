import { useLocale } from '@/hooks/useLocale';
import type { RelationshipRow } from './RelationshipPanel';

type Props = {
    rows: RelationshipRow[];
};

export default function ReportingLinesPanel({ rows }: Props) {
    const { t, locale } = useLocale();

    return (
        <section className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
            <h3 className="font-semibold text-(--app-foreground)">{t('relationships.reportingLines')}</h3>
            <div className="mt-4 space-y-2">
                {rows.length === 0 ? (
                    <p className="text-sm text-(--app-muted-foreground)">{t('relationships.noRelationships')}</p>
                ) : rows.map((row) => (
                    <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-(--app-border) px-3 py-2 text-sm">
                        <span className="font-medium text-(--app-foreground)">
                            {t(`relationships.types.${row.relationship_type}`)}
                        </span>
                        <span className="text-(--app-muted-foreground)">
                            {locale === 'am'
                                ? (row.target?.name_am ?? row.target_id)
                                : (row.target?.name_en ?? row.target_id)}
                        </span>
                    </div>
                ))}
            </div>
        </section>
    );
}
