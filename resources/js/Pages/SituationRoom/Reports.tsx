import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Card, FormField, Select, buttonClassName } from '@euisis/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useSituationLabels } from '@/Components/SituationRoom/useSituationLabels';
import type { SrCategory, SrFilters, SrLabels, SrQuery } from '@/types/situationRoom';

type Props = { labels: SrLabels; categories: SrCategory[]; filters: SrFilters; query: SrQuery; formats: Array<'csv' | 'xlsx' | 'pdf'> };

/**
 * Executive Reports: download a category exactly as the screen shows it —
 * the same scope, period and filters (carried in the link) — as CSV, Excel
 * or PDF, in English (Gregorian) or Amharic (Ethiopian calendar).
 */
export default function SituationRoomReports({ categories, query, formats }: Props) {
    const { sr, locale } = useSituationLabels();
    const [category, setCategory] = useState(categories[0]?.key ?? '');
    const [format, setFormat] = useState<string>(formats[0] ?? 'pdf');
    const [language, setLanguage] = useState<string>(locale === 'am' ? 'am' : 'en');
    const href = category ? route('situation-room.reports.export', { ...query, category, format, locale: language }) : '#';

    return (
        <AuthenticatedLayout header={<PageHeader title={sr('reports.title')} description={sr('reports.intro')} backHref={route('situation-room.index', query)} />}>
            <Head title={`${sr('reports.title')} · ${sr('title')}`} />

            <Card className="p-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <FormField id="sr-report-category" label={sr('reports.category')}>
                        <Select id="sr-report-category" value={category} onChange={(e) => setCategory(e.target.value)}>
                            {categories.map((c) => <option key={c.key} value={c.key}>{sr(`categories.${c.key}.title`)}</option>)}
                        </Select>
                    </FormField>
                    <FormField id="sr-report-format" label={sr('reports.format')}>
                        <Select id="sr-report-format" value={format} onChange={(e) => setFormat(e.target.value)}>
                            {formats.map((f) => <option key={f} value={f}>{sr(`reports.formats.${f}`)}</option>)}
                        </Select>
                    </FormField>
                    <FormField id="sr-report-language" label={sr('reports.language')}>
                        <Select id="sr-report-language" value={language} onChange={(e) => setLanguage(e.target.value)}>
                            {['en', 'am'].map((l) => <option key={l} value={l}>{sr(`reports.languages.${l}`)}</option>)}
                        </Select>
                    </FormField>
                </div>
                <div className="mt-4">
                    {/* A file download, not an Inertia visit. */}
                    <a href={href} className={buttonClassName({ variant: 'primary' })} aria-disabled={!category}>{sr('reports.download')}</a>
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
